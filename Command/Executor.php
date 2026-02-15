<?php

namespace MADIR\Command;

use \MADIR\Pty\Libc;
use \MADIR\Pty\Message;

class Executor {

  public $returnValue = 0;
  private $master;
  private $slave;
  private $outputFunc;
  private $inputSocket;
  private $pipes = [];
  private $run = true;
  private $lastpid;

  public function __construct($command, $master, $slave, $outputFunc, $inputSocket) {
    $this->master = $master;
    $this->slave = $slave;
    $this->outputFunc = $outputFunc;
    $this->inputSocket = $inputSocket;
    $parser = new CommandParser;
    $parsedCommand = $parser->parse($command);
    $this->runSequence($parsedCommand);
  }

  private function runSequence($sequence) {
    $lastStatus = 0;
    foreach ($sequence as $item) {
      if ($item['op'] === '&&' && $lastStatus !== 0) {
        continue;
      }
      if ($item['op'] === '||' && $lastStatus === 0) {
        continue;
      }
      $lastStatus = $this->runPipeline($item['pipeline']);
    }
    $this->returnValue = $lastStatus;
  }

  private function runPipeline($commands) {
    $n = count($commands);
    for ($i = 0; $i < $n - 1; $i++) {
      $this->pipes[$i] = Libc::socketpair();
    }
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
      $pid = pcntl_fork();
      if ($pid === 0) {
        $this->child($i, $commands[$i]['redirects'], $commands[$i]['argv']); // won't return!
      }
      $pids[] = $pid;
    }
    foreach ($this->pipes as $p) {
      Libc::close($p[0]);
      Libc::close($p[1]);
    }
    $this->lastpid = end($pids);
    pcntl_signal(SIGCHLD, [$this, 'childEnd']);
    while ($this->run) {
      $ready = Libc::pollN($this->inputSocket, $this->master);
      if ($ready[0]) {
        while (true) {
          $msg = Message::receive($this->inputSocket);
          if ($msg === false) {
            break;
          }
          $res = Libc::write($this->master, $msg['input']);
        }
      }
      if ($ready[1]) {
        while (true) {
          $data = Libc::read($this->master, 8192);
          if ($data === false || $data === '') {
var_dump($data);
            break;
          }
          call_user_func($this->outputFunc, $data);
        }
      }
    }
    foreach ($pids as $pid) {
      $res = pcntl_waitpid($pid, $status, WNOHANG);
    }
    return pcntl_wexitstatus($status);
  }

  private function child($i, $redirects, $argv) {
    $libc = Libc::$instance->libc;
    if ($i === 0) {
      Libc::setsid();
//    $libc->ioctl($this->slave, Libc::TIOCSCTTY, 0);
    }
    // set stdin/stdout
    $n = count($this->pipes);
    if ($i === 0) {
      Libc::dup2($this->slave, 0);
      Libc::setRawMode(0);
    }
    if ($i > 0) {
      Libc::dup2($this->pipes[$i - 1][0], 0);
    }
    if ($i < $n - 1) {
      Libc::dup2($this->pipes[$i][1], 1);
    }
    if ($n === 0 || $i === $n - 1) {
      Libc::dup2($this->slave, 1);
    }
    // set strderr
    Libc::dup2($this->slave, 2);
    // close all pipe fds in child
    Libc::close($this->master);
    Libc::close($this->slave);
    foreach ($this->pipes as $p) {
      Libc::close($p[0]);
      Libc::close($p[1]);
    }
    $this->applyRedirects($redirects);
    // $libc->setpgid(0, 0);
    // $libc->tcsetpgrp(0, $libc->getpgrp());
    Libc::execvp($argv);
    exit(127); // in case of exec failed
  }

  private function applyRedirects($redirects) {
    foreach ($redirects as $r) {
      if ($r['type'] === 'dup') {
        Libc::dup2($r['target'], $r['fd']);
        continue;
      }
      if ($r['type'] === '>') {
        $fd = Libc::open($r['target'], Libc::O_WRONLY | Libc::O_CREAT | Libc::O_TRUNC);
      } elseif ($r['type'] === '>>') {
        $fd = Libc::open($r['target'], Libc::O_WRONLY | Libc::O_CREAT | Libc::O_APPEND);
      } elseif ($r['type'] === '<') {
        $fd = Libc::open($r['target'], Libc::O_RDONLY);
      } else {
        continue;
      }
      Libc::dup2($fd, $r['fd']);
    }
  }

  public function childEnd() {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
      if ($pid === $this->lastpid) {
        $this->run = false;
      }
    }
  }

}
