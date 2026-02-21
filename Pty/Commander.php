<?php

namespace MADIR\Pty;

class Commander {

  private $ptys = [];
  private $commanderSocket;
  private $deathReport = [];
  private $isChild = false;

  public function __construct($commanderSocket) {
    $this->commanderSocket = $commanderSocket;
    cli_set_process_title('MADIRCommander');
    register_shutdown_function([$this, 'end']);
    pcntl_signal(SIGCHLD, [$this, 'childEnd']);
    pcntl_async_signals(true);
    $this->waitForMessage();
  }

  private function waitForMessage() {
    $ok = true;
    while ($ok) {
      $fds = [$this->commanderSocket];
      foreach ($this->ptys as $pty) {
        $fds[] = $pty->socket;
      }
      $ready = Libc::pollN(...$fds);
      while (!empty($this->deathReport)) {
        $msg = array_shift($this->deathReport);
        Message::send($this->commanderSocket, $msg);
      }
      foreach ($ready as $i => $read) {
        if ($read == 'IN' || $read == 'HUP') {
          if ($i === 0) {
            $message = Message::receive($this->commanderSocket);
            if ($message === false) {
              $ok = false;
              continue;
            }
            if (isset($message['input'])) {
              $this->sendInput($message);
            }
            if (isset($message['command'])) {
              $this->delegateCommand($message);
            }
          } else {
            $ptyResponse = Message::receive($fds[$i]);
            if ($ptyResponse === false) {
              $ok = false;
              continue;
            }
            $this->forwardResponse($ptyResponse);
          }
        }
      }
    }
  }

  private function delegateCommand($command) {
    $pty = new PtyHandler($this);
    $this->ptys[$pty->pid] = $pty;
    $pty->runCommand($command);
  }

  private function sendInput($input) {
    $selectedPty = false;
    foreach ($this->ptys as $pty) {
      if ($pty->cid === $input['cid']) {
        $selectedPty = $pty;
        break;
      }
    }
    if ($selectedPty !== false) {
      $selectedPty->sendInput($input['input']);
    }
  }

  private function forwardResponse($ptyResponse) {
    Message::send($this->commanderSocket, $ptyResponse);
    if (isset($ptyResponse['returned'])) {
      $pid = $ptyResponse['pid'];
      unset($this->ptys[$pid]);
    }
  }

  public function end() {
    if ($this->isChild) {
      return;
    }
    foreach ($this->ptys as $pty) {
      posix_kill($pty->pid, SIGKILL);
      if (is_resource($pty->socket)) {
        Libc::close($pty->socket);
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

  public function childEnd() {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
      if (isset($this->ptys[$pid])) {
        if ($status !== 0) {
          $pty = $this->ptys[$pid];
          Libc::close($pty->socket);
          $this->deathReport[] = [
            'cid' => $pty->cid,
            'returned' => $status
          ];
          unset($this->ptys[$pid]);
        }
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
