<?php

namespace MADIR\Screen;

class ScreenBuffer {

  const GLYPH = 0;
  const BG = 1;
  const FG = 2;
  const ATTR = 3;
  const INPUT_ROW = 4;
  const WRAPPABLE = 5;
  const WRAPPED = 6;
  const A_BOLD = 1;

  protected $mainScreen;
  protected $altScreen;
  protected $currentScreen;
  protected $scrollBuffer = [];
  protected $scrollRuns = [];
  protected $scrollWrap = [];
  protected $rows = 25;
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
  protected $fill = false;
  protected $pendingWrap = false;

  public function __construct($rows, $cols) {
    $this->rows = $rows;
    $this->cols = $cols;
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

  public function setFill($fill) {
    $this->fill = $fill;
  }

  public function putChar($chr) {
    if ($this->pendingWrap) {
      $this->currentScreen[$this->row][$this->col][self::WRAPPED] = true;
      $this->lineFeed();
    }
    $this->currentScreen[$this->row][$this->col] = [
      self::GLYPH => $chr,
      self::BG => $this->bg,
      self::FG => $this->fg,
      self::ATTR => $this->attrs
    ];
    if ($this->col === $this->cols - 1) {
      $this->pendingWrap = true;
    } else {
      $this->col++;
    }
  }

  public function lineFeed($cr = true) {
    if ($this->row < $this->scrollRegionEnd) {
      $this->setRow($this->row + 1);
    } else if ($this->row == $this->scrollRegionEnd) {
      if ($this->currentScreen === $this->mainScreen && $this->scrollRegionStart === 0 && $this->scrollRegionEnd === $this->rows - 1) {
        $this->pushToScrollBuffer($this->currentScreen[$this->scrollRegionStart]);
      }
      $this->scrollUp(1);
    } else if ($this->row < $this->rows) {
      $this->setRow($this->row + 1);
    }
    if ($cr) {
      $this->col = 0;
    }
    $this->pendingWrap = false;
  }

  public function carriageReturn() {
    $this->pendingWrap = false;
    $this->col = 0;
  }

  public function backSpace() {
    $this->pendingWrap = false;
    if ($this->col > 0) {
      $this->col--;
    }
  }

  public function tab() {
    $this->pendingWrap = false;
    $this->col = (int)($this->col / 8) * 8 + 8;
    if ($this->col > $this->cols) {
      $this->setRow($this->row + 1);
      $this->col = 0;
    }
  }

  public function setForeground($color) {
    $this->fg = $color;
  }

  public function setBackground($color) {
    $this->bg = $color;
  }

  public function setBold($bold) {
    if ($bold) {
      $this->attrs = $this->attrs | self::A_BOLD;
    } else {
      $this->attrs = $this->attrs & ~self::A_BOLD;
    }
  }

  public function isBold() {
    return ($this->attrs & self::A_BOLD) > 0;
  }

  public function cursorUp($n) {
    // DEBUG:8 echo "cursorUp {$n}";
    $this->pendingWrap = false;
    if ($this->row >= $n) {
      $this->setRow($this->row - $n);
    } else {
      $this->setRow(0);
    }
  }

  public function cursorDown($n) {
    // DEBUG:8 echo "cursorDown {$n}";
    $this->pendingWrap = false;
    if ($this->row < $this->rows - $n - 1) {
      $this->setRow($this->row + $n);
    } else {
      $this->setRow($this->rows - 1);
    }
  }

  public function cursorLeft($n) {
    // DEBUG:8 echo "cursorLeft {$n}";
    $this->pendingWrap = false;
    if ($this->col >= $n) {
      $this->col -= $n;
    } else {
      $this->col = 0;
    }
  }

  public function cursorRight($n) {
    // DEBUG:8 echo "cursorRight {$n}";
    $this->pendingWrap = false;
    if ($this->col < $this->cols - $n - 1) {
      $this->col += $n;
    } else {
      $this->col = $this->cols - 1;
    }
  }

  public function cursorPos($n, $m) {
    // DEBUG:8 echo "cursorPos {$n} {$m}";
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
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
        $this->scrollRuns = [];
        $this->scrollWrap = [];
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
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
    $n = max(1, min($n, $this->cols - $this->col));
    $i = $this->row;
    for ($j = $this->col; $j < $this->cols && $j < $this->col + $n; $j++) {
      $this->currentScreen[$i][$j] = $this->emptyCell();
    }
  }

  public function scrollUp($n) {
    // DEBUG:8 echo "scrollUp {$n}";
    $this->pendingWrap = false;
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
    $this->pendingWrap = false;
    for ($i = $this->scrollRegionEnd; $i >= $this->scrollRegionStart; $i--) {
      if ($i < $this->scrollRegionStart + $n) {
        $this->currentScreen[$i] = $this->emptyLine();
      } else {
        $this->currentScreen[$i] = $this->currentScreen[$i - $n];
      }
    }
  }

  public function scrollRegion($n, $m) {
    $this->pendingWrap = false;
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

  public function reverseIndex() {
    if ($this->row > $this->scrollRegionStart && $this->row <= $this->scrollRegionEnd) {
      $this->setRow($this->row - 1);
    } else if ($this->row == $this->scrollRegionStart) {
      $this->scrollDown(1);
    } else if ($this->row > 0) {
      $this->setRow($this->row - 1);
    }
    $this->pendingWrap = false;
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

  public function getRows($offset = false) {
    if ($this->currentScreen === $this->mainScreen) {
      $l = count($this->scrollBuffer);
      if ($offset === false) {
        $offset = $l;
      }
      $rows = [];
      if ($offset < $l) {
        $lines = array_slice($this->scrollBuffer, $offset, $this->rows);
        $rows = $this->linesToRows($lines);
      }
      $n = count($rows);
      $rows2 = [];
      if ($n < $this->rows) {
        $rows2 = array_slice($this->currentScreen, 0, $this->rows - $n);
      }
      $rows = array_merge($rows, $rows2);
      if ($this->fill) {
        $n = count($rows);
        if ($n < $this->rows) {
          for ($i = 0; $i < $this->rows - $n; $i++) {
            $rows[] = $this->emptyLine();
          }
        }
      }
      return $rows;
    } else {
      return $this->currentScreen;
    }
  }

  public function getLines() {
    if ($this->currentScreen === $this->mainScreen) {
      [$lines, $runs, $wrap] = $this->rowsToLines($this->currentScreen);
      $lines = array_merge($this->scrollBuffer, $lines);
      if ($this->fill) {
        $n = count($lines);
        if ($n < $this->rows) {
          for ($i = 0; $i < $this->rows - $n; $i++) {
            $lines[] = '';
          }
        }
      }
      return $lines;
    } else {
      [$lines, $runs, $wrap] = $this->rowsToLines($this->currentScreen);
      return $lines;
    }
  }

  public function countVisibleLines() {
    if (!$this->fill && $this->currentScreen === $this->mainScreen) {
      return min($this->rows, $this->mainHeight);
    }
    return $this->rows;
  }

  public function countLines() {
    if ($this->currentScreen === $this->mainScreen) {
      return $this->mainHeight + count($this->scrollBuffer);
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
    $this->pendingWrap = false;
    $this->row = $row;
    if ($this->currentScreen === $this->mainScreen) {
      $this->mainHeight = max($row + 1, $this->mainHeight);
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
    $this->cols = $cols;
  }


  public function getRowCount() {
    return $this->rows;
  }

  public function getColCount() {
    return $this->cols - 1;
  }

  public function pushToScrollBuffer($row) {
    [$lines, $runs, $wrap] = $this->rowsToLines([$row]);
    $this->scrollBuffer[] = $lines[0];
    $this->scrollRuns[] = $runs[0];
    $this->scrollWrap[] = $wrap[0];
  }

  protected function rowsToLines($rows) {
    $lines = [];
    $runs = [];
    $wrap = [];
    foreach ($rows as $row) {
      $line = '';
      foreach ($row as $cell) {
        $line .= $cell[self::GLYPH];
      }
      $lines[] = rtrim($line, ' ');
      $runs[] = '';
      $wrap[] = false;
    }
    return [$lines, $runs, $wrap];
  }

  protected function linesToRows($lines) {
    $rows = [];
    foreach ($lines as $line) {
      $chars = mb_str_split(mb_str_pad($line, $this->cols));
      $row = [];
      foreach ($chars as $char) {
        $row[] = [
          self::GLYPH => $char,
          self::BG => 0x000000,
          self::FG => 0xffffff,
          self::ATTR =>  0
        ];
      }
      $rows[] = $row;
    }
    return $rows;
  }

}
