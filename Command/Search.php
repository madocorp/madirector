<?php

namespace MADIR\Command;

class Search {

  private $hits = [];
  private $current = 0;
  private $buffer;

  public function __construct(\MADIR\Screen\ScreenBuffer $buffer) {
    $this->buffer = $buffer;
  }

  public function find(string $expression, bool $caseInsensitive, bool $regexp, bool $backwards): int {
    $this->hits = [];
    $lines = $this->buffer->getLines();
    $len = mb_strlen($expression);
    $matches = 0;
    foreach ($lines as $i => $line) {
      $offset = 0;
      $p = true;
      while ($p !== false) {
        $p = mb_strpos($line, $expression, $offset);
        if ($p !== false) {
          $this->hits[] = [$i, $p, $i, $p + $len];
          $matches++;
          $offset = $p + $len;
        }
      }
    }
    return $matches;
  }

  public function next(): array {
    $this->current++;
    if ($this->current >= count($this->hits)) {
      $this->current = 0;
    }
    if (!isset($this->hits[$this->current])) {
      return [];
    }
    return $this->hits[$this->current];
  }

  public function previous(): array {
    $this->current--;
    if ($this->current < 0) {
      $this->current = count($this->hits) - 1;
    }
    if (!isset($this->hits[$this->current])) {
      return [];
    }
    return $this->hits[$this->current];
  }

}
