<?php

namespace MADIR\App;

use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Widgets\Dock;

/** Lays out one parallel command group in a compact grid. */
class TerminalGroup extends Dock {

  private const HORIZONTAL_SEPARATOR = 1;
  /** @var TerminalBox[] */
  private array $boxes = [];
  private Rect $availableFrame;

  public function __construct(string $name) {
    parent::__construct($name);
    $this->availableFrame = new Rect(0, 0, 80, 24);
  }

  public function addBox(TerminalBox $box): void {
    $this->boxes[] = $box;
    $this->add($box);
  }

  public function containsBox(TerminalBox $box): bool {
    return in_array($box, $this->boxes, true);
  }

  public function setAvailableFrame(Rect $frame): void {
    $this->availableFrame = $frame;
    foreach ($this->boxes as $box) {
      $box->setAvailableFrame($this->boxAvailableFrame());
    }
  }

  public function preferredRowsForColumns(int $columns): int {
    return max(1, $this->gridRows() * $this->boxHeight());
  }

  public function layout(): void {
    $columns = $this->gridColumns();
    $boxWidth = $this->boxWidth();
    $boxHeight = $this->boxHeight();
    foreach ($this->boxes as $index => $box) {
      $column = $index % $columns;
      $row = intdiv($index, $columns);
      $box->setAvailableFrame($this->boxAvailableFrame());
      $box->setFrame(new Rect(
        $this->frame->x + $column * ($boxWidth + self::HORIZONTAL_SEPARATOR),
        $this->frame->y + $row * $boxHeight,
        $boxWidth,
        $boxHeight
      ));
    }
  }

  public function render(RenderTarget $target): void {
    $target->pushClip($this->frame);
    foreach ($this->boxes as $box) {
      if ($box->frame()->width > 0 && $box->frame()->height > 0) {
        $box->render($target);
      }
    }
    $target->popClip();
  }

  private function boxAvailableFrame(): Rect {
    return new Rect(0, 0, $this->boxWidth(), $this->boxHeight());
  }

  private function boxWidth(): int {
    $columns = $this->gridColumns();
    return max(1, intdiv(max(1, $this->availableFrame->width - ($columns - 1) * self::HORIZONTAL_SEPARATOR), $columns));
  }

  private function boxHeight(): int {
    return max(1, intdiv(max(1, $this->availableFrame->height), $this->gridColumns()));
  }

  private function gridRows(): int {
    return max(1, (int)ceil(count($this->boxes) / $this->gridColumns()));
  }

  private function gridColumns(): int {
    $count = count($this->boxes);
    if ($count <= 1) {
      return 1;
    }
    if ($count <= 4) {
      return 2;
    }
    if ($count <= 9) {
      return 3;
    }
    return 4;
  }

}
