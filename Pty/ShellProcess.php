<?php

namespace MADIR\Pty;

/** Own one interactive shell attached to a controlling pseudo terminal. */
final class ShellProcess {

  private static array $masters = [];
  private static array $closedPids = [];
  private int $master;
  private int $pid;
  private string $pending = '';
  private bool $running = true;

  /** Start the user's shell in its own session with the PTY slave as its TTY. */
  public function __construct(int $rows = 24, int $cols = 80) {
    $shell = getenv('SHELL');
    if (!is_string($shell) || $shell === '' || !is_executable($shell)) {
      $shell = '/bin/sh';
    }
    Libc::openpty($master, $slave);
    Libc::setSize($master, $rows, $cols);
    $pid = pcntl_fork();
    if ($pid < 0) {
      Libc::close($master);
      Libc::close($slave);
      throw new \RuntimeException('Could not fork the shell.');
    }
    if ($pid === 0) {
      foreach (self::$masters as $inheritedMaster => $_) {
        Libc::close($inheritedMaster);
      }
      Libc::close($master);
      if (Libc::setsid() < 0 || Libc::$instance->libc->ioctl($slave, Libc::TIOCSCTTY, 0) < 0) {
        exit(126);
      }
      Libc::dup2($slave, 0);
      Libc::dup2($slave, 1);
      Libc::dup2($slave, 2);
      if ($slave > 2) {
        Libc::close($slave);
      }
      putenv('TERM=xterm-256color');
      Libc::execvp([$shell, '-i']);
      exit(127);
    }
    Libc::close($slave);
    Libc::setNonBlocking($master);
    self::$masters[$master] = true;
    $this->master = $master;
    $this->pid = $pid;
  }

  /** Read available output and flush queued input without blocking the GUI. */
  public function poll(): string {
    if (!$this->running) {
      return '';
    }
    $libc = Libc::$instance->libc;
    $fds = $libc->new('struct pollfd[1]');
    $fds[0]->fd = $this->master;
    $fds[0]->events = 1 | ($this->pending === '' ? 0 : 4);
    $fds[0]->revents = 0;
    $libc->poll($fds, 1, 0);
    if (($fds[0]->revents & 4) !== 0) {
      $written = $libc->write($this->master, $this->pending, strlen($this->pending));
      if ($written > 0) {
        $this->pending = substr($this->pending, $written);
      }
    }
    $output = '';
    if (($fds[0]->revents & (1 | 16)) !== 0) {
      $bytes = $libc->new('char[8192]');
      while (true) {
        $count = $libc->read($this->master, $bytes, 8192);
        if ($count <= 0) {
          break;
        }
        $output .= \FFI::string($bytes, $count);
      }
    }
    $ended = pcntl_waitpid($this->pid, $status, WNOHANG);
    if ($ended === $this->pid || (($fds[0]->revents & (8 | 16 | 32)) !== 0 && $output === '')) {
      $this->running = false;
      Libc::close($this->master);
      unset(self::$masters[$this->master]);
      if ($ended !== $this->pid) {
        self::$closedPids[$this->pid] = true;
      }
    }
    return $output;
  }

  /** Queue bytes for the shell's controlling TTY. */
  public function input(string $bytes): void {
    if ($this->running) {
      $this->pending .= $bytes;
    }
  }

  /** Notify foreground programs when the pane changes size. */
  public function resize(int $rows, int $cols): void {
    if ($this->running) {
      Libc::setSize($this->master, $rows, $cols);
    }
  }

  public function isRunning(): bool {
    return $this->running;
  }

  /** Reap shells closed while the GUI remains open. */
  public static function reapClosed(): void {
    foreach (self::$closedPids as $pid => $_) {
      $result = pcntl_waitpid($pid, $status, WNOHANG);
      if ($result === $pid || $result === -1) {
        unset(self::$closedPids[$pid]);
      }
    }
  }

  /** End the shell and its foreground job when the application closes. */
  public function close(): void {
    if (!$this->running) {
      return;
    }
    posix_kill(-$this->pid, SIGHUP);
    Libc::close($this->master);
    unset(self::$masters[$this->master]);
    $this->running = false;
    self::$closedPids[$this->pid] = true;
    self::reapClosed();
  }

}
