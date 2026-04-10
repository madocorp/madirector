<?php

namespace MADIR\Completion\Provider;

class Alias implements \MADIR\Completion\Provider {

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    $lastArgv = end($argv);
    $search = substr($lastArgv, 1);
    $aliasList = $session->getAliasList();
    foreach (array_keys($aliasList) as $alias) {
      $score = 0;
      if ($alias === $search) {
        $score += 100;
      } else if (strpos($alias, $search) === 0) {
        $score += 80;
      } else if (stripos($alias, $search) === 0) {
        $score += 70;
      } else if (str_contains($alias, $search)) {
        $score += 30;
      } else {
        continue;
      }
      $candidates[] = ['score' => $score, 'length' => mb_strlen($alias), 'value' => $alias];
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
