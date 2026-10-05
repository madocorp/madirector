<?php

namespace MADIR\Command;

/** Tokenize shell operators, variables, words, and quoted arguments for CommandParser. */
final class CommandTokenizer {

  /** Return the token records expected by the existing command parser. */
  public static function start(array $lines, string $context = ''): array {
    $result = [];
    foreach ($lines as $line) {
      $tokens = [];
      $length = strlen($line);
      for ($offset = 0; $offset < $length;) {
        $rest = substr($line, $offset);
        if ($rest[0] === '"') {
          $tokens[] = ['type' => 'QUOTED_ARGV', 'value' => '"'];
          $offset++;
          while ($offset < $length && $line[$offset] !== '"') {
            if ($line[$offset] === '\\' && $offset + 1 < $length) {
              $tokens[] = ['type' => 'QARGV', 'value' => substr($line, $offset, 2)];
              $offset += 2;
            } else {
              $span = strcspn($line, '\\"', $offset);
              $tokens[] = ['type' => 'QARGV', 'value' => substr($line, $offset, $span)];
              $offset += $span;
            }
          }
          if ($offset < $length) {
            $tokens[] = ['type' => 'QUOTED_ARGV', 'value' => '"'];
            $offset++;
          }
          continue;
        }
        $matched = false;
        foreach (self::rules() as [$type, $pattern]) {
          if (preg_match($pattern, $rest, $match) !== 1) {
            continue;
          }
          $value = $match[0];
          $tokens[] = ['type' => $type, 'value' => $value];
          $offset += strlen($value);
          $matched = true;
          break;
        }
        if (!$matched) {
          $tokens[] = ['type' => 'UNKNOWN', 'value' => $rest[0]];
          $offset++;
        }
      }
      $result[] = ['tokens' => $tokens];
    }
    return $result;
  }

  /** Match operators before shorter words and single whitespace characters. */
  private static function rules(): array {
    return [
      ['AND', '/^&&/'],
      ['OR', '/^\|\|/'],
      ['COMMAND_SEPARATOR', '/^;/'],
      ['PARALLEL_SEPARATOR', '/^&/'],
      ['PIPE', '/^\|/'],
      ['REDIRECT_APPEND', '/^>>/'],
      ['REDIRECT_STDERR_STDOUT', '/^2>1/'],
      ['REDIRECT_STDERR', '/^2>/'],
      ['REDIRECT', '/^>/'],
      ['REDIRECT_INPUT', '/^</'],
      ['WHITESPACE', '/^\s/'],
      ['VARIABLE', '/^\$[A-Za-z_][A-Za-z0-9_]*/'],
      ['ARGV', '/^[^"&|;><\s]+/'],
    ];
  }

}
