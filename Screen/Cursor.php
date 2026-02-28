<?php

namespace MADIR\Screen;

class Cursor extends \SPTK\Elements\TextEditor\Cursor {

  protected $lines = 1;
  protected $cols = 1;

  public function __construct() {
    ;
  }

  public function setCols($cols) {
    $this->cols = $cols;
  }

  public function setLines($lines) {
    $this->lines = $lines;
  }

  protected function getLineLength($i) {
    return $this->cols;
  }

  protected function getLineCount() {
    return $this->lines;
  }


}
