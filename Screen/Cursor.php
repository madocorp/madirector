<?php

namespace MADIR\Screen;

class Cursor extends \SPTK\Elements\TextEditor\Cursor {

  public function __construct() {
    $this->lines = [''];
  }

  public function setLines($lines) {
    $this->lines = $lines;
  }

}
