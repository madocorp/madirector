<?php

namespace MADIR\Command;

class Search {

  private $hits = [];
  private $current = 0;
  private $buffer;
  private $cursor;

  public function __construct(\MADIR\Screen\ScreenBuffer $buffer, \SPTK\Elements\TextEditor\Cursor $cursor) {
    $this->buffer = $buffer;
    $this->cursor = $cursor;
  }

  public function find(string $expression, bool $caseSensitive, bool $regexp): int {
    $this->hits = [];
    $lines = $this->buffer->getLines();
    if ($regexp) {
      if (@preg_match($expression, '') === false) {
        $delimiter = '/';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $expression);
        $modifiers = $caseSensitive ? 'u' : 'ui';
        $expression = $delimiter . $escaped . $delimiter . $modifiers;
      } else if (!$caseSensitive && substr($expression, -1) !== 'i') {
        $expression .= 'i';
      }
    } else {
      $len = mb_strlen($expression);
    }
    $matches = 0;
    foreach ($lines as $i => $line) {
      if ($regexp) {
        if (@preg_match_all($expression, $line, $m, PREG_OFFSET_CAPTURE)) {
          foreach ($m[0] as $mm) {
            $this->hits[] = [$i, $mm[1], $i, $mm[1] + mb_strlen($mm[0]) - 1];
            $matches++;
          }
        }
      } else {
        $offset = 0;
        $p = true;
        while ($p !== false) {
          if ($caseSensitive) {
            $p = mb_strpos($line, $expression, $offset);
          } else {
            $p = mb_stripos($line, $expression, $offset);
          }
          if ($p !== false) {
            $this->hits[] = [$i, $p, $i, $p + $len - 1];
            $matches++;
            $offset = $p + $len;
          }
        }
      }
    }
    if ($matches === 0) {
      $this->cursor->set([0, 0, 0, 0]);
      $this->cursor->save();
    } else {
      $this->current();
    }
    return $matches;
  }

  public function current(): void {
    if (!isset($this->hits[$this->current])) {
      return;
    }
    $selection = $this->hits[$this->current];
    $this->cursor->set($selection);
    $this->cursor->save();
  }

  public function next(): void {
    $this->current++;
    if ($this->current > count($this->hits) - 1) {
      $this->current = 0;
    }
    if (!isset($this->hits[$this->current])) {
      return;
    }
    $selection = $this->hits[$this->current];
    $this->cursor->set($selection);
    $this->cursor->save();
  }

  public function previous(): void {
    $this->current--;
    if ($this->current < 0) {
      $this->current = count($this->hits) - 1;
    }
    if (!isset($this->hits[$this->current])) {
      return;
    }
    $selection = $this->hits[$this->current];
    $this->cursor->set($selection);
    $this->cursor->save();
  }

}
