<?php

namespace MADIR\Command;

class HelpFormatter {

  public static function row(string $example, string $description, int $column = 28, int $width = 80): string {
    $indent = '  ';
    $descriptionIndent = $indent . str_repeat(' ', $column);
    $descriptionWidth = max(20, $width - strlen($descriptionIndent));
    $parts = explode("\n", wordwrap($description, $descriptionWidth, "\n", false));
    $first = array_shift($parts) ?? '';
    $padding = str_repeat(' ', max(2, $column - mb_strlen($example)));
    $line = $indent . "\e[1;37m{$example}\e[0m" . $padding . $first . "\n";
    foreach ($parts as $part) {
      $line .= $descriptionIndent . $part . "\n";
    }
    return $line;
  }

  public static function rows(array $rows, int $column = 28, int $width = 80): string {
    $help = '';
    foreach ($rows as $row) {
      $help .= self::row($row[0], $row[1], $row[2] ?? $column, $width);
    }
    return $help;
  }

}
