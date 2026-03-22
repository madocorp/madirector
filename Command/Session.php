<?php

namespace MADIR\Command;

class Session {

  use InternalCommands;

  public static $sessions = [];
  public static $current;
  public static $alias = [
    'ls' => 'ls --color=auto'
  ];

  public static function getCurrent() {
    return self::$sessions[self::$current];
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
    if ($this->selected === count($this->commands) - 2) {
      $this->nextCommand();
    }
  }

  public function deleteCommand($cid) {
    foreach ($this->commands as $i => $command) {
      foreach ($command as $gcommand) {
        if ($gcommand->getCid() === $cid) {
          array_splice($this->commands, $i, 1);
          array_splice($this->groupCursor, $i, 1);
          $this->selected--;
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

  public static function getAliasList() {
    return self::$alias;
  }

}
