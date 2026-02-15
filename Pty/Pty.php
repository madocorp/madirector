<?php

namespace MADIR\Pty;

require_once 'Command/CommandParser.php';
require_once 'Command/Executor.php';

class Pty {

  const MAX_IDLE_TIME = 600;

  private $pid;
  private $cid;
  private $socket;
  private $master;
  private $slave;

  public function __construct($socket) {
//    pcntl_async_signals(true);
    cli_set_process_title('MADIRPty');
    putenv("LANG=en_US.UTF-8");
    putenv("TERM=xterm-256color");
    $this->pid = getmypid();
    $this->socket = $socket;
    Libc::setNonBlocking($this->socket);
    Libc::openpty($this->master, $this->slave);
    Libc::setNonBlocking($this->master);
    $idleSince = microtime(true);
    while (true) {
      $ready = Libc::pollN($this->socket);
      if ($ready[0]) {
        $command = Message::receive($this->socket);
      }
      if ($command === false) {
        break;
      }
      $this->runCommand($command);
      $idleSince = microtime(true);
    }
  }

  public function sendOutput($output) {
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'output' => $output
    ]);
  }

  private function runCommand($command) {
    $this->cid = $command['cid'];
    $executor = new \MADIR\Command\Executor($command['command'], $this->master, $this->slave, [$this, 'sendOutput'], $this->socket);
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'returned' => 0
    ]);
    $this->cid = false;
  }

}
