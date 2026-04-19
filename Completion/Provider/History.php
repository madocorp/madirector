<?php

namespace MADIR\Completion\Provider;

class History implements \MADIR\Completion\Provider {

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    $lastArgv = end($argv);
    $search = substr($lastArgv, 1);
    $historyList = $session->getHistory();
    foreach ($historyList as $command) {
      $score = 0;
      if ($command === $search) {
        $score += 100;
      } else if (strpos($command, $search) === 0) {
        $score += 80;
      } else if (stripos($command, $search) === 0) {
        $score += 70;
      } else if (str_contains($command, $search)) {
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
