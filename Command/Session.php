<?php

namespace MADIR\Command;

class Session {

  public static $sessions = [];
  public static $current;

  public static function getCurrent() {
    return self::$sessions[self::$current];
  }

  public $commands = [];
  public $selected;
  protected $cwd;
  protected $pwd;
  protected $history = 1;
  protected $env;
  protected $vars = [];

  public function __construct() {
    self::$sessions[] = $this;
    self::$current = count(self::$sessions) - 1;
    $this->cwd = \SPTK\Config::getHome();
    $this->pwd = $this->cwd;
    $this->env = getenv();
    $this->commandLine();
    $this->selected = 0;
  }

  private function commandLine() {
    array_splice($this->commands, -1, 0, [new Command(false, $this, false)]);
  }

  public function runCommand($command) {
    $this->history = 1;
    $internal = $this->handleInternal($command, $output);
    if ($internal === true && $output === true) {
      return;
    }
    array_splice($this->commands, -1, 0, [new Command($command, $this, $internal)]);
    if ($internal === true) {
      \MADIR\Screen\Controller::listCommands();
      $cmd = $this->currentCommand();
      $cmd->output($output);
      $cmd->end(0);
    }
  }

  public function getVisibleCommands() {
    $toSelected = array_slice($this->commands, 0, $this->selected + 1);
    return array_reverse($toSelected);
  }

  public function previousCommand() {
    $this->selected = max(0, $this->selected - 1);
  }

  public function nextCommand() {
    $this->selected = min(count($this->commands) - 1, $this->selected + 1);
  }

  public function currentCommand() {
    return $this->commands[$this->selected];
  }

  public function endCommand() {
    if ($this->selected === count($this->commands) - 2) {
      $this->nextCommand();
    }
  }

  public function cwd() {
    return $this->cwd;
  }

  public function history($step) {
    $commandLine = $this->currentCommand();
    if ($this->history === 1) {
      $commandLine->saveValue();
    }
    $n = count($this->commands);
    $this->history += $step;
    if ($this->history > $n) {
      $this->history = $n;
    }
    if ($this->history < 1) {
      $this->history = 1;
    }
    $command = $this->commands[$n - $this->history];
    if ($this->history === 1) {
      $commandLine->restoreValue();
    } else {
      $commandLine->setValue($command->command['commandString']);
    }
  }

  public function getenv() {
    return $this->env;
  }

  private function handleInternal($command, &$output) {
    $commandString = trim($command['commandString']);
    if (preg_match('/^cd( |$)/', $commandString)) {
      $output = $this->changeDir($commandString);
      return true;
    }
    if (preg_match('/^set( |$)/', $commandString)) {
      $output = $this->set($commandString);
      return true;
    }
    if (preg_match('/^export( |$)/', $commandString)) {
      $output = $this->export($commandString);
      return true;
    }
    // alias: define alias
    // exit: close session
    return false;
  }

  private function changeDir($command) {
    switch ($command) {
      case 'cd':
        return $this->cwd;
      case 'cd .':
        break;
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
        $this->cwd = \SPTK\Config::getHome();
        break;
      case 'cd -':
        list($this->cwd, $this->pwd) = [$this->pwd, $this->cwd];
        break;
      default:
        $path = substr($command, 2);
        $path = trim($path);
        if (substr($path, 0, 2) === './') {
          $path = $this->cwd . substr($path, 1);
        } else if (substr($path, 0, 1) !== '/') {
          $path = $this->cwd . '/' . $path;
        }
        $rpath = realpath($path);
        if ($rpath === false) {
          return "{$path}\nNo such file or directory!";
        } else {
          $this->pwd = $this->cwd;
          $this->cwd = $rpath;
        }
        break;
    }
    $cmd = end($this->commands);
    $cmd->refreshCommandLine();
    return true;
  }

  private function set($command) {
    if ($command === 'set') {
      $res = '';
      foreach ($this->vars as $key => $value) {
        $res .= "VAR[\"{$key}\"] = \"{$value}\"\n";
      }
      return $res;
    }
    $set = trim(substr($command, 3));
    $a = explode('=', $set, 2);
    $name = $a[0];
    $name = $this->resolveQuotation($name);
    if ($name === false) {
      return "Syntax error!";
    }
    if (isset($a[1])) {
      $value = $a[1];
      $value = $this->resolveQuotation($value);
      if ($value === false) {
        return "Syntax error!";
      }
    } else {
      if (isset($this->env[$name])) {
        unset($this->env[$name]);
        return "unset ENV[\"{$name}\"]";
      }
      if (isset($this->vars[$name])) {
        unset($this->vars[$name]);
        return "unset VAR[\"{$name}\"]";
      }
      return "Variable \"{$name}\" not found.";
    }
    $warning = '';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
      $warning .= "\e[0;33mWARNING: strange variable name!\e[0m\n";
    }
    if (isset($this->env[$name])) {
      $this->env[$name] = $value;
      return "{$warning}ENV[\"{$name}\"] = \"{$value}\"";
    } else {
      $this->vars[$name] = $value;
      return "{$warning}VAR[\"{$name}\"] = \"{$value}\"";
    }
  }

  private function export($command) {
    if ($command === 'export') {
      $res = '';
      foreach ($this->env as $key => $value) {
        $res .= "ENV[\"{$key}\"] = \"{$value}\"\n";
      }
      return $res;
    }
    $export = trim(substr($command, 6));
    $a = explode('=', $export, 2);
    $name = $a[0];
    $name = $this->resolveQuotation($name);
    if ($name === false) {
      return "Syntax error!";
    }
    if (isset($a[1])) {
      $value = $a[1];
      $value = $this->resolveQuotation($value);
      if ($value === false) {
        return "Syntax error!";
      }
    } else {
      if (isset($this->env[$name])) {
        return "\"{$name}\" is already exported!";
      }
      if (isset($this->vars[$name])) {
        $this->env[$name] = $this->vars[$name];
        unset($this->vars[$name]);
        return "[\"{$name}\"] has been exported.";
      }
      return "Variable \"{$name}\" not found!";
    }
    $warning = '';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
      $warning .= "\e[0;33mWARNING: strange variable name!\e[0m\n";
    }
    $this->env[$name] = $value;
    return "{$warning}ENV[\"{$name}\"] = \"{$value}\"";
  }

  private function resolveQuotation($name) {
    if (substr($name, 0, 1) === '"') {
      $name = substr($name, 1);
      if (substr($name, -1) === '"') {
        $name = substr($name, 0, -1);
      } else {
        return false;
      }
    }
    return $name;
  }

}
