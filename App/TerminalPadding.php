<?php

namespace MADIR\App;

use SPTK\Core\Element;
use SPTK\Core\RenderTarget;

class TerminalPadding extends Element {

  public function __construct(string $name) {
    parent::__construct($name);
    $this->setPreferredRows(1);
  }

  protected function paint(RenderTarget $target): void {
    $target->fill($this->frame, ' ', $this->theme->fg, $this->theme->bg);
  }

}
