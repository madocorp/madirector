<?php

namespace MADIR\Pty;

class PtyHandler {

  public $pid;
  public $cid;
  public $socket;
  public $session = false;

  public function __construct($commander) {
    $socket = Libc::socketpair();
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $this->pid = pcntl_fork();
    if ($this->pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($this->pid === 0) {
      Libc::close($socket[0]); // child closes parent end
      $commander->cleanupInChild();
      new Pty($socket[1]);
      exit(0);
    } else {
      Libc::close($socket[1]); // parent closes child end
      $this->socket = $socket[0];
      Libc::setNonBlocking($this->socket, false);
    }
  }

  public function runCommand($command) {
    $this->cid = $command['cid'];
echo "MSG SENT commander->pty (run)\n";
    Message::send($this->socket, $command);
  }

  public function sendInput($input) {
echo "MSG SENT commander->pty (input)\n";
    Message::send($this->socket, ['cid' => $this->cid, 'input' => $input]);
  }

}
