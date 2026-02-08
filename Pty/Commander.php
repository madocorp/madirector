<?php

namespace MADIR\Pty;

require_once 'Pty/PtyHandler.php';
require_once 'Pty/Pty.php';
require_once 'Pty/Libc.php';

class Commander {

  private $ptys = [];
  private $commanderSocket;
  private $deathReport = [];
  private $isChild = false;

  public function __construct($commanderSocket) {
    new Libc;
    $this->commanderSocket = $commanderSocket;
    cli_set_process_title('MADIRCommander');
    register_shutdown_function([$this, 'end']);
    pcntl_signal(SIGCHLD, [$this, 'death']);
    $this->waitForMessage();
  }

  private function waitForMessage() {
    $ok = true;
    while ($ok) {
      $read = [$this->commanderSocket];
      $write = [];
      $except = [];
      foreach ($this->ptys as $pty) {
        if ($pty->idle === false) {
          $read[] = $pty->socket;
        }
      }
      $n = @stream_select($read, $write, $except, 60, 0);
      while (!empty($this->deathReport)) {
        $msg = array_shift($this->deathReport);
        Message::send($this->commanderSocket, $msg);
      }
      if ($n === false) {
        pcntl_signal_dispatch();
        continue;
      }
      if ($n == 0) {
        continue;
      }
      foreach ($read as $socket) {
        if ($socket === $this->commanderSocket) {
          $command = Message::receive($socket);
          if ($command === false) {
            $ok = false;
            break;
          }
          $this->delegateCommand($command);
        } else {
          $ptyResponse = Message::receive($socket);
          if ($ptyResponse === false) {
            $ok = false;
            break;
          }
          $this->forwardResponse($ptyResponse);
        }
      }
    }
  }

  private function delegateCommand($command) {
    $selectedPty = false;
    foreach ($this->ptys as $pty) {
      if ($pty->idle !== false) {
        $selectedPty = $pty;
        break;
      }
    }
    if ($selectedPty === false) {
      $selectedPty = new PtyHandler($this);
      $this->ptys[$selectedPty->pid] = $selectedPty;
    }
    $selectedPty->runCommand($command);
  }

  private function forwardResponse($ptyResponse) {
    Message::send($this->commanderSocket, $ptyResponse);
    if (isset($ptyResponse['returned'])) {
      $pid = $ptyResponse['pid'];
      $this->ptys[$pid]->idle = true;
      $this->ptys[$pid]->cid = false;
    }
  }

  public function end() {
    if ($this->isChild) {
      return;
    }
    foreach ($this->ptys as $pty) {
      $pty->idle = true;
      $pty->since = microtime(true);
      posix_kill($pty->pid, SIGKILL);
      if (is_resource($pty->socket)) {
        fclose($pty->socket);
      }
    }
    while (!empty($this->ptys)) {
      while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        if (isset($this->ptys[$pid])) {
          unset($this->ptys[$pid]);
        }
      }
      usleep(10000);
    }
  }

  public function death() {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
      if (isset($this->ptys[$pid])) {
        $pty = $this->ptys[$pid];
        if (is_resource($wpty->socket)) {
          fclose($pty->socket);
        }
        if ($pty->idle === false) {
          $this->deathReport[] = [
            'cid' => $pty->cid,
            'returned' => -1
          ];
        }
        unset($this->ptys[$pid]);
      }
    }
  }

  public function cleanupInChild() {
    $this->ptys = null;
    $this->commanderSocket = null;
    $this->deathReport = null;
    $this->isChild = true;
    pcntl_signal(SIGCHLD, SIG_DFL);
  }

}
