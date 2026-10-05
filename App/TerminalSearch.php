<?php

namespace MADIR\App;

use SPTK\Rendering\TextMetrics;

/** Locate literal matches in terminal document rows. */
final class TerminalSearch {

  private array $hits = [];
  private int $position = 0;

  public function __construct(public readonly string $pattern, array $lines) {
    if ($pattern === '') {
      return;
    }
    $length = mb_strlen($pattern);
    foreach ($lines as $row => $line) {
      $offset = 0;
      while (($column = mb_stripos($line, $pattern, $offset)) !== false) {
        $start = TextMetrics::length(mb_substr($line, 0, $column));
        $end = $start + TextMetrics::length(mb_substr($line, $column, $length)) - 1;
        $this->hits[] = [$row, $start, $end];
        $offset = $column + $length;
      }
    }
  }

  public function count(): int {
    return count($this->hits);
  }

  public function current(): ?array {
    return $this->hits[$this->position] ?? null;
  }

  public function index(): int {
    return $this->position;
  }

  public function move(int $step): ?array {
    if ($this->hits === []) {
      return null;
    }
    $this->position = ($this->position + $step + count($this->hits)) % count($this->hits);
    return $this->current();
  }

}
