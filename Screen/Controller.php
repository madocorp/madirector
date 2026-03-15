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
    $session = \MADIR\Command\Session::getCurrent();
    $command = $session->currentCommand();
    switch (\SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
      case \SPTK\SDLWrapper\Action::CLOSE:
        exit(0);
      case \SPTK\SDLWrapper\KeyCode::F12:
        if (!$command->isNew()) {
          $command->toggleGrab();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::DO_IT:
        if ($command->isNew()) {
          self::runCommand($command);
        } else {
          $command->toggleScroll();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::PAGE_UP:
        $session->previousCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::PAGE_DOWN:
        $session->nextCommand();
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_UP:
        if ($command->isNew()) {
          $session->history(1);
          \SPTK\Element::refresh();
          return true;
        }
        return false;
      case \SPTK\SDLWrapper\Action::MOVE_DOWN:
        if ($command->isNew()) {
          $session->history(-1);
          \SPTK\Element::refresh();
          return true;
        }
        return false;
    }
  }

  public static function runCommand($command) {
    $commandString = $command->getValue();
    $session = \MADIR\Command\Session::getCurrent();
    $parser = new \MADIR\Command\CommandParser($session);
    $parsedCommands = $parser->parse($commandString);
    $command->setValue('');
    foreach ($parsedCommands as $parsedCommand) {
      $session->runCommand($parsedCommand);
    }
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
    $commands[0]->raise();
  }

}
