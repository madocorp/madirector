<?php

namespace MADIR\Command;

class Command {

  public $command;
  public $session;
  public $started = false;
  public $done = false;
  public $returnValue = false;
  public $grab = false;
  public $scroll = false;
  private $zoom = false;
  public $screenBuffer;
  private $cid;
  public $box;
  public $terminal;
  private $height = false;
  private $value = '';
  private $maxRows = 25;
  private $maxCols = 80;

  public function __construct($command, $session, $internal, $boxSize = 1) {
    $this->command = $command;
    $this->session = $session;
    if ($command === false) {
      $this->createCommandLine();
    } else {
      $this->started = microtime(true);
      $sizes = \MADIR\Screen\Controller::$sizes;
      if (!empty($sizes)) {
        $this->maxRows = (int)((($sizes['windowHeight'] / $boxSize) - $sizes['verticalOverhead'] - $sizes['verticalGap'] * 2) / $sizes['letterHeight']);
        $this->maxCols = (int)((($sizes['windowWidth'] / $boxSize) - $sizes['horizontalOverhead'] - $sizes['horizontalGap']) / $sizes['letterWidth']);
      }
      $this->screenBuffer = new \MADIR\Screen\ScreenBuffer($this->maxRows, $this->maxCols);
      if ($internal) {
        $this->cid = \MADIR\Pty\CommanderHandler::nextCommandId();
      } else {
        $this->cid = \MADIR\Pty\CommanderHandler::runCommand($this);
      }
      $this->createCommandBox($boxSize);
      if ($boxSize > 1) {
        $this->screenBuffer->setFill(true);
      }
      $this->height = $this->screenBuffer->countVisibleLines();
    }
  }

  public function getCommandMessage() {
    return json_encode([
      'rows' => $this->maxRows,
      'cols' => $this->maxCols,
      'wd' => $this->session->cwd(),
      'env' => $this->session->getenv(),
      'sequence' => $this->command['sequence'],
      'commandString' => $this->command['commandString']
    ]);
  }

  public function output($stream) {
    $this->screenBuffer->parse($stream);
    $this->terminal->refreshScroll();
    // if current session, on screen, etc ...
    $newHeight = $this->screenBuffer->countVisibleLines();
    if ($newHeight !== $this->height) {
      \MADIR\Screen\Controller::listCommands();
    }
    \SPTK\Element::refresh(); // refresh only terminal...
  }

  public function input($stream) {
    \MADIR\Pty\CommanderHandler::sendInput($this->cid, $stream);
  }

  public function end($returnValue) {
    $this->returnValue = $returnValue;
    $this->toggleGrab(false);
    $this->terminal->releaseInput();
    $cmd = \SPTK\Element::firstByType('Command', $this->box);
    $cmd->removeClass('run');
    $this->done = microtime(true);
    $info = \SPTK\Element::firstByType('CommandStatus', $this->box);
    $info->setText($this->getStatusString());
    $this->screenBuffer->cursor(false);
    $this->session->endCommand();
    \MADIR\Screen\Controller::listCommands();
    \SPTK\Element::refresh();
  }

  public function setSize($rows, $cols) {
    $this->screenBuffer->setSize($rows, $cols);
    \MADIR\Pty\CommanderHandler::sendSize($this->cid, $rows, $cols);
  }

  public function isNew() {
    return $this->command === false;
  }

  public function isRunning() {
    return $this->done === false;
  }

  public function isGrabbed() {
    return $this->grab;
  }

  public function isScrolled() {
    return $this->scroll;
  }

  public function isZoomed() {
    return $this->zoom;
  }

  public function toggleGrab($grab = null) {
    if ($grab === null) {
      $this->grab = !$this->grab;
    } else {
      $this->grab = $grab;
    }
    if ($this->grab) {
      $this->box->addClass('grab', true);
      $this->terminal->grabInput();
    } else {
      $this->box->removeClass('grab', true);
      $this->terminal->releaseInput();
    }
  }

  public function toggleScroll($scroll = null) {
    if ($scroll === null) {
      $this->scroll = !$this->scroll;
    } else {
      $this->scroll = $scroll;
    }
    if ($this->scroll) {
      $this->terminal->scrollOn();
      $this->box->addClass('scroll', true);
    } else {
      $this->terminal->scrollOff();
      $this->box->removeClass('scroll', true);
    }
  }

  public function toggleZoom($zoom = null) {
    if ($zoom === null) {
      $this->zoom = !$this->zoom;
    } else {
      $this->zoom = $zoom;
    }
    if ($this->zoom) {
      $sizes = \MADIR\Screen\Controller::$sizes;
      if (empty($sizes)) {
        $this->zoom = false;
        return;
      }
      $zoomRows = (int)($sizes['windowHeight'] / $sizes['letterHeight']);
      $zoomCols = (int)($sizes['windowWidth'] / $sizes['letterWidth']);
      $this->terminal->remove();
      $this->terminal->addClass('zoom');
      $this->setSize($zoomRows, $zoomCols);
    } else {
      $this->box->addDescendant($this->terminal);
      $this->terminal->removeClass('zoom');
      $this->setSize($this->maxRows, $this->maxCols);
    }
  }

  public function getStatusString() {
    $status = '';
    $status .= "[{$this->cid}] ";
    $dt = new \DateTime;
    $dt->setTimestamp($this->started);
    $status .= $dt->format('Y-m-d H:i:s') . ' ';
    if ($this->done !== false) {
      $status .= sprintf("(%.3fs) ", $this->done - $this->started);
    }
    $status .= ">>> ";
    if ($this->done !== false) {
      $status .= sprintf("%d%s", $this->returnValue, $this->returnValue === 0 ? '.' : '!');
    } else {
      $status .= '...';
    }
    return $status;
  }

  private function createCommandLine() {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, 'newCommand', false, 'CommandBlock');
    $this->box = $block;
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(rtrim($this->session->cwd(), '/') . '/');
    $cmd = new \SPTK\Element($block, false, 'new', 'Command');
    $label = new \SPTK\Element($cmd, false, 'prompt', 'Label');
    $label->setText('>');
    $input = new \SPTK\Elements\Input($label, false, 'cmd');
    $input->addClass('active', true);
  }

  public function refreshCommandLine() {
    $info = \SPTK\Element::firstByType('CommandInfo', $this->box);
    $info->setText(rtrim($this->session->cwd(), '/') . '/');
  }

  public function setValue($value) {
    $cmd = \SPTK\Element::firstByType('Input', $this->box);
    $cmd->setValue($value);
  }

  public function getValue() {
    $cmd = \SPTK\Element::firstByType('Input', $this->box);
    return $cmd->getValue();
  }

  public function saveValue() {
    $this->value = $this->getValue();
  }

  public function restoreValue() {
    $this->setValue($this->value);
  }

  private function createCommandBox($boxSize) {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, false, false, 'CommandBlock');
    $this->box = $block;
    if ($boxSize === 2) {
      $this->box->addClass('half');
    } else if ($boxSize === 3) {
      $this->box->addClass('third');
    }
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText(rtrim($this->session->cwd(), '/') . '/');
    $status = new \SPTK\Element($info, false, false, 'CommandStatus');
    $status->setText($this->getStatusString());
    $cmd = new \SPTK\Element($block, false, 'run', 'Command');
    $cmd->setText('> ' . $this->command['commandString']);
    $this->terminal = new \MADIR\Screen\Terminal($block);
    $this->terminal->setBuffer($this->screenBuffer);
    $this->terminal->setInputCallback([$this, 'input']);
    if ($boxSize === 1) {
      $this->toggleGrab(true);
      $this->box->addClass('grab', true);
    }
  }

  public function raise() {
    if ($this->command === false) {
      $input = \SPTK\Element::firstByType('Input', $this->box);
      $input->raise();
    } else {
      $this->terminal->raise();
    }
  }

  public function getHeight() {
    $geometry = $this->box->getGeometry();
    return $geometry->height;
  }

  public function activate() {
    if ($this->command === false || (!$this->grab && !$this->scroll)) {
      $this->box->addClass('active', true);
    }
  }

  public function inactivate() {
    $this->box->removeClass('active', true);
  }

  public function getCid() {
    return $this->cid;
  }

}
