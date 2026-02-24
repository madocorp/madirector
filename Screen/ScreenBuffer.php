<?php

namespace MADIR\Screen;

class ScreenBuffer {

  const GLYPH = 0;
  const BG = 1;
  const FG = 2;
  const ATTR = 3;

  protected $mainScreen;
  protected $altScreen;
  protected $currentScreen;
  protected $scrollBuffer = [];
  protected $rows = 24;
  protected $cols = 80;
  protected $row = 0;
  protected $col = 0;
  protected $scrollRegionStart = 0;
  protected $scrollRegionEnd = 0;
  protected $savedCursor = [];
  protected $showCursor = true;
  protected $fg = 0xffffff;
  protected $bg = 0x000000;
  protected $attrs = 0;
  protected $parser;
  protected $applicationCursor = false;
  protected $applicationKeypad = false;
  protected $otherScreenState = false;
  protected $mainHeight = 1;

  public function __construct() {
    $this->parser = new ANSIParser($this);
    $this->fg = $this->parser->colors[7];
    $this->bg = $this->parser->colors[0];
    $this->scrollRegionStart = 0;
    $this->scrollRegionEnd = $this->rows - 1;
    $this->currentScreen = &$this->altScreen;
    $this->initScreen();
    $this->currentScreen = &$this->mainScreen;
    $this->initScreen();
  }

  public function parse($output) {
    $this->parser->parse($output);
  }

  protected function emptyCell() {
    return [
      self::GLYPH => ' ',
      self::BG => $this->bg,
      self::FG => $this->fg,
      self::ATTR =>  $this->attrs
    ];
  }

  protected function emptyLine() {
    $line = [];
    for ($j = 0; $j < $this->cols; $j++) {
      $line[$j] = $this->emptyCell();
    }
    return $line;
  }

  protected function initScreen() {
    $this->currentScreen = [];
    for ($i = 0; $i < $this->rows; $i++) {
      $this->currentScreen[$i] = $this->emptyLine();
    }
  }

  public function setCurrentBuffer($buffer) {
    $state = [
      'row' => $this->row,
      'col' => $this->col,
      'scrollRegionStart' => $this->scrollRegionStart,
      'scrollRegionEnd' => $this->scrollRegionEnd,
      'savedCursor' => $this->savedCursor
    ];
    if ($buffer === 0) {
      $this->currentScreen = &$this->mainScreen;
    } else {
      $this->currentScreen = &$this->altScreen;
    }
    if ($this->otherScreenState !== false) {
      $this->setRow($this->otherScreenState['row']);
      $this->col = $this->otherScreenState['col'];
      $this->scrollRegionStart = $this->otherScreenState['scrollRegionStart'];
      $this->scrollRegionEnd = $this->otherScreenState['scrollRegionEnd'];
      $this->savedCursor = $this->otherScreenState['savedCursor'];
    }
    $this->otherScreenState = $state;
  }

  public function putChar($chr) {
    $this->currentScreen[$this->row][$this->col] = [
      self::GLYPH => $chr,
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
    if ($this->row < $this->scrollRegionEnd) {
      $this->setRow($this->row + 1);
    } else if ($this->row == $this->scrollRegionEnd) {
      if ($this->currentScreen === $this->mainScreen) {
        $this->scrollBuffer[] = $this->currentScreen[$this->scrollRegionStart];
      }
      $this->scrollUp(1);
    } else if ($this->row < $this->rows) {
      $this->setRow($this->row + 1);
    }
    $this->col = 0;
  }

  public function carriageReturn() {
    $this->col = 0;
  }

  public function backSpace() {
    if ($this->col > 0) {
      $this->col--;
    }
  }

  public function tab() {
    $this->col = (int)($this->col / 8) * 8 + 8;
    if ($this->col > $this->cols) {
      $this->setRow($this->row + 1);
      $this->col = 0;
    }
  }

  public function debug() {
    echo str_repeat('-', $this->cols + 2), "\n";
    for ($i = 0; $i < $this->rows; $i++) {
      echo "|";
      for ($j = 0; $j < $this->cols; $j++) {
        echo $this->currentScreen[$i][$j][self::GLYPH] ?? ' ';
      }
      echo "|\n";
    }
    echo str_repeat('-', $this->cols + 2), "\n";
    foreach ($this->scrollBuffer as $line) {
      echo "> ";
      for ($j = 0; $j < $this->cols; $j++) {
        echo $line[$j][self::GLYPH] ?? ' ';
      }
      echo "\n";
    }
    echo count($this->scrollBuffer), "\n";
  }

  public function setForeground($color) {
    $this->fg = $color;
  }

  public function setBackground($color) {
    $this->bg = $color;
  }

  public function setBold($bold) {
    if ($bold) {
      $this->attrs = 1;
    } else {
      $this->attrs = 0;
    }
  }

  public function isBold() {
    return ($this->attrs & 1) > 0;
  }

  public function cursorUp($n) {
    // DEBUG:8 echo "cursorUp {$n}";
    if ($this->row >= $n) {
      $this->setRow($this->row - $n);
    } else {
      $this->setRow(0);
    }
  }

  public function cursorDown($n) {
    // DEBUG:8 echo "cursorDown {$n}";
    if ($this->row < $this->rows - $n - 1) {
      $this->setRow($this->row + $n);
    } else {
      $this->setRow($this->rows - 1);
    }
  }

  public function cursorLeft($n) {
    // DEBUG:8 echo "cursorLeft {$n}";
    if ($this->col >= $n) {
      $this->col -= $n;
    } else {
      $this->col = 0;
    }
  }

  public function cursorRight($n) {
    // DEBUG:8 echo "cursorRight {$n}";
    if ($this->col < $this->cols - $n - 1) {
      $this->col += $n;
    } else {
      $this->col = $this->cols - 1;
    }
  }

  public function cursorPos($n, $m) {
    // DEBUG:8 echo "cursorPos {$n} {$m}";
    if ($n !== false) {
      $this->setRow($n - 1);
      if ($this->row < 0) {
        $this->setRow(0);
      }
      if ($this->row > $this->rows - 1) {
        $this->setRow($this->rows - 1);
      }
    }
    if ($m !== false) {
      $this->col = $m - 1;
      if ($this->col < 0) {
        $this->col = 0;
      }
      if ($this->col > $this->cols - 1) {
        $this->col = $this->cols - 1;
      }
    }
  }

  public function eraseDisplay($n) {
    switch ($n) {
      case 1: // erase from cursor to beginning of screen
        for ($i = 0; $i <= $this->row; $i++) {
          if ($i === $this->row) {
            $end = $this->col;
          } else {
            $end = $this->cols;
          }
          for ($j = 0; $j < $end; $j++) {
            $this->currentScreen[$i][$j] = $this->emptyCell();
          }
        }
        break;
      case 3: // erase saved lines
        $this->scrollBuffer = [];
        // no break, clear the screen too
      case 2: // erase entire screen
        for ($i = 0; $i < $this->rows; $i++) {
          for ($j = 0; $j < $this->cols; $j++) {
            $this->currentScreen[$i][$j] = $this->emptyCell();
          }
        }
        break;
      default: // erase from cursor until end of screen
        for ($i = $this->row; $i < $this->rows; $i++) {
          if ($i === $this->row) {
            $start = $this->col;
          } else {
            $start = 0;
          }
          for ($j = $start; $j < $this->cols; $j++) {
            $this->currentScreen[$i][$j] = $this->emptyCell();
          }
        }
        break;
    }
  }

  public function eraseLine($n) {
    // DEBUG:8 echo "eraseLine {$n}";
    switch ($n) {
      case 1: // erase start of line to the cursor
        $start = 0;
        $end = $this->col;
        break;
      case 2: // erase the entire line
        $start = 0;
        $end = $this->cols;
        break;
      default: // erase from cursor to end of line
        $start = $this->col;
        $end = $this->cols;
        break;
    }
    for ($j = $start; $j < $end; $j++) {
      $this->currentScreen[$this->row][$j] = $this->emptyCell();
    }
  }

  public function insertLine($n) {
    if ($this->row < $this->scrollRegionStart || $this->row > $this->scrollRegionEnd) {
      return;
    }
    $height = $this->scrollRegionEnd - $this->scrollRegionStart + 1;
    $n = max(1, min($n, $height));
    for ($i = $this->scrollRegionEnd; $i >= $this->row + $n; $i--) {
      $this->currentScreen[$i] = $this->currentScreen[$i - $n];
    }
    for ($i = $this->row; $i < $this->row + $n; $i++) {
      $this->currentScreen[$i] = $this->emptyLine();
    }
  }

  public function deleteLine($n) {
    if ($this->row < $this->scrollRegionStart || $this->row > $this->scrollRegionEnd) {
      return;
    }
    $height = $this->scrollRegionEnd - $this->scrollRegionStart + 1;
    $n = max(1, min($n, $height));
    for ($i = $this->row; $i <= $this->scrollRegionEnd - $n; $i++) {
      $this->currentScreen[$i] = $this->currentScreen[$i + $n];
    }
    for ($i = $this->scrollRegionEnd - $n + 1; $i <= $this->scrollRegionEnd; $i++) {
      $this->currentScreen[$i] = $this->emptyLine();
    }
  }

  public function insertChars($n) {
    $n = max(1, min($n, $this->cols - $this->col));
    $i = $this->row;
    for ($j = $this->cols - 1; $j >= $this->col; $j--) {
      if ($j < $this->col + $n) {
        $this->currentScreen[$i][$j] = $this->emptyCell();
      } else {
        $this->currentScreen[$i][$j] = $this->currentScreen[$i][$j - $n];
      }
    }
  }

  public function deleteChars($n) {
    $n = max(1, min($n, $this->cols - $this->col));
    $i = $this->row;
    for ($j = $this->col; $j < $this->cols; $j++) {
      if ($j + $n < $this->cols) {
        $this->currentScreen[$i][$j] = $this->currentScreen[$i][$j + $n];
      } else {
        $this->currentScreen[$i][$j] = $this->emptyCell();
      }
    }
  }

  public function eraseChars($n) {
    $n = max(1, min($n, $this->cols - $this->col));
    $i = $this->row;
    for ($j = $this->col; $j < $this->cols && $j < $this->col + $n; $j++) {
      $this->currentScreen[$i][$j] = $this->emptyCell();
    }
  }

  public function scrollUp($n) {
    // DEBUG:8 echo "scrollUp {$n}";
    for ($i = $this->scrollRegionStart; $i <= $this->scrollRegionEnd; $i++) {
      if ($i + $n <= $this->scrollRegionEnd) {
        $this->currentScreen[$i] = $this->currentScreen[$i + $n];
      } else {
        $this->currentScreen[$i] = $this->emptyLine();
      }
    }
  }

  public function scrollDown($n) {
    // DEBUG:8 echo "scrollDown {$n}";
    for ($i = $this->scrollRegionEnd; $i >= $this->scrollRegionStart; $i--) {
      if ($i < $this->scrollRegionStart + $n) {
        $this->currentScreen[$i] = $this->emptyLine();
      } else {
        $this->currentScreen[$i] = $this->currentScreen[$i - $n];
      }
    }
  }

  public function scrollRegion($n, $m) {
    if ($m <= 1 || $m >= $this->cols) {
      $m = $this->cols - 1;
    }
    if ($n < 1) {
      $n = 0;
    }
    if ($n > $m) {
      $n = $m - 1;
    }
    $this->scrollRegionStart = $n - 1;
    $this->scrollRegionEnd = $m - 1;
  }

  public function applicationCursor($state) {
    $this->applicationCursor = $state;
  }

  public function getApplicationCursorState() {
    return $this->applicationCursor;
  }

  public function applicationKeypad($state) {
    $this->applicationKeypad = $state;
  }

  public function getApplicationKeypadState() {
    return $this->applicationKeypad;
  }

  public function getLines() {
    if ($this->currentScreen === $this->mainScreen) {
      return $this->currentScreen; // scroll
    } else {
      return $this->currentScreen;
    }
  }

  public function countLines() {
    if ($this->currentScreen === $this->mainScreen) {
      return min($this->rows, $this->mainHeight + 1);
    }
    return $this->rows;
  }

  public function saveCursor($saveState = false) {
    // DEBUG:8 echo "saveCursor: {$this->row}, {$this->col}";
    $this->savedCursor[0] = $this->row;
    $this->savedCursor[1] = $this->col;
    if ($saveState) {
      $this->savedCursor[2] = $this->attrs;
      $this->savedCursor[3] = $this->parser->getCharset();
      $this->savedCursor[4] = $this->applicationCursor;
      $this->savedCursor[5] = $this->applicationKeypad;
    }
  }

  public function restoreCursor($restoreState = false) {
    if (empty($this->savedCursor)) {
      return;
    }
    // DEBUG:8 echo "restoreCursor: {$this->row}, {$this->col}";
    $this->setRow($this->savedCursor[0]);
    $this->col = $this->savedCursor[1];
    if ($restoreState && count($this->savedCursor) > 2) {
      $this->attrs = $this->savedCursor[2];
      $this->parser->setCharset($this->savedCursor[3]);
      $this->applicationCursor = $this->savedCursor[4];
      $this->applicationKeypad = $this->savedCursor[5];
    }
  }

  public function setRow($row) {
    $this->row = $row;
    if ($this->currentScreen === $this->mainScreen) {
      $this->mainHeight = max($row, $this->mainHeight);
    }
  }

  public function cursor($show) {
    $this->showCursor = $show;
  }

  public function getCursor() {
    if ($this->showCursor) {
      return [$this->row, $this->col];
    }
    return false;
  }

  public function setSize($rows, $cols) {
    $this->rows = $rows;
    $this->cols = $col;
  }

}
