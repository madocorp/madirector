<?php

namespace MADIR\Command;

class Command {

  public $command;
  public $session;
  public $started = false;
  public $done = false;
  public $returnValue = false;
  public $screenBuffer;
  public $cid;

  public function __construct($command, $session) {
    $this->command = $command;
    $this->session = $session;
    if ($command !== false) {
      $this->screenBuffer = new \SPTK\Terminal\ScreenBuffer;
      $this->cid = \MADIR\Pty\CommanderHandler::runCommand($this);
    }
  }

  public function output($stream) {
    $this->screenBuffer->parse($stream);

// TODO: detect size changes!
\MADIR\Screen\Controller::listCommands();
\SPTK\Element::refresh();

  }

  public function input($stream) {
    \MADIR\Pty\CommanderHandler::sendInput($this->cid, $stream);
  }

  public function end() {
    $this->returnValue = 0;
    $this->session->endCommand();
    \MADIR\Screen\Controller::listCommands();
    \SPTK\Element::refresh();
  }

  public function isNew() {
    return $this->command === false;
  }

}
