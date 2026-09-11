<?php

namespace MADIR\App;

/** Tracks terminal command/input/scroll mode and mode-switch gestures. */
class TerminalState {

  private const DOUBLE_SHIFT_LIMIT_MS = 300;

  private string $mode = 'prompt';
  private bool $zoomed = false;
  private float $lastShiftRelease = 0.0;

  public function enterPrompt(): void {
    $this->setMode('prompt');
    $this->zoomed = false;
  }

  public function enterNormal(): void {
    $this->setMode('normal');
    $this->zoomed = false;
  }

  public function enterInput(): void {
    $this->setMode('input');
  }

  public function enterZoomInput(): void {
    $this->setMode('input');
    $this->zoomed = true;
  }

  public function enterScroll(): void {
    $this->setMode('scroll');
  }

  public function switchF12(bool $canEnterInput): bool {
    if ($this->isScroll()) {
      return $this->switchToInputIfPossible($canEnterInput);
    }
    if ($this->isInput()) {
      $this->enterNormal();
      return true;
    }
    return $this->switchToInputIfPossible($canEnterInput);
  }

  public function switchDoubleShift(bool $canEnterInput): bool {
    if ($this->isScroll()) {
      return $this->switchToInputIfPossible($canEnterInput);
    }
    if ($this->isInput()) {
      $this->enterNormal();
      return true;
    }
    return $this->switchToInputIfPossible($canEnterInput);
  }

  public function shiftReleased(bool $canEnterInput): bool {
    $now = microtime(true) * 1000;
    if ($this->lastShiftRelease === 0.0 || $now - $this->lastShiftRelease > self::DOUBLE_SHIFT_LIMIT_MS) {
      $this->lastShiftRelease = $now;
      return false;
    }
    $this->lastShiftRelease = 0.0;
    return $this->switchDoubleShift($canEnterInput);
  }

  public function isInput(): bool {
    return $this->mode === 'input';
  }

  public function isScroll(): bool {
    return $this->mode === 'scroll';
  }

  public function isZoomed(): bool {
    return $this->zoomed;
  }

  private function switchToInputIfPossible(bool $canEnterInput): bool {
    if (!$canEnterInput) {
      return false;
    }
    $this->enterInput();
    return true;
  }

  private function setMode(string $mode): void {
    $this->mode = $mode;
    $this->lastShiftRelease = 0.0;
  }

}
