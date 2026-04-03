<?php

namespace MADIR\Completion\Provider;

class Command implements \MADIR\Completion\Provider {

  protected static $commands = [];
  protected static $dirs = [];

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    self::cacheCommands($session);
    $candidates = [];
    $longEnough = mb_strlen($argv[0]) > 3;
    foreach (self::$commands as $dir => $commands) {
      foreach ($commands as $command) {
        $score = 0;
        if ($command === $argv[0]) {
          $score += 100;
        } else if (strpos($command, $argv[0]) === 0) {
          $score += 80;
        } else if (stripos($command, $argv[0]) === 0) {
          $score += 70;
        } else if ($longEnough && str_contains($command, $argv[0])) {
          $score += 30;
        } else {
          continue;
        }
        $candidates[] = ['score' => $score, 'length' => mb_strlen($command), 'value' => $command];
      }
    }
    usort($candidates, [$this, 'sortCandidates']);
    $candidates = array_column($candidates, 'value');
    return $candidates;
  }

  public function sortCandidates(array $a, array $b): int {
    return [
      $b['score'],
      $a['length'],
      $a['value'],
    ] <=> [
      $a['score'],
      $b['length'],
      $b['value'],
    ];
  }

  protected static function cacheCommands($session) {
    $path = $session->getVar('$PATH');
    $dirs = explode(':', $path);
    $dirs = array_flip($dirs);
    foreach (self::$dirs as $dir => $mtime) {
      if (!isset($dirs[$dir])) {
        unset(self::$dirs[$dir]);
        unset(self::$commands[$dir]);
      }
    }
    foreach ($dirs as $dir => $dummy) {
      if (!isset(self::$dirs[$dir])) {
        self::$dirs[$dir] = 0;
        self::$commands[$dir] = [];
      }
    }
    foreach (self::$dirs as $dir => $mtime) {
      $mtimeNow = filemtime($dir);
      if ($mtime < $mtimeNow) {
        if (!is_dir($dir)) {
          continue;
        }
        self::$commands[$dir] = [];
        foreach (scandir($dir, SCANDIR_SORT_NONE) as $file) {
          $full = $dir . '/' . $file;
          if (is_file($full) && is_executable($full)) {
            self::$commands[$dir][] = $file;
          }
        }
      }
    }
  }

}
