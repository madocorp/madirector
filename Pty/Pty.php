<?php
// DEBUGLEVEL:8
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
      $this->pollOnce();
      $command = Message::receive($this->socket);
      if ($command === '') {
         continue;
      }
      $commandReceived = true;
    }
    // DEBUG:8 echo "MSGRCV: pty [command]\n";
    $this->cid = $command['cid'];
    $executor = new \MADIR\Command\Executor($command['command'], $this->master, $this->slave);
try {
    while ($executor->run) {
      $this->pollOnce();
      while (true) {
        $msg = Message::receive($this->socket);
        if ($msg === '') {
           break; // no full message yet
        }
        // handle $msg array...
       }
    // Process PTY output (fast path)
    // If you prefer fully libc-buffered PTY reads, don’t use IO::pumpRead for PTY,
    // instead do Terminal::readAvailable when poll says POLLIN.
    // (See note below.)
    }
} catch (\Exception $e) {
  echo $e->getMessage();
}
    $status = $executor->getStatus();
    // DEBG:8 echo "MSGSND: pty->commander [return]\n";
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'return' => $status
    ]);
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
