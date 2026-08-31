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

  public function __construct(
    private string $command,
    private string $workingDirectory,
    private array $environment
  ) {
    $this->buffer = new ScreenBuffer($this->rows, $this->cols);
    $this->started = microtime(true);
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
      'sequence' => [[
        'pipeline' => [[
          'argv' => ['/bin/bash', '-lc', $this->command],
          'redirects' => [],
        ]],
        'op' => false,
      ]],
      'commandString' => $this->command,
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
    if ($this->cid === null || !$this->isRunning()) {
      return;
    }
    CommanderHandler::sendInput($this->cid, $stream);
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

  public function title(): string {
    return $this->command;
  }

  public function status(): string {
    if ($this->isRunning()) {
      return 'Running since ' . date('Y-m-d H:i:s', (int)$this->started);
    }
    return sprintf('Exit %d (%.3fs)', (int)$this->returnValue, ($this->ended ?? microtime(true)) - $this->started);
  }

  public function isRunning(): bool {
    return $this->ended === null;
  }

}
