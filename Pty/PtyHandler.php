<?php

namespace MADIR\Pty;

class PtyHandler {

  public $pid;
  public $cid;
  public $socket;
  public $session = false;
  public $idle = true;

  public function __construct($commander) {
    $this->since = microtime(true);
    $socket = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $this->pid = pcntl_fork();
    if ($this->pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($this->pid === 0) {
      fclose($socket[0]); // child closes parent end
      $commander->cleanupInChild();
      new Pty($socket[1]);
      exit(0);
    } else {
      fclose($socket[1]); // parent closes child end
      $this->socket = $socket[0];
      stream_set_blocking($this->socket, false);
    }
  }

  public function runCommand($command) {
    $this->idle = false;
    $this->cid = $command['cid'];
    Message::send($this->socket, $command);
  }

  public function sendInput($input) {
    Message::send($this->socket, ['cid' => $this->cid, 'input' => $input]);
  }

}
