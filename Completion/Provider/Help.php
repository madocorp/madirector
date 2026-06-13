<?php

namespace MADIR\Completion\Provider;

class Help implements \MADIR\Completion\Provider {

  protected $topics = [
    'about', 'key', 'command'
  ];

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $argument = end($argv);
    return array_values(array_filter($this->topics, function ($topic) use ($argument) {
      return strpos($topic, $argument) === 0;
    }));
  }

}

