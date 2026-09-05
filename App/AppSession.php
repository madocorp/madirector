<?php

namespace MADIR\App;

/** One visible MaDirector command screen. */
class AppSession {

  public CommandSession $commandSession;
  /** @var RunningCommand[] */
  public array $commands = [];
  /** @var TerminalBox[] */
  public array $terminalBoxes = [];
  public ?RunningCommand $runningCommand = null;
  public ?int $selectedTerminalIndex = null;
  public ?string $name = null;

  public function __construct(public int $id) {
    $this->commandSession = new CommandSession();
  }

  public function hasRunningCommands(): bool {
    foreach ($this->commands as $command) {
      if ($command->isRunning()) {
        return true;
      }
    }
    return false;
  }

  public function terminateRunningCommands(int $signal = SIGKILL): void {
    foreach ($this->commands as $command) {
      $command->terminate($signal);
    }
  }

}
