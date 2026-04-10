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
      $completionWindow = new \SPTK\Element($window, false, false, 'CompletionWindow');
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
      $container = new \SPTK\Element($completionWindow, false, false, 'ListBoxContainer');
      $overflow = '';
      if (isset($candidates['overflow'])) {
        $overflow = $candidates['overflow'];
        unset($candidates['overflow']);
      }
      $container->setText("{$type}:\n");
      $listBox = new \SPTK\Elements\ListBox($container, false, 'completion-list');
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
    $commandString = $command->getValueTillCursor();
    if (empty($commandString)) {
      return [];
    }
    $parser = new \MADIR\Command\CommandParser($command->session, false);
    $parsedCommands = $parser->parse($commandString);
    $lastCommand = end($parsedCommands);
    $lastSequence = $lastCommand['sequence'];
    $lastSequence = end($lastSequence);
    $lastPipeline = $lastSequence['pipeline'];
    $lastPipeline = end($lastPipeline);
    $lastArgv = $lastPipeline['argv'];
    if (substr($commandString, -1) === ' ' && substr(end($lastArgv), -1) !== ' ') {
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
      return $providers;
    }
    if ($argv[0] === 's' || $argv[0] === 'session') {
      $providers[] = 'Session';
      return $providers;
    }
    if ($argv[0] === 'cd') {
      $argv = self::mergeArgv($argv);
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
