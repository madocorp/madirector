<?php

namespace MADIR\Completion;

interface Provider {

  public function getCandidates(array $argv, \MADIR\App\CommandSession $session): array;

}
