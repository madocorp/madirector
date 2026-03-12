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
    $this->pid = getmypid();
    $this->socket = $socket;
    Libc::setNonBlocking($this->socket);
    Libc::openpty($this->master, $this->slave);
    Libc::setNonBlocking($this->master);
    Libc::setSize($this->master, 24, 80);
    Libc::setsid();
    $libc = Libc::$instance->libc;
    $libc->ioctl($this->slave, Libc::TIOCSCTTY, 0);
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
    $command = json_decode($message['command'], true);
    Libc::setSize($this->master, $command['rows'], $command['cols']);
    chdir($command['wd']);
    $this->setEnv($command['env']);
    $executor = new Executor($command['sequence'], $this->master, $this->slave);
    $run = $executor->startSequence();
    $masterAlive = true;
    while ($masterAlive && $run) {
      $executor->reapChildren();
      $run = $executor->advanceSequence();
      $events = IO::pollAndReceive(50, [$this->socket, $this->master], $this->master);
      foreach ($events as $item) {
        if ($item['fd'] === $this->master) {
          if ($item['alive'] < 1) {
            $masterAlive = false;
          }
          $this->sendOutput($item['msg']);
        } else {
          if ($item['alive'] < 1) {
            echo "pty socket closed!\n";
            exit(1);
          } else {
            $message = $item['msg'];
            if ($message['type'] === Message::INPUT) {
              IO::queueWrite($this->master, $message['input']);
            }
            if ($message['type'] === Message::RESIZE) {
              $size = explode('x', $message['size']);
              Libc::setSize($this->master, $size[0], $size[1]);
            }
          }
        }
      }
    }
    // DEBG:8 echo "MSGSND: pty->commander [return]\n";
    Message::send($this->socket, [
      'cid' => $this->cid,
      'pid' => $this->pid,
      'return' => $executor->getReturnValue()
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

  private function setEnv($env) {
    $libc = Libc::$instance->libc;
    $libc->clearenv();
    foreach ($env as $key => $value) {
      $libc->setenv($key, $value, 1);
    }
  }

}
