<?php

namespace MADIR\Completion\Provider;

class Env implements \MADIR\Completion\Provider {

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    $lastArgv = end($argv);
    $search = substr($lastArgv, 1);
    foreach ($session->getEnvList() as $var) {
      $score = 0;
      if ($var === $search) {
        $score += 100;
      } else if (strpos($var, $search) === 0) {
        $score += 80;
      } else if (stripos($var, $search) === 0) {
        $score += 70;
      } else if (str_contains($var, $search)) {
        $score += 30;
      } else {
        continue;
      }
      $candidates[] = ['score' => $score, 'length' => mb_strlen($var), 'value' => '$' . $var];
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
