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

  public function __construct() {
    self::$sessions[] = $this;
    self::$current = count(self::$sessions) - 1;
    $this->runCommand(false);
    $this->selected = 0;
  }

  public function runCommand($command) {
    array_splice($this->commands, -1, 0, [new Command($command, $this)]);
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

  public function endCommand() {
    if ($this->selected === count($this->commands) - 2) {
      $this->nextCommand();
    }
  }

  public function toggleGrab() {
    $command = $this->commands[$this->selected];
    if ($command->isNew()) {
      return;
    }
    if ($command->grab) {
      $command->grab = false;
    } else {
      $command->grab = true;
    }
  }

}