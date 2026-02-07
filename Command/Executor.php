<?php

namespace MADIR\Command;

use \MADIR\Pty\Libc;

class Executor {

  public $returnValue = 0;
  private $master;
  private $slave;
  private $outputFunc;
  private $pipes = [];

  public function __construct($command, $master, $slave, $outputFunc) {
    $this->master = $master;
    $this->slave = $slave;
    $this->outputFunc = $outputFunc;
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
    $output = '';
    $lastpid = end($pids);
    $processEnd = false;
    $dataRead = false;
    $data = false;
    while (!$processEnd || $dataRead) {
      $dataRead = false;
      $data = Libc::read($this->master, 8192);
      if ($data !== false && $data !== '') {
        call_user_func($this->outputFunc, $data);
        $dataRead = true;
      }
      foreach ($pids as $pid) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res === $lastpid) {
          $processEnd = true;
        }
      }
      if (!$processEnd || $dataRead) {
        usleep(10000);
      }
    }
    return pcntl_wexitstatus($status);
  }

  private function child($i, $redirects, $argv) {
    if ($i === 0) {
      Libc::setsid();
    }
    // set stdin/stdout
    $n = count($this->pipes);
    if ($i === 0) {
      Libc::dup2($this->slave, 0);
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

}
