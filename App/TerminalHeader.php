<?php

namespace MADIR\App;

use SPTK\Core\Color;
use SPTK\Core\Element;
use SPTK\Core\RenderTarget;

class TerminalHeader extends Element {

  public function __construct(string $name, private string $title, private string $status, private Color|string $titleColor) {
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

  public function setTitleColor(Color|string $titleColor): void {
    if ($this->titleColor !== $titleColor) {
      $this->titleColor = $titleColor;
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
      $promptEnd = mb_strpos($title, '$ ');
      if ($promptEnd !== false) {
        $prompt = mb_substr($title, 0, $promptEnd + 2);
        $command = mb_substr($title, $promptEnd + 2);
        $target->write($this->frame->x, $this->frame->y, $prompt, $this->theme->muted, $this->theme->bg);
        if ($command !== '') {
          $target->write($this->frame->x + mb_strlen($prompt), $this->frame->y, $command, $this->titleColor, $this->theme->bg);
        }
      } else {
        $target->write($this->frame->x, $this->frame->y, $title, $this->titleColor, $this->theme->bg);
      }
    }
    if ($status !== '') {
      $target->write($this->frame->x + $width - $statusWidth, $this->frame->y, $status, $this->theme->muted, $this->theme->bg);
    }
  }

}
