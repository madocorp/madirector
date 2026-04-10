<?php

namespace MADIR\Completion\Provider;

class Session implements \MADIR\Completion\Provider {

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    $search = end($argv);
    $sessionList = $session->getSessionList();
    foreach ($sessionList as $sessionName) {
      $score = 0;
      if ($sessionName === $search) {
        $score += 100;
      } else if (strpos($sessionName, $search) === 0) {
        $score += 80;
      } else if (stripos($sessionName, $search) === 0) {
        $score += 70;
      } else {
        continue;
      }
      $candidates[] = ['score' => $score, 'length' => mb_strlen($sessionName), 'value' => $sessionName];
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
