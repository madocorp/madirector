<?php

namespace MADIR\App;

use SPTK\Core\Color;
use SPTK\Core\Len;
use SPTK\Core\Place;
use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Core\SurfaceRenderTarget;
use SPTK\Widgets\Dock;

/** Framed command output area with header and terminal body. */
class TerminalBox extends Dock {

  private const BORDER_WIDTH = 2;
  private const STACK_HEIGHT_RATIO = 0.75;

  private TerminalHeader $header;
  private TerminalView $terminal;
  private TerminalPadding $padding;
  private Rect $availableFrame;
  private bool $selected = false;
  private bool $inputRedirected = false;
  private bool $scrollMode = false;
  private bool $zoomed = false;

  public function __construct(string $name, private RunningCommand $command, private int $terminalCellHeight = TerminalView::DEFAULT_CELL_HEIGHT) {
    parent::__construct($name);
    $this->terminalCellHeight = max(1, $this->terminalCellHeight);
    $this->availableFrame = new Rect(0, 0, 80, 24);
    $this->header = new TerminalHeader($name . '-header', $command->title(), $command->status(), $this->titleColor());
    $this->terminal = new TerminalView($name . '-terminal', $command, $this->terminalCellHeight);
    $this->padding = new TerminalPadding($name . '-padding');

    $this->place($this->header, Place::dock('top', Len::cell(1)));
    $this->place($this->padding, Place::dock('bottom', Len::cell(1)));
    $this->place($this->terminal, Place::fill());
  }

  public function setDoubleShiftCallback(?callable $callback): void {
    $this->terminal->setDoubleShiftCallback($callback);
  }

  public function setFrame(Rect $frame): void {
    parent::setFrame($frame);
    if ($this->zoomed) {
      return;
    }
    $this->command->resize($this->maxOutputRows(), $this->availableFrame->width);
  }

  public function setAvailableFrame(Rect $frame): void {
    $this->availableFrame = $frame;
    if ($this->zoomed) {
      return;
    }
    $this->command->resize($this->maxOutputRows(), $this->availableFrame->width);
  }

  public function preferredRowsForColumns(int $columns): int {
    $maxRows = $this->maxStackRows();
    if ($this->zoomed) {
      return $maxRows;
    }
    if ($this->command->isAltScreenActive()) {
      return $maxRows;
    }
    return min($maxRows, 2 + $this->terminalRowsAsWidgetRows($this->command->outputRows()));
  }

  public function refresh(): void {
    $this->setPreferredRows($this->preferredRowsForColumns(max(1, $this->frame()->width)));
    $this->header->setStatus($this->command->status());
    $this->header->setTitleColor($this->titleColor());
    $this->terminal->setTerminalState($this->selected, $this->inputRedirected, $this->scrollMode, $this->zoomed);
    $this->terminal->refresh();
  }

  public function requestTerminalFocus(): void {
    $this->terminal->requestFocus();
  }

  public function setTerminalState(bool $selected, bool $inputRedirected, bool $scrollMode = false, bool $zoomed = false): void {
    if (
      $this->selected !== $selected
      || $this->inputRedirected !== $inputRedirected
      || $this->scrollMode !== $scrollMode
      || $this->zoomed !== $zoomed
    ) {
      $this->invalidateRender();
    }
    $this->selected = $selected;
    $this->inputRedirected = $inputRedirected;
    $this->scrollMode = $scrollMode;
    $this->zoomed = $zoomed;
    $this->terminal->setTerminalState($selected, $inputRedirected, $scrollMode, $zoomed);
  }

  public function layout(): void {
    if (!$this->zoomed) {
      parent::layout();
      return;
    }
    $this->header->setFrame(new Rect($this->frame->x, $this->frame->y, 0, 0));
    $this->padding->setFrame(new Rect($this->frame->x, $this->frame->y, 0, 0));
    $this->terminal->setFrame($this->frame);
  }

  public function render(RenderTarget $target): void {
    parent::render($target);
    if ($this->zoomed) {
      return;
    }
    $target->drawOuterRect($this->terminal->frame(), $this->borderColor(), self::BORDER_WIDTH);
  }

  public function renderZoomSurface(SurfaceRenderTarget $target, Rect $pixelFrame): void {
    $this->terminal->renderPixelSurface($target, $pixelFrame);
  }

  private function maxOutputRows(): int {
    $overhead = $this->zoomed ? 0 : 2;
    return TerminalView::rowsForPixelHeightWithCellHeight(max(1, $this->maxStackRows() - $overhead) * $this->theme->rowHeight, $this->terminalCellHeight);
  }

  private function terminalRowsAsWidgetRows(int $terminalRows): int {
    return max(1, (int)ceil(TerminalView::pixelHeightForRowsWithCellHeight($terminalRows, $this->terminalCellHeight) / max(1, $this->theme->rowHeight)));
  }

  private function maxStackRows(): int {
    if ($this->zoomed) {
      return max(1, $this->availableFrame->height);
    }
    return max(1, min($this->availableFrame->height, (int)floor($this->availableFrame->height * self::STACK_HEIGHT_RATIO)));
  }

  private function borderColor(): string {
    if ($this->scrollMode) {
      return '#ffff00';
    }
    if ($this->inputRedirected) {
      return '#000000';
    }
    if ($this->selected) {
      return '#ffffff';
    }
    return '#555555';
  }

  private function titleColor(): Color|string {
    if ($this->command->isRunning()) {
      return '#00ffff';
    }
    if ($this->command->failed()) {
      return '#ff5555';
    }
    return $this->theme->fg;
  }

}
