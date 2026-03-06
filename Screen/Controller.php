<?php

namespace MADIR\Screen;

class Controller {

  private static $commandGap = 10;

  public static function init() {
    cli_set_process_title('MADIR');
    new \MADIR\Command\Session();
    self::ListCommands();
  }

  public static function keyPressHandler($element, $event) {
    switch (\SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
      case \SPTK\SDLWrapper\Action::CLOSE:
        exit(0);
      case \SPTK\SDLWrapper\KeyCode::F12:
        $session = \MADIR\Command\Session::getCurrent();
        $command = $session->currentCommand();
        if (!$command->isNew()) {
          $command->toggleGrab();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::DO_IT:
        $session = \MADIR\Command\Session::getCurrent();
        $command = $session->currentCommand();
        if ($command->isNew()) {
          self::runCommand($command);
        } else {
          $command->toggleScroll();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::PAGE_UP:
        $session = \MADIR\Command\Session::getCurrent();
        $session->previousCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::PAGE_DOWN:
        $session = \MADIR\Command\Session::getCurrent();
        $session->nextCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
    }
  }

  public static function runCommand($command) {
    $inputElement = $command->getInputElement();
    $commandString = $inputElement->getValue();
    $inputElement->setValue('');
    $session = \MADIR\Command\Session::getCurrent();
    $session->runCommand($commandString);
    self::listCommands();
    \SPTK\Element::refresh();
  }

  public static function listCommands() {
    $window = \SPTK\Element::firstByType('Window');
    $window->clear();
    $geometry = $window->getGeometry();
    $session = \MADIR\Command\Session::getCurrent();
    $commands = $session->getVisibleCommands();
    $y = self::$commandGap;
    foreach ($commands as $i => $command) {
      if ($i === 0) {
        $command->activate();
      } else {
        $command->inactivate();
      }
      $window->addDescendant($command->box);
      $command->setPos($y);
      $y += $command->getHeight() + self::$commandGap;
      if ($y > $geometry->height) {
        break;
      }
    }
    $commands[0]->getInputElement()->raise();
  }

}
