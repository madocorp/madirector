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
    $commandReceived = false;
    while (!$commandReceived) {
      $events = IO::pollAndReceive(-1, [$this->socket]);
      foreach ($events as $item) {
        $message = $item['msg'];
        if ($message === false) {
          echo "pty socket has been closed A\n";
          exit(1);
        }
        $commandReceived = true;
      }
    }
    // DEBUG:8 echo "MSGRCV: pty [command: {$message['command']}]\n";
    $this->cid = $message['cid'];
    $executor = new \MADIR\Command\Executor($message['command'], $this->master, $this->slave);
    $masterAlive = true;
    while ($masterAlive && $executor->run) {
      $events = IO::pollAndReceive(-1, [$this->socket, $this->master], $this->master);
      foreach ($events as $item) {
        if ($item['fd'] === $this->master) {
          if ($item['alive'] < 1) {
            $masterAlive = false;
          }
          $this->sendOutput($item['msg']);
        } else {
          if ($item['alive'] < 1) {
            echo "pty socket has been closed\n";
            exit(1);
          } else {
            $message = $item['msg'];
            if ($message['type'] === Message::INPUT) {
              IO::queueWrite($this->master, $message['input']);
            }
          }
        }
      }
      pcntl_signal_dispatch();
    }
    $status = $executor->getStatus();
    // DEBG:8 echo "MSGSND: pty->commander [return]\n";
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'return' => $status
    ]);
    IO::pollAndReceive(-1, [$this->socket]);
    exit(0);
  }

  private function sendOutput($output) {
    // DEBUG:8 echo "MSGSND: pty->commander [output]\n";
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'output' => $output
    ]);
  }

}
