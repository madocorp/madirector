<?php

namespace MADIR\Command;

class ScreenBuffer {

  const CHAR = 0;
  const BG = 1;
  const FG = 2;
  const ATTR = 3;

  public $mainScreen;
  public $altScreen;
  public $currentScreen;
  public $scrollBuffer = [];
  public $rows = 25;
  public $cols = 80;
  public $row = 0;
  public $col = 0;
  public $showCursor = true;
  public $scroll = true;
  public $fg = 0xeeeeee;
  public $bg = 0x000000;
  public $attrs = 0;
  public $parser;

  public function __construct() {
    $this->mainScreen = [];
    $this->currentScreen = &$this->mainScreen;
    $this->parser = new ANSIParser($this);
  }

  protected function initScreen() {
    $this->currentScreen = [];
    for ($i = 0; $i < $this->rows; $i++) {
      for ($j = 0; $j < $this->cols; $j++) {
        $this->currentScreen[$i][$j] = [
          self::CHAR => ' ',
          self::BG => $this->bg,
          self::FG => $this->fg,
          self::ATTR =>  $this->attrs
        ];
      }
    }
  }

  public function putChar($chr) {
    $this->currentScreen[$this->row][$this->col] = [
      self::CHAR => $chr,
      self::BG => $this->bg,
      self::FG => $this->fg,
      self::ATTR =>  $this->attrs
    ];
    $this->col++;
    if ($this->col > $this->cols) {
      $this->lineFeed();
    }
  }

  public function lineFeed() {
    if ($this->row >= $this->rows) {
      $this->scrollBuffer[] = $this->currentScreen[0];
      // fill scrollBuffer if needed
      array_splice($this->currentScreen, 0, 1);
    } else {
      $this->row++;
    }
    for ($j = 0; $j < $this->cols; $j++) {
      $this->currentScreen[$this->row][$j] = [
        self::CHAR => ' ',
        self::BG => $this->bg,
        self::FG => $this->fg,
        self::ATTR =>  $this->attrs
      ];
    }
    $this->col = 0;
  }

  public function carriageReturn() {
    $this->col = 0;
  }

  public function backSpace() {
    $this->col--;
    if ($this->col < 0) {
      $this->col = 0;
    }
  }

  public function tab() {
    $this->col = (int)($this->col / 8) * 8 + 8;
    if ($this->col > $this->cols) {
      $this->row++;
      $this->col = 0;
    }
  }

  public function debug() {
    echo str_repeat('-', $this->cols + 2), "\n";
    for ($i = 0; $i < $this->rows; $i++) {
      echo "|";
      for ($j = 0; $j < $this->cols; $j++) {
        echo $this->currentScreen[$i][$j][self::CHAR] ?? ' ';
      }
      echo "|\n";
    }
    echo str_repeat('-', $this->cols + 2), "\n";
    foreach ($this->scrollBuffer as $line) {
      echo "> ";
      for ($j = 0; $j < $this->cols; $j++) {
        echo $line[$j][self::CHAR] ?? ' ';
      }
      echo "\n";
    }
    echo count($this->scrollBuffer), "\n";
  }

}
