<?php

namespace MADIR\App;

use MADIR\Command\CommandParser;
use MADIR\Command\InternalCommands;
use SPTK\Core\AppData;

/** App-side command state for parser variables and portable internal commands. */
class CommandSession {

  use InternalCommands {
    changeDir as private traitChangeDir;
  }

  protected string $cwd;
  protected string $pwd;
  protected array $env;
  public array $vars = [];
  private $git = false;
  private static array $alias = [
    'ls' => 'ls --color=auto'
  ];
  private static bool $aliasLoaded = false;
  private $runCommandStringsCallback = null;

  public function __construct() {
    $this->cwd = getcwd();
    $this->pwd = $this->cwd;
    $this->env = getenv();
    $this->env['PWD'] = $this->cwd;
  }

  public function parse(string $commandString): array {
    return (new CommandParser($this))->parse($commandString);
  }

  public function setRunCommandStringsCallback(?callable $callback): void {
    $this->runCommandStringsCallback = $callback;
  }

  public function handleInternal(array $command, mixed &$output): bool {
    $commandString = trim($command['commandString']);
    if (preg_match('/^open( |$)/', $commandString)) {
      $output = $this->openCommand($command);
      return true;
    }
    if (preg_match('/^cd( |$)/', $commandString)) {
      $output = $this->changeDir($commandString);
      return true;
    }
    if (preg_match('/^set( |$)/', $commandString)) {
      $output = $this->set($commandString);
      return true;
    }
    if (preg_match('/^alias( |$)/', $commandString)) {
      $output = $this->alias($commandString);
      return true;
    }
    if (preg_match('/^help( |$)/', $commandString)) {
      $output = $this->help($commandString);
      return true;
    }
    if (preg_match('/^\$[A-Za-z][A-Za-z0-9_-]*=.*$/', $commandString)) {
      $output = $this->variable($commandString);
      return true;
    }
    if ($commandString === '%MeasureTerminalSize%') {
      $output = '';
      return true;
    }
    return false;
  }

  public function cwd(): string {
    return $this->cwd;
  }

  public function getenv(): array {
    return $this->env;
  }

  public function getvar(string $name): string {
    $vname = substr($name, 1);
    if (isset($this->env[$vname])) {
      return $this->env[$vname];
    }
    if (isset($this->vars[$vname])) {
      return $this->vars[$vname];
    }
    return $name;
  }

  public function getVarList(): array {
    return array_keys($this->vars);
  }

  public function getEnvList(): array {
    return array_keys($this->env);
  }

  public function aliases(): array {
    return self::getAliasList();
  }

  public static function getAliasList(): array {
    self::loadAlias();
    return self::$alias;
  }

  public static function saveAlias(): bool {
    self::loadAlias();
    ksort(self::$alias, SORT_STRING);
    return AppData::saveJson('alias.json', self::$alias);
  }

  protected function setGit(): void {
    exec('git -C ' . escapeshellarg($this->cwd) . ' branch --show-current 2>&1', $output, $result);
    $this->git = $result === 0 && isset($output[0]) ? $output[0] : false;
  }

  private function runCommandStrings(array $commandStrings): void {
    if ($this->runCommandStringsCallback !== null) {
      call_user_func($this->runCommandStringsCallback, $commandStrings);
    }
  }

  private static function loadAlias(): void {
    if (self::$aliasLoaded) {
      return;
    }
    self::$aliasLoaded = true;
    $alias = AppData::loadJson('alias.json');
    foreach ($alias as $name => $value) {
      if (is_string($name) && is_string($value)) {
        self::$alias[$name] = $value;
      }
    }
  }

  protected function home(): string {
    return getenv('HOME') ?: getcwd();
  }

  private function changeDir(string $command): string|bool {
    switch ($command) {
      case 'cd':
        return $this->traitChangeDir($command);
      case 'cd .':
        return $this->cwd . "\n";
      case 'cd ..':
        $this->pwd = $this->cwd;
        $this->cwd = dirname($this->cwd);
        break;
      case 'cd /':
        $this->pwd = $this->cwd;
        $this->cwd = '/';
        break;
      case 'cd ~':
        $this->pwd = $this->cwd;
        $this->cwd = $this->home();
        break;
      case 'cd -':
        [$this->cwd, $this->pwd] = [$this->pwd, $this->cwd];
        break;
      default:
        $path = trim(substr($command, 2));
        if (substr($path, 0, 2) === './') {
          $path = $this->cwd . substr($path, 1);
        } else if (substr($path, 0, 2) === '~/') {
          $path = $this->home() . substr($path, 1);
        } else if (substr($path, 0, 1) !== '/') {
          $path = rtrim($this->cwd, '/') . '/' . $path;
        }
        $realPath = realpath($path);
        if ($realPath === false) {
          return "{$path}\nNo such file or directory!\n";
        }
        $this->pwd = $this->cwd;
        $this->cwd = $realPath;
        break;
    }
    $this->setGit();
    $this->env['OLDPWD'] = $this->pwd;
    $this->env['PWD'] = $this->cwd;
    return true;
  }

}
