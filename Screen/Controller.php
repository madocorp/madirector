<?php

namespace MADIR\Screen;

class Controller {

  const MODE_IDLE = 0;
  const MODE_ACTIVE = 1;
  const MODE_INTERACTIVE = 2;
  const IDLE_TIMEOUT = 250;
  const ACTIVE_TIMEOUT = 50;
  const INTERACTIVE_TIMEOUT = 12;
  const ACTIVE_PERIOD = 500;
  const INTERACTIVE_PERIOD = 250;
  const INTERACTIVE_LIMIT = 120;
  const ACTIVE_LIMIT = 400;

  public static $sizes = [];
  protected static $grabbedBeforeZoom = false;
  protected static $scrolledBeforeZoom = false;
  protected static $heightChanged = false;
  protected static $outputHappened = false;
  protected static $lastOutput = 0;
  protected static $outputMode = self::MODE_IDLE;
  protected static $activeTill = 0;
  protected static $interactiveTill = 0;

  public static function init() {
    cli_set_process_title('MADIR');
    \SPTK\SDLWrapper\SDL::$instance->setWaitTime(self::IDLE_TIMEOUT);
    new \MADIR\Command\Session(0);
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
          self::listCommands();
        }
        if ($command->isNew()) {
          \MADIR\Completion\Engine::hideWindow();
        }
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
          if (!\MADIR\Completion\Engine::replace($command)) {
            self::runCommand($command);
            self::listCommands();
          }
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
        if (!$session->moveGroupCursor(0, -1)) {
          $session->previousCommand();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_DOWN:
        if (!$session->moveGroupCursor(0, 1)) {
          $session->nextCommand();
        }
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_LEFT:
        if ($command->isNew()) {
          if (!\MADIR\Completion\Engine::selectGroup(-1)) {
            return false;
          }
        } else {
          $session->moveGroupCursor(-1, 0);
          self::listCommands();
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::MOVE_RIGHT:
        if ($command->isNew()) {
          if (!\MADIR\Completion\Engine::selectGroup(1)) {
            return false;
          }
        } else {
          $session->moveGroupCursor(1, 0);
          self::listCommands();
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::SELECT_UP:
        if ($command->isNew()) {
          $session->history(1);
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::SELECT_DOWN:
        if ($command->isNew()) {
          $session->history(-1);
        }
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::COPY:
        \SPTK\Clipboard::set($command->getCommandString(false));
        return true;
      case \SPTK\SDLWrapper\Action::SELECT_ALL:
        \SPTK\Clipboard::set($command->getCommandString(true));
        return true;
      case \SPTK\SDLWrapper\Action::DELETE_FORWARD:
        if ($command->isRunning()) {
          // kill
        } else {
          if (!$command->isZoomed() && !$command->isScrolled()) {
            $session->deleteCommand($command->getCid());
            self::listCommands();
            \SPTK\Element::refresh();
          }
        }
        return true;
      case \SPTK\SDLWrapper\Action::SWITCH_LEFT:
        \MADIR\Command\Session::selectSession(-1, true);
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::SWITCH_RIGHT:
        \MADIR\Command\Session::selectSession(1, true);
        self::listCommands();
        \SPTK\Element::refresh();
        return true;
      case \SPTK\SDLWrapper\Action::SWITCH_NEXT:
        if ($command->isNew()) {
          \MADIR\Completion\Engine::complete($command);
          \SPTK\Element::refresh();
        }
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
    $session = $command->session;
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
    \MADIR\Completion\Engine::hideWindow();
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

  public static function heightChanged() {
    self::$heightChanged = true;
  }

  public static function outputHappened() {
    self::$outputHappened = true;
    $now = microtime(true) * 1000;
    $delta = $now - self::$lastOutput;
    if ($delta < self::INTERACTIVE_LIMIT) {
      if (self::$outputMode !== self::MODE_INTERACTIVE) {
        self::$outputMode = self::MODE_INTERACTIVE;
        \SPTK\SDLWrapper\SDL::$instance->setWaitTime(self::INTERACTIVE_TIMEOUT);
        // DEBUG:6 echo "Mode: INTERACTIVE\n";
      }
      self::$interactiveTill = $now + self::INTERACTIVE_PERIOD;
      self::$activeTill = $now + self::ACTIVE_PERIOD;
    } else if ($delta < self::ACTIVE_LIMIT) {
      if (self::$outputMode === self::MODE_IDLE) {
        \SPTK\SDLWrapper\SDL::$instance->setWaitTime(self::ACTIVE_TIMEOUT);
        self::$outputMode = self::MODE_ACTIVE;
        // DEBUG:6 echo "Mode: ACTIVE\n";
      }
      self::$activeTill = $now + self::ACTIVE_PERIOD;
    }
    self::$lastOutput = $now;

  }

  public static function periodicRefresh() {
    if (self::$heightChanged) {
      self::listCommands();
      \SPTK\Element::refresh();
    } else if (self::$outputHappened > 0) {
      $window = \SPTK\Element::firstByType('Window');
      $terminals = \SPTK\Element::allByType('Terminal', $window);
      foreach ($terminals as $terminal) {
        \SPTK\Element::immediateRender($terminal, false);
      }
    }
    self::$heightChanged = false;
    self::$outputHappened = false;
    $now = microtime(true) * 1000;
    if (self::$outputMode === self::MODE_INTERACTIVE && $now > self::$interactiveTill) {
      self::$outputMode = self::MODE_ACTIVE;
      \SPTK\SDLWrapper\SDL::$instance->setWaitTime(self::ACTIVE_TIMEOUT);
      // DEBUG:6 echo "Mode: ACTIVE\n";
    }
    if (self::$outputMode === self::MODE_ACTIVE && $now > self::$activeTill) {
      self::$outputMode = self::MODE_IDLE;
      \SPTK\SDLWrapper\SDL::$instance->setWaitTime(self::IDLE_TIMEOUT);
      // DEBUG:6 echo "Mode: IDLE\n";
    }
  }

}
