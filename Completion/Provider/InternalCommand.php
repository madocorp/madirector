<?php

namespace MADIR\Completion\Provider;

class InternalCommand implements \MADIR\Completion\Provider {

  protected $commands = [
    'cd', 'set', 'alias', 'session', 'help', 'exit'
  ];

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    foreach ($this->commands as $command) {
      $score = 0;
      if ($command === $argv[0]) {
        $score += 100;
      } else if (strpos($command, $argv[0]) === 0) {
        $score += 80;
      } else if (stripos($command, $argv[0]) === 0) {
        $score += 70;
      } else if (str_contains($command, $argv[0])) {
        $score += 30;
      } else {
        continue;
      }
      $candidates[] = ['score' => $score, 'length' => mb_strlen($command), 'value' => $command];
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

}
