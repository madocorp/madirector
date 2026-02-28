<?php
namespace MADIR\Pty;

class Commander {

  private $ptys = [];
  private $commanderSocket;
  private $deathReport = [];
  private $isChild = false;

  public function __construct($commanderSocket) {
    \SPTK\Autoload::load('\MADIR\Pty\Pty');
    \SPTK\Autoload::load('\MADIR\Command\Executor');
    \SPTK\Autoload::load('\MADIR\Command\CommandParser');
    $this->commanderSocket = $commanderSocket;
    Libc::setNonBlocking($this->commanderSocket);
    cli_set_process_title('MADIRCommander');
    register_shutdown_function([$this, 'end']);
    pcntl_signal(SIGCHLD, [$this, 'childEnd']);
    $this->waitForMessage();
  }

  private function waitForMessage() {
    while (true) {
      $fds = [$this->commanderSocket];
      foreach ($this->ptys as $pty) {
        $fds[] = $pty->socket;
      }
      $events = IO::pollAndReceive(-1, $fds);
      foreach ($events as $item) {
        $fd  = $item['fd'];
        $message = $item['msg'];
        if ($fd === $this->commanderSocket) {
          if ($item['alive'] < 1) {
            echo "Commander socket has been closed\n";
            exit(1);
          }
          if (isset($message['input'])) {
            // DEBUG:8 echo "MSGRCV: commander [input]\n";
            $this->sendInput($message);
          }
          if (isset($message['command'])) {
            // DEBUG:8 echo "MSGRCV: commander [command: {$message['command']}]\n";
            $this->delegateCommand($message);
          }
        } else {
          if ($item['alive'] < 1) {
            echo "Pty socket has been closed\n";
            // exit(1); // ?
          }
          if ($message !== null) {
            $this->forwardResponse($message);
          }
        }
      }
      pcntl_signal_dispatch();
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
    // DEBUG:8 echo "MSGSND: commander->main [", (isset($ptyResponse['return']) ? 'return' : 'output'), "]\n";
    Message::send($this->commanderSocket, $ptyResponse);
    if (isset($ptyResponse['return'])) {
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
            'return' => $status
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
