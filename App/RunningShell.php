<?php

namespace MADIR\App;

use MADIR\Pty\ShellProcess;
use MADIR\Screen\ScreenBuffer;

/** Connect one long lived shell PTY to the terminal screen and image buffers. */
final class RunningShell {

  private ScreenBuffer $buffer;
  private TerminalImages $images;
  private ShellProcess $process;
  private int $rows = 24;
  private int $cols = 80;

  public function __construct() {
    $this->buffer = new ScreenBuffer($this->rows, $this->cols);
    $this->images = new TerminalImages($this->buffer, $this->inputDirect(...));
    $this->buffer->setTerminal($this->images);
    $this->process = new ShellProcess($this->rows, $this->cols);
  }

  /** Drain shell output and update the terminal screen. */
  public function poll(): bool {
    $output = $this->process->poll();
    if ($output !== '') {
      $this->buffer->parse($output);
      return true;
    }
    return false;
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
    $this->process->resize($rows, $cols);
  }

  public function input(string $bytes): void {
    $this->process->input($bytes);
  }

  public function inputDirect(string $bytes): void {
    $this->process->input($bytes);
  }

  public function buffer(): ScreenBuffer {
    return $this->buffer;
  }

  public function images(): TerminalImages {
    return $this->images;
  }

  public function isRunning(): bool {
    return $this->process->isRunning();
  }

  public function isAltScreenActive(): bool {
    return $this->buffer->isAltScreenActive();
  }

  public function outputRows(): int {
    return $this->rows;
  }

  public function title(): string {
    return 'Shell';
  }

  public function status(): string {
    return $this->isRunning() ? 'running' : 'exited';
  }

  public function failed(): bool {
    return false;
  }

  public function close(): void {
    $this->process->close();
  }

}
