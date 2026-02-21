<?php

namespace MADIR\Pty;

class Pty {

  private $pid;
  private $cid;
  private $socket;
  private $master;
  private $slave;

  public function __construct($socket) {
    cli_set_process_title('MADIRPty');
    putenv("LANG=en_US.UTF-8");
    putenv("TERM=xterm-256color");
    $this->pid = getmypid();
    $this->socket = $socket;
    Libc::setNonBlocking($this->socket);
    Libc::openpty($this->master, $this->slave);
    Libc::setNonBlocking($this->master);
    Libc::setSize($this->master, 24, 80);
    $end = false;
    while (!$end) {
      $ready = Libc::pollN($this->socket);
      if ($ready[0] == 'IN' || $ready[0] == 'HUP') {
        $command = Message::receive($this->socket);
      }
      if ($ready[0] == 'HUP') {
        $end = true;
      }
      if ($command === false || $command === '') {
        continue;
      }
      $end = true;
    }
    $this->cid = $command['cid'];
    $executor = new \MADIR\Command\Executor($command['command'], $this->master, $this->slave, [$this, 'sendOutput'], $this->socket);
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'returned' => 0 // exexutor->status
    ]);
    exit(0);
  }

  public function sendOutput($output) {
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'output' => $output
    ]);
  }

}
