<?php

namespace MADIR\Completion;

interface Provider {

  public function getCandidates(array $argv, \MADIR\Command\Session|\MADIR\App\CommandSession $session): array;

}
