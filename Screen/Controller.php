<?php

namespace MADIR\Screen;

class Controller {

  private static $newInput = true;
  private static $commandGap = 10;

  public static function init() {
    cli_set_process_title('MADIR');
    new \MADIR\Command\Session();
    self::newCommand(self::$commandGap);
  }

  public static function keyPressHandler($element, $event) {
    switch (\SPTK\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
      case \SPTK\Action::CLOSE:
        exit(0);
      case \SPTK\Action::DO_IT:
        self::runCommand();
        return true;
      case \SPTK\Action::PAGE_UP:
        $session = \MADIR\Command\Session::getCurrent();
        $session->previousCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\Action::PAGE_DOWN:
        $session = \MADIR\Command\Session::getCurrent();
        $session->nextCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
    }
  }

  public static function newCommand($y) {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, 'newCommand', 'active', 'CommandBlock');
    $style = $block->getStyle();
    $style->set('y', "-{$y}px");
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(getcwd());
    $cmd = new \SPTK\Element($block, false, 'new', 'Command');
    $label = new \SPTK\Element($cmd, false, 'prompt', 'Label');
    $label->setText('$');
    self::$newInput = new \SPTK\Input($label, false, 'cmd');
    self::$newInput->addClass('active', true);
    self::$newInput->raise();
    $block->recalculateGeometry();
    $geometry = $block->getGeometry();
    return $y + $geometry->height + self::$commandGap;
  }

  public static function addCommand($command, $y, $active) {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, false, $active ? 'active' : false, 'CommandBlock');
    $style = $block->getStyle();
    $style->set('y', "-{$y}px");
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(getcwd());
    $cmd = new \SPTK\Element($block, false, $command->returnValue === false ? 'run' : 'done', 'Command');
    $cmd->setText('$ ' . $command->command);
    $result = new \SPTK\Terminal($block);
    $result->setBuffer($command->screenBuffer);
    $result->setInputCallback([$command, 'input']);
    $block->recalculateGeometry();
    $geometry = $block->getGeometry();
    return $y + $geometry->height + self::$commandGap;
  }

  public static function runCommand() {
    if (self::$newInput === false) {
      return;
    }
    $command = self::$newInput->getValue();
    self::$newInput = false;
    $session = \MADIR\Command\Session::getCurrent();
    $session->runCommand($command);
    self::listCommands();
    \SPTK\Element::refresh();
  }

  public static function listCommands() {
    $window = \SPTK\Element::firstByType('Window');
    $window->clear();
    $geometry = $window->getGeometry();
    $session = \MADIR\Command\Session::getCurrent();
    $commands = $session->getVisibleCommands();
    self::$newInput = false;
    $y = self::$commandGap;
    foreach ($commands as $i => $command) {
      if ($command->isNew()) {
        $y = self::newCommand($y);
      } else {
        $y = self::addCommand($command, $y, $i === 0);
      }
      if ($y > $geometry->height) {
        break;
      }
    }
    if (self::$newInput !== false) {
      self::$newInput->raise();
    }
  }

}
