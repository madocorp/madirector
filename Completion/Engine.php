<?php

namespace MADIR\Completion;

/**
 * It manages the completion process.
 * The process is: tokenize the command, find providers, show the results, do the replace.
 */
class Engine {

  const LIST_LIMIT = 30;

  protected static $display = false;
  protected static $selectedGroup = 0;
  protected static $lastArg;

  /** Reuse the parked provider routing for the current SPTK command line. */
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

  public static function complete(\MADIR\Command\Command $command): void {
    if (self::$display) {
      self::hideWindow();
      return;
    }
    $argv = self::parseArgv($command);
    $providers = self::route($argv);
    self::$lastArg = end($argv);
    $results = [];
    $n = 0;
    foreach ($providers as $providerName) {
      $providerClass = "\\MADIR\\Completion\\Provider\\{$providerName}";
      $provider = new $providerClass;
      $result = $provider->getCandidates($argv, $command->session);
      $result = array_unique($result);
      if (!empty($result)) {
        $count = count($result);
        $n += $count;
        $results[$providerName] = array_slice($result, 0, self::LIST_LIMIT);
        if ($count > self::LIST_LIMIT) {
          $results[$providerName]['overflow'] = '+' . ($count - self::LIST_LIMIT) . ' items';
        }
      }
    }
    if ($n === 1) {
      $res = reset($results);
      $replace = reset($res);
      self::replace($command, $replace);
    } else {
      self::showWindow($results);
    }
  }

  protected static function showWindow(array $results): void {
    self::$display = true;
    self::$selectedGroup = 0;
    $window = \SPTK\Element::firstByType('Window');
    $completionWindow = \SPTK\Element::firstByType('CompletionWindow', $window);
    if ($completionWindow === false) {
      $completionWindow = new \SPTK\Element($window, null, null, 'CompletionWindow');
      $completionWindow->addEvent('KeyPress', '\\MADIR\\Completion\\Engine::keyPressHandler');
      $style = $completionWindow->getStyle();
      $y = \MADIR\Screen\Controller::$sizes['commandHeight'];
      $style->set('y', "-{$y}px");
    } else {
      $completionWindow->clear();
    }
    if (empty($results)) {
      $completionWindow->setText('No matches');
      return;
    }
    $firstBox = false;
    foreach ($results as $type => $candidates) {
      $container = new \SPTK\Element($completionWindow, null, null, 'ListBoxContainer');
      $overflow = '';
      if (isset($candidates['overflow'])) {
        $overflow = $candidates['overflow'];
        unset($candidates['overflow']);
      }
      $container->setText("{$type}:\n");
      $listBox = new \SPTK\Elements\ListBox($container, null, 'completion-list');
      if ($firstBox === false) {
        $firstBox = $listBox;
      }
      foreach ($candidates as $candidate) {
        $item = new \SPTK\Elements\ListItem($listBox);
        $item->setValue($candidate);
      }
      $container->addText("\n$overflow");
    }
    $firstBox->activateItem();
    $firstBox->raise();
  }

  public static function hideWindow(): void {
    if (self::$display === false) {
      return;
    }
    self::$display = false;
    $completionWindow = \SPTK\Element::firstByType('CompletionWindow');
    if ($completionWindow !== false) {
      $completionWindow->remove();
    }
  }

  public static function selectGroup(int $next): bool {
    if (self::$display === false) {
      return false;
    }
    $completionWindow = \SPTK\Element::firstByType('CompletionWindow');
    $groups = \SPTK\Element::allByType('ListBox', $completionWindow);
    if (empty($groups)) {
      self::hideWindow();
      return true;
    }
    $groups[self::$selectedGroup]->inactivateItem();
    self::$selectedGroup += $next;
    if (self::$selectedGroup >= count($groups)) {
      self::$selectedGroup = 0;
    }
    if (self::$selectedGroup < 0) {
      self::$selectedGroup = count($groups) - 1;
    }
    $groups[self::$selectedGroup]->activateItem();
    $groups[self::$selectedGroup]->raise();
    return true;
  }

  public static function replace(\MADIR\Command\Command $command, ?string $replace = null): bool {
    $value = $command->getValue();
    $cursor = $command->getCursorPos();
    $before = substr($value, 0, $cursor - mb_strlen(self::$lastArg));
    $after = substr($value, $cursor);
    if ($replace === null) {
      $completionWindow = \SPTK\Element::firstByType('CompletionWindow');
      $groups = \SPTK\Element::allByType('ListBox', $completionWindow);
      if (empty($groups)) {
        if (self::$display === false) {
          return false;
        }
        self::hideWindow();
        return true;
      }
      $replace = $groups[self::$selectedGroup]->getValue() ;
    }
    $command->setValue($before . $replace . $after);
    if (self::$display === false) {
      return false;
    }
    self::hideWindow();
    return true;
  }

  protected static function parseArgv(\MADIR\Command\Command $command): array {
    return self::parseArgvText($command->getValueTillCursor(), $command->session);
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

  public static function keyPressHandler($element, $event) {
    $keyCombo = \SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    switch ($keyCombo) {
      case \SPTK\SDLWrapper\Action::MOVE_LEFT:
        self::selectGroup(-1);
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_RIGHT:
        self::selectGroup(1);
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::DO_IT:
        $session = \MADIR\Command\Session::getCurrent();
        $command = $session->currentCommand();
        self::replace($command);
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::SWITCH_NEXT:
        self::hideWindow();
        \SPTK\Element::refresh();
        return true;
    }
    self::hideWindow();
    \SPTK\Element::refresh();
    return false;
  }

}
