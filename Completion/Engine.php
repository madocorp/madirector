<?php

namespace MADIR\Completion;

/** Route command-line completion requests to the providers. */
class Engine {

  const LIST_LIMIT = 30;

  /** Gather matches for the command line. */
  public static function suggest(string $line, int $cursor, \MADIR\App\CommandSession $session): array {
    $before = \SPTK\Rendering\TextMetrics::slice($line, 0, $cursor);
    try {
      $argv = self::parseArgvText($before, $session);
    } catch (\Throwable) {
      return ['prefix' => '', 'groups' => [], 'total' => 0];
    }
    if ($argv === []) {
      return ['prefix' => '', 'groups' => [], 'total' => 0];
    }
    $prefix = (string)end($argv);
    if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*$/', $before, $match)) {
      $prefix = $match[0];
      $argv[count($argv) - 1] = $prefix;
    }
    $providers = self::route($argv);
    $groups = [];
    $total = 0;
    foreach ($providers as $name) {
      $class = "\\MADIR\\Completion\\Provider\\{$name}";
      $matches = array_values(array_unique((new $class)->getCandidates($argv, $session)));
      if ($matches !== []) {
        $total += count($matches);
        $groups[$name] = array_slice($matches, 0, self::LIST_LIMIT);
      }
    }
    return ['prefix' => $prefix, 'groups' => $groups, 'total' => $total];
  }

  private static function parseArgvText(string $commandString, object $session): array {
    if ($commandString === '') {
      return [];
    }
    $parser = new \MADIR\Command\CommandParser($session, false);
    $parsedCommands = $parser->parse($commandString);
    if ($parsedCommands === []) {
      return [];
    }
    $lastCommand = end($parsedCommands);
    $lastSequence = $lastCommand['sequence'];
    if ($lastSequence === []) {
      return [];
    }
    $lastSequence = end($lastSequence);
    $lastPipeline = $lastSequence['pipeline'];
    if ($lastPipeline === []) {
      return [];
    }
    $lastPipeline = end($lastPipeline);
    $lastArgv = $lastPipeline['argv'];
    if (str_ends_with($commandString, ' ') && ($lastArgv === [] || !str_ends_with((string)end($lastArgv), ' '))) {
      $lastArgv[] = '';
    }
    return $lastArgv;
  }

  protected static function route(array &$argv): array {
    $lastArgv = end($argv);
    $providers = [];
    if (empty($argv)) {
      return $providers;
    }
    if (substr($lastArgv, 0, 1) === '$') {
      $providers[] = 'Variable';
      $providers[] = 'Env';
      return $providers;
    }
    if (count($argv) === 1) {
      $providers[] = 'Command';
      $providers[] = 'InternalCommand';
      $providers[] = 'Alias';
      $providers[] = 'History';
      return $providers;
    }
    if ($argv[0] === 's' || $argv[0] === 'session') {
      $providers[] = 'Session';
      return $providers;
    }
    if ($argv[0] === 'help') {
      $providers[] = 'Help';
      return $providers;
    }
    if ($argv[0] === 'exit' && count($argv) > 2 && $argv[count($argv) - 2] === '-s') {
      $providers[] = 'Session';
      return $providers;
    }
    if ($argv[0] === 'cd') {
      $argv = self::mergeArgv($argv);
      $providers[] = 'Directory';
      return $providers;
    }
    if ($argv[0] === 'open') {
      $providers[] = 'File';
      $providers[] = 'Directory';
      return $providers;
    }
    if (count($argv) > 1) {
      $providers[] = 'File';
      $providers[] = 'Directory';
    }
    return $providers;
  }

  protected static function mergeArgv(array $argv): array {
    $tmpArgv = [$argv[0]];
    unset($argv[0]);
    $tmpArgv[] = implode(' ', $argv);
    return $tmpArgv;
  }

}
