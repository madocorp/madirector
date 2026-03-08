<?php

namespace MADIR\Command;

class Command {

  public $command;
  public $session;
  public $started = false;
  public $done = false;
  public $returnValue = false;
  public $grab = true;
  public $scroll = false;
  public $screenBuffer;
  public $cid;
  public $box;
  public $terminal;
  private $height = false;

  public function __construct($command, $session) {
    $this->command = $command;
    $this->session = $session;
    if ($command === false) {
      $this->createCommandLine();
    } else {
      $this->started = microtime(true);
      $this->screenBuffer = new \MADIR\Screen\ScreenBuffer;
      $this->cid = \MADIR\Pty\CommanderHandler::runCommand($this);
      $this->createCommandBox();
      $this->height = $this->screenBuffer->countVisibleLines();
    }
  }

  public function getCommandMessage() {
    return json_encode([
      'rows' => 25,
      'cols' => 100,
      'wd' => $this->session->cwd(),
      'sequence' => $this->command['sequence']
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
    $this->grab = false;
    $this->scroll = false;
    $this->box->removeClass('grab', true);
    $this->terminal->releaseInput();
    $cmd = $this->box->firstByType('Command');
    $cmd->removeClass('run');
    $this->done = microtime(true);
    $info = $this->box->firstByType('CommandStatus');
    $info->setText($this->getStatusString());
    $this->screenBuffer->cursor(false);
    $this->session->endCommand();
    \MADIR\Screen\Controller::listCommands();
    \SPTK\Element::refresh();
  }

  public function isNew() {
    return $this->command === false;
  }

  public function isRunning() {
    return $this->done === false;
  }

  public function toggleGrab() {
    $this->grab = !$this->grab;
    if ($this->grab) {
      $this->box->addClass('grab', true);
      $this->terminal->grabInput();
    } else {
      $this->box->removeClass('grab', true);
      $this->terminal->releaseInput();
    }
  }

  public function toggleScroll() {
    $this->scroll = !$this->scroll;
    if ($this->scroll) {
      $this->terminal->scrollOn();
      $this->box->addClass('scroll', true);
    } else {
      $this->terminal->scrollOff();
      $this->box->removeClass('scroll', true);
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
    $info->setText($this->session->cwd());
    $cmd = new \SPTK\Element($block, false, 'new', 'Command');
    $label = new \SPTK\Element($cmd, false, 'prompt', 'Label');
    $label->setText('$');
    $input = new \SPTK\Elements\Input($label, false, 'cmd');
    $input->addClass('active', true);
    $this->setPos(0);
  }

  private function createCommandBox() {
    $window = \SPTK\Element::firstByType('Window');
    $block = new \SPTK\Element($window, false, false, 'CommandBlock');
    $this->box = $block;
    $this->box->addClass('grab', true);
    $info = new \SPTK\Element($block, false, false, 'CommandInfo');
    $info->setText($this->session->cwd());
    $status = new \SPTK\Element($info, false, false, 'CommandStatus');
    $status->setText($this->getStatusString());
    $cmd = new \SPTK\Element($block, false, 'run', 'Command');
    $cmd->setText('$ ' . $this->command['commandString']);
    $this->terminal = new \MADIR\Screen\Terminal($block);
    $this->terminal->setBuffer($this->screenBuffer);
    $this->terminal->setInputCallback([$this, 'input']);
    $this->terminal->grabInput();
    $block->addClass('grab', true);
    $this->setPos(0);
  }

  public function setPos($y) {
    $style = $this->box->getStyle();
    $style->set('y', "-{$y}px");
    $this->box->recalculateGeometry();
  }

  public function getInputElement() {
    if ($this->command === false) {
      return $this->box->firstByType('Input');
    } else {
      return $this->box->firstByType('Terminal');
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

}
