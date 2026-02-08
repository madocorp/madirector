<?php

namespace MADIR\Pty;

require_once 'Command/CommandParser.php';
require_once 'Command/Executor.php';

class Pty {

  const MAX_IDLE_TIME = 600;

  private $pid;
  private $cid;
  private $socket;
  private $connection;
  private $connected = false;
  private $master;
  private $slave;

  public function __construct($socket) {
    cli_set_process_title('MADIRPty');
    $this->pid = getmypid();
    $this->socket = $socket;
    Libc::openpty($this->master, $this->slave);
    Libc::setNonBlocking($this->master);
    $idleSince = microtime(true);
    while (true) {
      $read = [$this->socket];
      $write = $except = [];
      $n = stream_select($read, $write, $except, 60, 0);
      if ($n === false || $n === 0) { // EINTR or timeout
        if (microtime(true) - $idleSince > self::MAX_IDLE_TIME) {
          break;
        }
        continue;
      }
      $command = Message::receive($this->socket);
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
    $executor = new \MADIR\Command\Executor($command['command'], $this->master, $this->slave, [$this, 'sendOutput']);
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'returned' => 0
    ]);
    $this->cid = false;
  }

}
