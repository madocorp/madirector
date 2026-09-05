<?php

namespace MADIR\App;

use SPTK\Core\Len;
use SPTK\Core\Place;
use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Core\Element;
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

  public function __construct(string $name, private RunningCommand $command, private int $terminalCellHeight = TerminalView::DEFAULT_CELL_HEIGHT) {
    parent::__construct($name);
    $this->terminalCellHeight = max(1, $this->terminalCellHeight);
    $this->availableFrame = new Rect(0, 0, 80, 24);
    $this->header = new TerminalHeader($name . '-header', $command->title(), $command->status());
    $this->terminal = new TerminalView($name . '-terminal', $command, $this->terminalCellHeight);
    $this->padding = new TerminalPadding($name . '-padding');

    $this->place($this->header, Place::dock('top', Len::cell(1)));
    $this->place($this->padding, Place::dock('bottom', Len::cell(1)));
    $this->place($this->terminal, Place::fill());
  }

  public function setFrame(Rect $frame): void {
    parent::setFrame($frame);
    $this->command->resize($this->maxOutputRows(), $this->availableFrame->width);
  }

  public function setAvailableFrame(Rect $frame): void {
    $this->availableFrame = $frame;
    $this->command->resize($this->maxOutputRows(), $this->availableFrame->width);
  }

  public function preferredRowsForColumns(int $columns): int {
    $maxRows = $this->maxStackRows();
    if ($this->command->isAltScreenActive()) {
      return $maxRows;
    }
    return min($maxRows, 2 + $this->terminalRowsAsWidgetRows($this->command->outputRows()));
  }

  public function refresh(): void {
    $this->setPreferredRows($this->preferredRowsForColumns(max(1, $this->frame()->width)));
    $this->header->setStatus($this->command->status());
    $this->terminal->setTerminalState($this->selected, $this->inputRedirected, $this->scrollMode);
    $this->terminal->refresh();
  }

  public function requestTerminalFocus(): void {
    $this->terminal->requestFocus();
  }

  public function setTerminalState(bool $selected, bool $inputRedirected, bool $scrollMode = false): void {
    if ($this->selected !== $selected || $this->inputRedirected !== $inputRedirected || $this->scrollMode !== $scrollMode) {
      $this->invalidateRender();
    }
    $this->selected = $selected;
    $this->inputRedirected = $inputRedirected;
    $this->scrollMode = $scrollMode;
    $this->terminal->setTerminalState($selected, $inputRedirected, $scrollMode);
  }

  public function render(RenderTarget $target): void {
    parent::render($target);
    $target->drawOuterRect($this->terminal->frame(), $this->borderColor(), self::BORDER_WIDTH);
  }

  private function maxOutputRows(): int {
    return TerminalView::rowsForPixelHeightWithCellHeight(max(1, $this->maxStackRows() - 2) * $this->theme->rowHeight, $this->terminalCellHeight);
  }

  private function terminalRowsAsWidgetRows(int $terminalRows): int {
    return max(1, (int)ceil(TerminalView::pixelHeightForRowsWithCellHeight($terminalRows, $this->terminalCellHeight) / max(1, $this->theme->rowHeight)));
  }

  private function maxStackRows(): int {
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

}

class TerminalPadding extends Element {

  public function __construct(string $name) {
    parent::__construct($name);
    $this->setPreferredRows(1);
  }

  protected function paint(RenderTarget $target): void {
    $target->fill($this->frame, ' ', $this->theme->fg, $this->theme->bg);
  }

}

class TerminalHeader extends Element {

  public function __construct(string $name, private string $title, private string $status) {
    parent::__construct($name);
    $this->setPreferredRows(1);
    $this->setPreferredColumns(mb_strlen($title) + mb_strlen($status) + 1);
  }

  public function setStatus(string $status): void {
    if ($this->status !== $status) {
      $this->status = $status;
      $this->invalidateRender();
    }
  }

  protected function paint(RenderTarget $target): void {
    $width = max(0, $this->frame->width);
    if ($width === 0) {
      return;
    }
    $status = mb_substr($this->status, 0, $width);
    $statusWidth = mb_strlen($status);
    $titleWidth = max(0, $width - $statusWidth - 1);
    $title = mb_substr($this->title, 0, $titleWidth);
    $target->fill($this->frame, ' ', $this->theme->fg, $this->theme->bg);
    if ($title !== '') {
      $target->write($this->frame->x, $this->frame->y, $title, $this->theme->fg, $this->theme->bg);
    }
    if ($status !== '') {
      $target->write($this->frame->x + $width - $statusWidth, $this->frame->y, $status, $this->theme->muted, $this->theme->bg);
    }
  }

}
