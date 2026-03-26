<?php

namespace MADIR\Screen;

class Controller {

  public static $sizes = [];
  protected static $grabbedBeforeZoom = false;
  protected static $scrolledBeforeZoom = false;

  public static function init() {
    cli_set_process_title('MADIR');
    new \MADIR\Command\Session();
    self::measureSize();
    self::ListCommands();
  }

  public static function keyPressHandler($element, $event) {
    $session = \MADIR\Command\Session::getCurrent();
    $command = $session->currentCommand();
    switch (\SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
      case \SPTK\SDLWrapper\Action::CLOSE:
        if ($command->isScrolled()) {
          $command->toggleScroll(false);
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\KeyCode::F12:
        if ($command->isNew()) {
          return true;
        }
        if ($event['mod'] & \SPTK\SDLWrapper\KeyModifier::SHIFT) {
          if ($command->isGrabbed()) {
            $command->toggleGrab(false);
            $command->toggleScroll(true);
          } else if ($command->isRunning()) {
            $command->toggleGrab(true);
            $command->toggleScroll(false);
          }
        } else if ($command->isZoomed()) {
          $command->toggleZoom(false);
          $command->toggleGrab(false);
          $command->toggleScroll(false);
        } else if ($command->isGrabbed()) {
          $command->toggleGrab(false);
        } else if ($command->isRunning()) {
          $command->toggleScroll(false);
          $command->toggleGrab(true);
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\KeyCode::F11:
        if ($command->isNew()) {
          return true;
        }
        if ($command->isZoomed()) {
          $command->toggleZoom(false);
          if ($command->isRunning()) {
            $command->toggleGrab(self::$grabbedBeforeZoom);
          }
          $command->toggleScroll(self::$scrolledBeforeZoom);
        } else {
          $command->toggleZoom(true);
          self::$scrolledBeforeZoom = $command->isScrolled();
          self::$grabbedBeforeZoom = $command->isGrabbed();
          if (!$command->isScrolled() && !$command->isGrabbed()) {
            if ($command->isRunning()) {
              $command->toggleGrab(true);
            } else {
              $command->toggleScroll(true);
            }
          }
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::DO_IT:
        if ($command->isNew()) {
          self::runCommand($command);
          self::listCommands();
          \SPTK\Element::refresh();
          return true;
        }
        $command->toggleScroll();
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
        } else {
          $session->moveGroupCursor(0, -1);
          self::listCommands();
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_DOWN:
        if ($command->isNew()) {
          $session->history(-1);
        } else {
          $session->moveGroupCursor(0, 1);
          self::listCommands();
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_LEFT:
        if ($command->isNew()) {
          return false;
        }
        $session->moveGroupCursor(-1, 0);
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_RIGHT:
        if ($command->isNew()) {
          return false;
        }
        $session->moveGroupCursor(1, 0);
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
    }
  }

  private static function getBoxSize($n) {
    if ($n > 4) {
      return 3;
    }
    if ($n > 1) {
      return 2;
    }
    return 1;
  }

  public static function runCommand($command) {
    $commandString = $command->getValue();
    $session = \MADIR\Command\Session::getCurrent();
    $parser = new \MADIR\Command\CommandParser($session);
    $parsedCommands = $parser->parse($commandString);
    $command->setValue('');
    $n = count($parsedCommands);
    $boxSize = self::getBoxSize($n);
    $group = $n;
    foreach ($parsedCommands as $parsedCommand) {
      $session->runCommand($parsedCommand, $group, $boxSize);
    }
  }

  public static function listCommands() {
    $window = \SPTK\Element::firstByType('Window');
    $window->clear();
    $wgeometry = $window->getGeometry();
    $session = \MADIR\Command\Session::getCurrent();
    $command = $session->currentCommand();
    if ($command->isZoomed()) {
      $window->addDescendant($command->terminal);
      $command->raise();
      return;
    }
    $commands = $session->getVisibleCommands();
    $groupCursor = $session->getGroupCursor();
    $y = 0;
    foreach ($commands as $i => $command) {
      $groupBox = new \SPTK\Element($window, false, false, 'CommandGroup');
      $style = $groupBox->getStyle();
      $style->set('y', "-{$y}px");
      foreach ($command as $j => $gcommand) {
        if ($i === 0 && $j === $groupCursor) {
          $gcommand->activate();
        } else {
          $gcommand->inactivate();
        }
        $groupBox->addDescendant($gcommand->box);
      }
      $groupBox->recalculateGeometry();
      $ggeometry = $groupBox->getGeometry();
      $y += $ggeometry->height;
      if ($y > $wgeometry->height) {
        break;
      }
    }
    $commands[0][$groupCursor]->raise();
  }

  public static function measureSize() {
    $session = \MADIR\Command\Session::getCurrent();
    $parser = new \MADIR\Command\CommandParser($session);
    $parsedCommands = $parser->parse('%MeasureTerminalSize%');
    $cid = $session->runCommand($parsedCommands[0]);
    $command = $session->getCommand($cid);
    $group = $command->box->getAncestor();
    $terminal = \SPTK\Element::firstByType('Terminal', $command->box);
    $window = \SPTK\Element::firstByType('Window');
    $window->recalculateGeometry();
    $wgeometry = $window->getGeometry();
    $ggeometry = $group->getGeometry();
    $tgeometry = $terminal->getGeometry();
    $bgeometry = $command->box->getGeometry();
    $session->deleteCommand($cid);
    $letterWidth = $terminal->getLetterWidth();
    $letterHeight = $terminal->getLetterHeight();
    self::$sizes['verticalOverhead'] = $bgeometry->height - $letterHeight;
    self::$sizes['horizontalOverhead'] = $bgeometry->borderLeft + $tgeometry->borderLeft + $bgeometry->borderRight + $tgeometry->borderRight;
    self::$sizes['verticalGap'] = (int)(($ggeometry->height - $bgeometry->height) / 2);
    self::$sizes['horizontalGap'] = (int)(($wgeometry->innerWidth - $bgeometry->width) / 2);
    self::$sizes['windowHeight'] = $wgeometry->innerHeight;
    self::$sizes['windowWidth'] = $wgeometry->innerWidth;
    self::$sizes['letterHeight'] = $letterHeight;
    self::$sizes['letterWidth'] = $letterWidth;
  }

}
