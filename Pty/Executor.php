<?php

namespace MADIR\Pty;

use \MADIR\Pty\Libc;
use \MADIR\Pty\Message;

class Executor {

  private $returnValue = false;
  private $master;
  private $slave;
  private $pids = [];
  private $pgid;
  private $lastPid;
  private $sequence = [];
  private $sidx = 0;
  private $pipelineIsRunning = false;

  public function __construct($sequence, $master, $slave) {
    $this->master = $master;
    $this->slave = $slave;
    $this->sequence = $sequence;
  }

  public function startSequence() {
    $this->sidx = 0;
    $item = $this->sequence[$this->sidx];
    $this->runPipeline($item['pipeline']);
    $this->sidx++;
    return true;
  }

  public function advanceSequence() {
    if ($this->pipelineIsRunning) {
      return true;
    }
    if (!isset($this->sequence[$this->sidx])) {
      return false;
    }
    $previousItem = $this->sequence[$this->sidx - 1];
    if ($previousItem['op'] === '&&' && $this->returnValue !== 0) {
      return false;
    }
    if ($previousItem['op'] === '||' && $this->returnValue === 0) {
      return false;
    }
    $item = $this->sequence[$this->sidx];
    $this->runPipeline($item['pipeline']);
    $this->sidx++;
    return true;
  }

  private function runPipeline($commands) {
    $this->pipelineIsRunning = true;
    $nCmd = count($commands);
    $nPipes = $nCmd - 1;
    $this->pids = [];
    $pipes = [];
    for ($i = 0; $i < $nPipes; $i++) {
      $pipes[$i] = Libc::pipe();
    }
    for ($i = 0; $i < $nCmd; $i++) {
      $pid = pcntl_fork();
      if ($pid === 0) {
        $this->child($i, $commands[$i]['redirects'], $commands[$i]['argv'], $pipes); // won't return!
        exit(127);
      }
      $this->pids[] = $pid;
      if ($i === 0) {
        $this->pgid = $pid;
      }
    }
    foreach ($pipes as $p) {
      Libc::close($p[0]);
      Libc::close($p[1]);
    }
    $libc = Libc::$instance->libc;
    $libc->tcsetpgrp($this->slave, $this->pgid);
    $this->lastPid = end($this->pids);
  }

  private function child($i, $redirects, $argv, $pipes) {
    $libc = Libc::$instance->libc;
    Libc::close($this->master);
    Libc::close(1);
    if ($i === 0) {
      $this->pgid = $libc->getpid();
    }
    $libc->setpgid(0, $this->pgid);
    // Default stdio for a foreground job is the PTY slave.
    Libc::dup2($this->slave, 0);
    Libc::dup2($this->slave, 1);
    Libc::dup2($this->slave, 2);
    // Override stdin/stdout for pipeline members.
    $nPipes = count($pipes);
    if ($i > 0) {
      Libc::dup2($pipes[$i - 1][0], 0);
    }
    if ($i < $nPipes) {
      Libc::dup2($pipes[$i][1], 1);
    }
    // close all pipe fds in child
    Libc::close($this->slave);
    foreach ($pipes as $p) {
      Libc::close($p[0]);
      Libc::close($p[1]);
    }
    $this->applyRedirects($redirects);
    Libc::execvp($argv);
    exit(127); // in case of exec failed
  }

  private function applyRedirects($redirects) {
    $libc = Libc::$instance->libc;
    foreach ($redirects as $r) {
      if ($r['type'] === 'dup') {
        Libc::dup2($r['target'], $r['fd']);
        continue;
      }
      if ($r['type'] === '>') {
        $fd = $libc->open($r['target'], Libc::O_WRONLY | Libc::O_CREAT | Libc::O_TRUNC, 0644);
      } elseif ($r['type'] === '>>') {
        $fd = $libc->open($r['target'], Libc::O_WRONLY | Libc::O_CREAT | Libc::O_APPEND, 0644);
      } elseif ($r['type'] === '<') {
        $fd = $libc->open($r['target'], Libc::O_RDONLY);
      } else {
        continue;
      }
      if ($fd < 0) {
        echo "Failed to open {$r['target']}\n";
        exit(127);
      }
      Libc::dup2($fd, $r['fd']);
    }
  }

  public function reapChildren() {
    foreach ($this->pids as $pid) {
      $status = 0;
      $wpid = pcntl_waitpid($pid, $status, WNOHANG);
      if ($wpid === $this->lastPid) {
        if (pcntl_wifexited($status)) {
          $this->returnValue = pcntl_wexitstatus($status);
        } elseif (pcntl_wifsignaled($status)) {
          $this->returnValue = 128 + pcntl_wtermsig($status);
        } else {
          $this->returnValue = 1;
        }
        $this->pipelineIsRunning = false;
      }
    }
  }

  public function getReturnValue() {
    return $this->returnValue;
  }

  public function sendSignal($signal) {
    posix_kill($this->pgid, $signal);
  }

}
