<?php

namespace MADIR\App;

/** One visible MaDirector command screen. */
class AppSession {

  public CommandSession $commandSession;
  /** @var RunningCommand[] */
  public array $commands = [];
  /** @var array<int, int|null> */
  public array $commandGroupIds = [];
  public int $nextGroupId = 1;
  /** @var string[] */
  public array $history = [];
  public int $historyOffset = 0;
  public string $historyDraft = '';
  public ?RunningCommand $runningCommand = null;
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

  public function recordHistory(string $command): void {
    $command = trim($command);
    if ($command === '') {
      return;
    }
    $this->history[] = $command;
    $this->resetHistoryNavigation();
  }

  public function browseHistory(int $step, string $currentText): ?string {
    if ($this->history === []) {
      return null;
    }
    if ($this->historyOffset === 0) {
      $this->historyDraft = $currentText;
    }
    $count = count($this->history);
    $this->historyOffset = max(0, min($count, $this->historyOffset + $step));
    if ($this->historyOffset === 0) {
      return $this->historyDraft;
    }
    return $this->history[$count - $this->historyOffset];
  }

  public function resetHistoryNavigation(): void {
    $this->historyOffset = 0;
    $this->historyDraft = '';
  }

}
