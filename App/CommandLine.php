<?php

namespace MADIR\App;

use SPTK\Core\Element;
use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;

/** One visible command row: prompt, spacer, and editable command input. */
class CommandLine extends Element {

  private CommandInput $input;
  private bool $visible = true;

  public function __construct(string $name = '') {
    parent::__construct($name);
    $this->input = new CommandInput($name . '-input');
    $this->add($this->input);
    $this->setPreferredRows(1);
  }

  public function setVisible(bool $visible): void {
    $this->visible = $visible;
    $this->input->setVisible($visible);
    $this->setPreferredRows($visible ? 1 : 0);
  }

  public function isVisible(): bool {
    return $this->visible;
  }

  public function text(): string {
    return $this->input->text();
  }

  public function setText(string $text): void {
    $this->input->setText($text);
    $this->input->setCursorPosition(mb_strlen($text));
  }

  public function requestFocus(): void {
    $this->input->requestFocus();
  }

  public function preferredRowsForColumns(int $columns): int {
    return $this->visible ? 1 : 0;
  }

  public function layout(): void {
    $this->input->setFrame(new Rect($this->frame->x + 2, $this->frame->y, max(0, $this->frame->width - 2), $this->frame->height));
  }

  protected function paint(RenderTarget $target): void {
    if (!$this->visible) {
      return;
    }
    $fg = $this->input->commandLineFg();
    $bg = $this->input->commandLineBg();
    $target->fill($this->frame, ' ', $fg, $bg);
    if ($this->frame->width > 0) {
      $target->put($this->frame->x, $this->frame->y, '$', $fg, $bg);
    }
  }

}
