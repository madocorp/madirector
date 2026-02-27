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
  private $height = false;

  public function __construct($command, $session) {
    $this->command = $command;
    $this->session = $session;
    if ($command !== false) {
      $this->screenBuffer = new \MADIR\Screen\ScreenBuffer;
      $this->cid = \MADIR\Pty\CommanderHandler::runCommand($this);
      $this->height = $this->screenBuffer->countLines();
    }
  }

  public function output($stream) {
    $this->screenBuffer->parse($stream);
    // if current session, on screen, etc ...
    $newHeight = $this->screenBuffer->countLines();
    if ($newHeight !== $this->height) {
      \MADIR\Screen\Controller::listCommands();
    }
    \SPTK\Element::refresh(); // refresh only terminal...
  }

  public function input($stream) {
    \MADIR\Pty\CommanderHandler::sendInput($this->cid, $stream);
  }

  public function end() {
    $this->returnValue = 0;
    $this->grab = false;
    $this->scroll = false;
    $this->done = microtime(true);
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
  }

  public function toggleScroll() {
    $this->scroll = !$this->scroll;
  }

}
