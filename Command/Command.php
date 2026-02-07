<?php

namespace MADIR\Command;

class Command {

  public $command;
  public $session;
  public $started = false;
  public $done = false;
  public $returnValue = false;
  public $screenBuffer;

  public function __construct($command, $session) {
    $this->command = $command;
    $this->session = $session;
    if ($command !== false) {
      $this->screenBuffer = new ScreenBuffer;
      \MADIR\Pty\CommanderHandler::runCommand($this);
    }
  }

  public function output($stream) {
    $this->screenBuffer->parser->parse($stream);
//$this->screenBuffer->debug();
  }

  public function end() {
    echo "end\n";
    $this->returnValue = 0;
    $this->session->endCommand();
    \MADIR\Screen\Controller::listCommands();
    \SPTK\Element::refresh();
  }

  public function isNew() {
    return $this->command === false;
  }

}
