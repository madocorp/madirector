<?php

namespace MADIR\Pty;

class IO {

  const POLLIN = 0x001;
  const POLLOUT = 0x004;
  const POLLERR = 0x008;
  const POLLHUP = 0x010;
  const POLLNVAL = 0x020;

  const SCRATCH = 8192;

  private static array $in = [];
  private static array $out = [];
  private static array $inOff = [];
  private static array $scratch = [];

  private static function scratch(int $fd): \FFI\CData {
    return self::$scratch[$fd] ??= \FFI::new("char[" . self::SCRATCH . "]");
  }

  public static function queueWrite(int $fd, string $bytes): void {
    if ($bytes === '') {
      return;
    }
    self::$out[$fd] = (self::$out[$fd] ?? '') . $bytes;
  }

  public static function hasPendingWrite(int $fd): bool {
    return (self::$out[$fd] ?? '') !== '';
  }

  /** Read whatever is available now into in-buffer. Call when POLLIN. */
  public static function pumpRead(int $fd): bool {
    $libc = Libc::$instance->libc;
    $buf = self::scratch($fd);
    while (true) {
      $n = $libc->read($fd, $buf, self::SCRATCH);
      if ($n > 0) {
        $chunk = \FFI::string($buf, $n);
        self::$in[$fd] = (self::$in[$fd] ?? '') . $chunk;
        self::$inOff[$fd] ??= 0;
        continue; // keep draining until EAGAIN
      }
      if ($n === 0) { // EOF (peer closed)
        return false;
      }
      $e = Libc::errno();
      if ($e === 11) { // EAGAIN
        return true;
      }
      if ($e === 4) { // EINTR
        continue;
      }
      if ($e === 5) { // EOF
        return false;
      }
echo "read($fd) failed errno=$e\n";
      return false;
    }
  }

  /** Write as much as possible from out-buffer. Call when POLLOUT. */
  public static function pumpWrite(int $fd): bool {
    $libc = Libc::$instance->libc;
    while (true) {
      $out = self::$out[$fd] ?? '';
      if ($out === '') {
        return true;
      }
      $n = $libc->write($fd, $out, strlen($out));
      if ($n > 0) {
        if ($n === strlen($out)) {
          self::$out[$fd] = '';
          return true;
        }
        self::$out[$fd] = substr($out, $n);
        // try again; maybe still writable
        continue;
      }
      if ($n === 0) {
        // treat as would-block-ish; rare for write()
        return true;
      }
      $e = Libc::errno();
      if ($e === 11) { // EAGAIN
        return true;
      }
      if ($e === 4) { // EINTR
        continue;
      }
      if ($e === 9) { // EBADF
        return false;
      }
echo "write($fd) failed errno=$e\n";
      return false;

    }
  }

  /** Peek into in-buffer without copying too much */
  public static function inLength(int $fd): int {
    $buf = self::$in[$fd] ?? '';
    $off = self::$inOff[$fd] ?? 0;
    return max(0, strlen($buf) - $off);
  }

  /** Take exactly $n bytes from in-buffer, or '' if not enough */
  public static function take(int $fd, int $n): string {
    $buf = self::$in[$fd] ?? '';
    $off = self::$inOff[$fd] ?? 0;
    $avail = strlen($buf) - $off;
    if ($avail < $n) {
      return '';
    }
    $out = substr($buf, $off, $n);
    $off += $n;
    self::$inOff[$fd] = $off;
    // occasional compaction to avoid unbounded growth
    if ($off > 65536) {
      self::$in[$fd] = substr($buf, $off);
      self::$inOff[$fd] = 0;
    }
    return $out;
  }

  /** Peek $n bytes (without consuming), or '' if not enough */
  public static function peek(int $fd, int $n): string {
    $buf = self::$in[$fd] ?? '';
    $off = self::$inOff[$fd] ?? 0;
    $avail = strlen($buf) - $off;
    if ($avail < $n) {
      return '';
    }
    return substr($buf, $off, $n);
  }

  public static function consume(int $fd, int $n): void {
    $got = self::take($fd, $n);
  }

  public static function pollAndReceive($timeout, array $fds, ?int $terminalFd = null): array {
    $libc = Libc::$instance->libc;
    $n = count($fds);
    $pfds = $libc->new("struct pollfd[{$n}]");
    foreach ($fds as $i => $fd) {
      $pfds[$i]->fd = $fd;
      $pfds[$i]->events = self::POLLIN | (self::hasPendingWrite($fd) ? self::POLLOUT : 0);
      $pfds[$i]->revents = 0;
    }
    $rc = $libc->poll($pfds, $n, $timeout);
    if ($rc < 0) {
      $e = Libc::errno();
      if ($e === 4) { // EINTR
        return [];
      }
      throw new \Exception("poll failed errno=$e");
    }
    if ($rc === 0) { // TIMEOUT
      return [];
    }
    $alive = [];
    $terminalOut = '';
    $terminalClosed = false;
    for ($i = 0; $i < $n; $i++) {
      $alive[$i] = 1;
      $fd = $pfds[$i]->fd;
      $re = $pfds[$i]->revents;
      if ($re & (self::POLLIN | self::POLLHUP)) {
        if (!self::pumpRead($fd)) {
          if ($re & self::POLLHUP) {
            $alive[$i] = 0;
          } else {
            $alive[$i] = -1;
          }
        }
      }
      if ($re & self::POLLOUT) {
        if (!self::pumpWrite($fd)) {
          $alive[$i] = -1;
        }
      }
      if ($re & self::POLLNVAL) {
        $alive[$i] = -1;
      }
      if ($re & self::POLLERR) {
        $alive[$i] = -1;
      }
    }
    $out = [];
    $count = 0;
    foreach ($fds as $i => $fd) {
      if ($fd === $terminalFd) {
        $msg = self::drainBuffered($fd);
        if ($msg !== null) {
          $out[] = ['fd' => $fd, 'msg' => $msg, 'alive' => $alive[$i]];
        }
        continue;
      } 
      while ($count < 100) {
        $msg = Message::receive($fd);
        if ($msg === null) { // no full frame available right now
          if ($alive[$i] < 1) {
            $out[] = ['fd' => $fd, 'msg' => null, 'alive' => $alive[$i]];
          }
          break;
        }
        $out[] = ['fd' => $fd, 'msg' => $msg, 'alive' => 1];
        $count++;
      }
    }
    return $out;
  }

  public static function drainBuffered(int $fd, int $maxBytes = 65536): ?string {
    $avail = self::inLength($fd);
    if ($avail <= 0) {
      return null;
    }
    $n = min($avail, $maxBytes);
    return self::take($fd, $n); // consumes
  }

}
