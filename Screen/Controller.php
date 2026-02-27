<?php

namespace MADIR\Screen;

class Controller {

  private static $inputElement = false;
  private static $commandGap = 10;

  public static function init() {
    cli_set_process_title('MADIR');
    new \MADIR\Command\Session();
    self::newCommand(self::$commandGap);
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
          self::runCommand();
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

  public static function newCommand($y) {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, 'newCommand', false, 'CommandBlock');
    $block->addClass('active', true);
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(getcwd());
    $cmd = new \SPTK\Element($block, false, 'new', 'Command');
    $label = new \SPTK\Element($cmd, false, 'prompt', 'Label');
    $label->setText('$');
    $input = new \SPTK\Elements\Input($label, false, 'cmd');
    $input->addClass('active', true);
    if (self::$inputElement === false) {
      self::$inputElement = $input;
    }
    $style = $block->getStyle();
    $style->set('y', "-{$y}px");
    $block->recalculateGeometry();
    $geometry = $block->getGeometry();
    return $y + $geometry->height + self::$commandGap;
  }

  public static function addCommand($command, $y, $active) {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, false, false, 'CommandBlock');
    if ($active) {
      $block->addClass('active', true);
    }
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(getcwd());
    $status = new \SPTK\Element($info, false, false, 'CommandStatus');
    $status->setText($command->getStatusString());
    $cmd = new \SPTK\Element($block, false, $command->returnValue === false ? 'run' : 'done', 'Command');
    $cmd->setText('$ ' . $command->command);
    $terminal = new Terminal($block);
    $terminal->setBuffer($command->screenBuffer);
    $terminal->setInputCallback([$command, 'input']);
    if ($command->grab) {
      $terminal->grabInput();
      $block->addClass('grab', true);
    } else if ($command->scroll) {
      $terminal->scrollOn();
      $block->addClass('scroll', true);
    }
    $style = $block->getStyle();
    $style->set('y', "-{$y}px");
    if (self::$inputElement === false) {
      self::$inputElement = $terminal;
    }
    $block->recalculateGeometry();
    $geometry = $block->getGeometry();
    return $y + $geometry->height + self::$commandGap;
  }

  public static function runCommand() {
    if (self::$inputElement === false) {
      return;
    }
    $command = self::$inputElement->getValue();
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
    self::$inputElement = false;
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
    if (self::$inputElement !== false) {
      self::$inputElement->raise();
    }
  }

}
