<?php

namespace MADIR\Command;

class Session {

  use InternalCommands;

  public static $sessions = [];
  public static $current;
  public static $alias = [
    'ls' => 'ls --color=auto'
  ];

  public static function getCurrent(): Session {
    return self::$sessions[self::$current];
  }

  public static function getAliasList(): array {
    return self::$alias;
  }

  public static function selectSession(int $s, bool $relative = false): void {
    $next = $s;
    if ($relative) {
      $next = self::$current;
      if ($s < 0 && self::$current > 0) {
        $n = 0;
        for ($i = self::$current - 1; $i > 0; $i--) {
          if (isset(self::$sessions[$i])) {
            $n--;
          }
          if ($n <= $s) {
            break;
          }
        }
        $next = $i;
      }
      $m = max(array_keys(self::$sessions));
      if ($s > 0 && self::$current < $m) {
        $n = 0;
        for ($i = self::$current + 1; $i < $m; $i++) {
          if (isset(self::$sessions[$i])) {
            $n++;
          }
          if ($n >= $s) {
            break;
          }
        }
        $next = $i;
      }
    } else if (!isset(self::$sessions[$s])) {
      new Session($s);
      ksort(self::$sessions, SORT_NUMERIC);
    }
    if (isset(self::$sessions[$next])) {
      self::$current = $next;
    }
  }

  public static function selectSessionByName(string $name): void {
    foreach (self::$sessions as $id => $session) {
      if ($session->getName() === $name) {
        self::$current = $id;
        return;
      }
    }
    for ($i = 0; $i < 999; $i++) {
      if (!isset(self::$sessions[$i])) {
        $session = new Session($i);
        $session->setName($name);
        ksort(self::$sessions, SORT_NUMERIC);
        return;
      }
    }
  }

  public static function getSessionList(): string {
    $list = [];
    foreach (self::$sessions as $i => $session) {
      $current = ($i === self::$current ? '*' : ' ');
      $id = str_pad($current . $session->id(), 3, ' ', STR_PAD_LEFT);
      $name = $session->getName() ?? '';
      $list[] = "{$id} {$name}";
    };
    return implode("\n", $list);
  }

  public static function delete(int $id): string {
    if ($id === self::$current) {
      self::selectSession(1, true);
    }
    if ($id === self::$current) {
      self::selectSession(-1, true);
    }
    if ($id === self::$current) {
      return "You can't delete the last session.";
    }
    unset(self::$sessions[$id]);
    return self::getSessionList();
  }

  public $commands = [];
  public $groupCursor = [];
  public $selected;
  protected $cwd;
  protected $pwd;
  protected $history = 1;
  protected $env;
  public $vars = [];
  protected $groupRun = false;
  protected $id;
  protected $name;

  public function __construct($s) {
    self::$sessions[$s] = $this;
    self::$current = $s;
    $this->id = $s;
    $this->cwd = \SPTK\Config::getHome();
    $this->pwd = $this->cwd;
    $this->env = getenv();
    $this->commandLine();
    $this->selected = 0;
  }

  public function clear() {
    foreach ($this->commands as $group) {
      foreach ($group as $command) {
        if (!$command->isRunning()) {
          $cid = $command->getCid();
          if ($cid > 0) {
            $this->deleteCommand($cid);
          }
        }
      }
    }
  }

  public function id() {
    return $this->id;
  }

  public function setName($name) {
    $this->name = $name;
  }

  public function getName() {
    return $this->name;
  }

  public function getText() {
    if (!empty($this->name)) {
      return $this->name;
    }
    return $this->id;
  }

  private function commandLine() {
    array_splice($this->commands, -1, 0, [[new Command(false, $this, false, 1)]]);
  }

  public function runCommand($parsedCommand, $group = 1, $boxSize = 1) {
    $this->history = 1;
    $internal = $this->handleInternal($parsedCommand, $output);
    if ($internal === true && $output === true) {
      return true;
    }
    $command = new Command($parsedCommand, $this, $internal, $boxSize);
    if ($this->groupRun === false) {
      $this->groupRun = [];
    }
    $this->groupRun[] = $command;
    if (count($this->groupRun) === $group) {
      array_splice($this->commands, -1, 0, [$this->groupRun]);
      $this->groupCursor[] = 0;
      $this->groupRun = false;
    }
    if ($internal === true) {
      \MADIR\Screen\Controller::listCommands();
      $command->output($output);
      $command->end(0);
    }
    return $command->getCid();
  }

  public function getVisibleCommands() {
    $toSelected = array_slice($this->commands, 0, $this->selected + 1);
    return array_reverse($toSelected);
  }

  public function getGroupCursor() {
    if (isset($this->groupCursor[$this->selected])) {
      return $this->groupCursor[$this->selected];
    }
    return 0;
  }

  public function moveGroupCursor($h, $v) {
    $n = count($this->commands[$this->selected]);
    if (!isset($this->groupCursor[$this->selected])) {
      return;
    }
    $groupCursor = $this->groupCursor[$this->selected];
    if ($n > 4) {
      $columns = 3;
    } else if ($n > 1) {
      $columns = 2;
    } else {
      return;
    }
    $groupCursor += $h + $v * $columns;
    if ($groupCursor < 0) {
      $groupCursor = 0;
    }
    if ($groupCursor > $n - 1) {
      $groupCursor = $n - 1;
    }
    $this->groupCursor[$this->selected] = $groupCursor;
  }

  public function previousCommand() {
    $this->selected = max(0, $this->selected - 1);
  }

  public function nextCommand() {
    $n = count($this->commands);
    $this->selected = min($n - 1, $this->selected + 1);
  }

  public function currentCommand() {
    $groupCursor = $this->getGroupCursor();
    return $this->commands[$this->selected][$groupCursor];
  }

  public function endCommand() {
    $command = $this->currentCommand();
    if ($command->isZoomed()) {
      $command->toggleScroll(true);
    } else if ($this->selected === count($this->commands) - 2) {
      $this->nextCommand();
    }
  }

  public function deleteCommand($cid) {
    foreach ($this->commands as $i => $command) {
      foreach ($command as $j => $gcommand) {
        if ($gcommand->getCid() === $cid) {
          array_splice($this->commands[$i], $j, 1);
          if ($this->groupCursor[$i] >= count($this->commands[$i])) {
            $this->groupCursor[$i]--;
          }
          if (empty($this->commands[$i])) {
            array_splice($this->commands, $i, 1);
            array_splice($this->groupCursor, $i, 1);
            $this->selected--;
            $this->nextCommand();
          }
          return true;
        }
      }
    }
    return false;
  }

  public function getCommand($cid) {
    foreach ($this->commands as $command) {
      foreach ($command as $gcommand) {
        if ($gcommand->getCid() === $cid) {
          return $gcommand;
        }
      }
    }
    return false;
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
      $commandStr = [];
      foreach ($command as $gcommand) {
        $commandStr[] = $gcommand->command['commandString'];
      }
      $commandLine->setValue(implode(' & ', $commandStr));
    }
  }

  public function getenv() {
    return $this->env;
  }

  public function getvar($name) {
    $vname = substr($name, 1);
    if (isset($this->env[$vname])) {
      return $this->env[$vname];
    }
    if (isset($this->vars[$vname])) {
      return $this->vars[$vname];
    }
    return $name;
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
    if (preg_match('/^alias( |$)/', $commandString)) {
      $output = $this->alias($commandString);
      return true;
    }
    if (preg_match('/^s( |$)/', $commandString)) { // short verions of session
      $output = $this->session('session' . substr($commandString, 1));
      return true;
    }
    if (preg_match('/^session( |$)/', $commandString)) {
      $output = $this->session($commandString);
      return true;
    }
    if (preg_match('/^[0-9]+$/', $commandString)) {
      $output = $this->session("session {$commandString}");
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
    // exit: close session
    return false;
  }

}
