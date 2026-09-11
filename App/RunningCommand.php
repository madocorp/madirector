<?php

namespace MADIR\App;

use MADIR\Pty\CommanderHandler;
use MADIR\Screen\ScreenBuffer;

/** Adapts one submitted command to the existing commander/PTY protocol. */
class RunningCommand {

  private ScreenBuffer $buffer;
  private ?TerminalBox $box = null;
  private ?int $cid = null;
  private float $started;
  private ?float $ended = null;
  private int|false $returnValue = false;
  private bool $ptyReady = false;
  private int $rows = 24;
  private int $cols = 80;
  private $inputCallback = null;

  public function __construct(
    private array $command,
    private string $workingDirectory,
    private array $environment
  ) {
    $this->buffer = new ScreenBuffer($this->rows, $this->cols);
    $this->started = microtime(true);
  }

  public static function completed(array $command, string $workingDirectory, array $environment, string $output, int $returnValue = 0): self {
    $runningCommand = new self($command, $workingDirectory, $environment);
    if ($output !== '') {
      $runningCommand->output($output);
    }
    $runningCommand->end($returnValue);
    return $runningCommand;
  }

  public function attachBox(TerminalBox $box): void {
    $this->box = $box;
  }

  public function start(): void {
    $this->started = microtime(true);
    $this->cid = CommanderHandler::runCommand($this);
    $this->box?->refresh();
  }

  public function getCommandMessage(): string {
    return json_encode([
      'rows' => $this->rows,
      'cols' => $this->cols,
      'wd' => $this->workingDirectory,
      'env' => $this->environment,
      'sequence' => $this->command['sequence'],
      'commandString' => $this->command['commandString'],
    ]);
  }

  public function output(string $stream): void {
    $this->ptyReady = true;
    $this->buffer->parse($stream);
    $this->box?->refresh();
  }

  public function end(int|false $returnValue): void {
    $this->ptyReady = true;
    $this->returnValue = $returnValue;
    $this->ended = microtime(true);
    $this->buffer->cursor(false);
    $this->box?->refresh();
  }

  public function refresh(): void {
    $this->box?->refresh();
  }

  public function setInputCallback(?callable $inputCallback): void {
    $this->inputCallback = $inputCallback;
  }

  public function resize(int $rows, int $cols): void {
    $rows = max(1, $rows);
    $cols = max(1, $cols);
    if ($rows === $this->rows && $cols === $this->cols) {
      return;
    }
    $this->rows = $rows;
    $this->cols = $cols;
    $this->buffer->setSize($rows, $cols);
    if ($this->cid !== null && $this->ptyReady && $this->isRunning()) {
      CommanderHandler::sendSize($this->cid, $rows, $cols);
    }
    $this->box?->refresh();
  }

  public function input(string $stream): void {
    if ($this->inputCallback !== null) {
      call_user_func($this->inputCallback, $this, $stream);
      return;
    }
    $this->inputDirect($stream);
  }

  public function inputDirect(string $stream): void {
    if ($this->cid === null || !$this->isRunning()) {
      return;
    }
    CommanderHandler::sendInput($this->cid, $stream);
  }

  public function terminate(int $signal = SIGKILL): void {
    if ($this->cid === null || !$this->isRunning()) {
      return;
    }
    CommanderHandler::sendSignal($this->cid, $signal);
  }

  public function buffer(): ScreenBuffer {
    return $this->buffer;
  }

  public function outputRows(): int {
    $lines = $this->buffer->getLines();
    while ($lines !== [] && end($lines) === '') {
      array_pop($lines);
    }
    return max(1, min($this->rows, count($lines)));
  }

  public function isAltScreenActive(): bool {
    return $this->buffer->isAltScreenActive();
  }

  public function title(): string {
    return $this->promptPath() . '$ ' . $this->command['commandString'];
  }

  public function status(): string {
    $started = date('Y-m-d H:i:s', (int)$this->started);
    if ($this->isRunning()) {
      return sprintf('[%s]', $started);
    }
    return sprintf('#%d (%s) [%s]', (int)$this->returnValue, $this->formatDuration(($this->ended ?? microtime(true)) - $this->started), $started);
  }

  public function isRunning(): bool {
    return $this->ended === null;
  }

  public function failed(): bool {
    return !$this->isRunning() && (int)$this->returnValue !== 0;
  }

  private function formatDuration(float $seconds): string {
    if ($seconds < 60) {
      return sprintf('%.3fs', $seconds);
    }
    if ($seconds < 3600) {
      return sprintf('%.1fm', $seconds / 60);
    }
    return sprintf('%.1fh', $seconds / 3600);
  }

  private function promptPath(): string {
    $path = rtrim($this->workingDirectory, '/');
    return $path === '' ? '/' : $path;
  }

}
