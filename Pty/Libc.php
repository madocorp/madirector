<?php

namespace MADIR\Pty;

class Libc {

  const F_GETFL = 3;
  const F_SETFL = 4;

  const O_RDONLY = 0x0;
  const O_WRONLY = 0x1;
  const O_RDWR = 0x2;
  const O_CREAT = 0x40;
  const O_TRUNC = 0x200;
  const O_APPEND = 0x400;
  const O_NONBLOCK = 0x800;

  const AF_UNIX = 1;
  const SOCK_STREAM = 1;

  const TCSANOW = 0;
  const ICANON = 0000002;
  const ECHO = 0000010;
  const ISIG = 0000001;
  const IXON = 0002000;
  const ICRNL = 0000400;
  const OPOST = 0000001;

  const POLLIN = 0x001;
  const POLLOUT = 0x004;
  const POLLERR = 0x008;
  const POLLHUP = 0x010;
  const POLLNVAL = 0x020;

  const TIOCSCTTY = 0x540E;
  const TIOCSWINSZ = 0x5414;
  const TIOCGWINSZ = 0x5413;

  const SCRATCH = 8192;

  public static $instance;

  private static array $in = [];
  private static array $out = [];
  private static array $inOff = [];
  private static array $scratch = [];

  public $libc;

  public function __construct() {
    if (!is_null(self::$instance)) {
      die("MADIR\\Libc is a singleton, you can't instantiate more than once");
    }
    self::$instance = $this;
    $this->libc = \FFI::cdef(file_get_contents(dirname(APP_PATH) . "/Pty/libc_extract.h"), "libc.so.6");
  }

  public static function setsid() {
    $libc = self::$instance->libc;
    return $libc->setsid();
  }

  public static function openpty(&$master, &$slave) {
    $libc = self::$instance->libc;
    $amaster = \FFI::new("int");
    $aslave  = \FFI::new("int");
    if ($libc->openpty(\FFI::addr($amaster), \FFI::addr($aslave), null, null, null) !== 0) {
      die("openpty failed\n");
    }
    $master = $amaster->cdata;
    $slave = $aslave->cdata;
  }

  public static function open($filename) {
    $libc = self::$instance->libc;
  }

  public static function dup2($oldfd, $newfd) {
    $libc = self::$instance->libc;
    $libc->dup2($oldfd, $newfd);
  }

  public static function socketpair() {
    $libc = self::$instance->libc;
    $sv = \FFI::new("int[2]");
    $libc->socketpair(self::AF_UNIX, self::SOCK_STREAM, 0, $sv);
    return [$sv[0], $sv[1]];
  }

  public static function errno(): int {
    $libc = self::$instance->libc;
    $ptr = $libc->__errno_location();
    return $ptr[0];
  }

  public static function close($fd) {
    $libc = self::$instance->libc;
    $libc->close($fd);
  }

  public static function execvp($argvp) {
    $libc = self::$instance->libc;
    $file = $argvp[0]; // fix path
    $argc = count($argvp) + 1;
    $argv = \FFI::new("char*[{$argc}]");
    $str = [];
    foreach ($argvp as $i => $item) {
      $len = strlen($item) + 1;
      $str[$i] = \FFI::new("char[{$len}]");
      \FFI::memcpy($str[$i], "{$item}\0", $len);
      $argv[$i] = \FFI::cast("char*", $str[$i]);
    }
    $argv[$argc - 1] = null;
    $libc->execvp($file, $argv);
  }

  public static function setNonBlocking($fd) {
    $libc = self::$instance->libc;
    $flags = $libc->fcntl($fd, self::F_GETFL, 0);
    if ($flags < 0) {
      die("fcntl get failed");
    }
    if ($libc->fcntl($fd, self::F_SETFL, $flags | self::O_NONBLOCK) < 0) {
      die("fcntl set failed");
    }
  }

  public static function setRawMode(int $fd) {
    $libc = self::$instance->libc;
    $t = $libc->new("struct termios");
    if ($libc->tcgetattr($fd, \FFI::addr($t)) !== 0) {
      throw new \Exception("tcgetattr failed");
    }
    // ---- input flags ----
    $t->c_iflag &= ~(self::IXON | self::ICRNL);
    // ---- output flags ----
    $t->c_oflag &= ~(self::OPOST);
    // ---- local flags ----
    $t->c_lflag &= ~(self::ICANON | self::ECHO | self::ISIG);
    // ---- control chars ----
    // read returns immediately
    $t->c_cc[6] = 1;  // VMIN
    $t->c_cc[5] = 0;  // VTIME
    if ($libc->tcsetattr($fd, self::TCSANOW, \FFI::addr($t)) !== 0) {
      throw new \Exception("tcsetattr failed");
    }
  }

  public static function setSize(int $fd, int $rows, int $cols): void {
    $libc = self::$instance->libc;
    $ws = $libc->new("struct winsize");
    $ws->ws_row = $rows;
    $ws->ws_col = $cols;
    $ws->ws_xpixel = 0;
    $ws->ws_ypixel = 0;
    $ret = $libc->ioctl($fd, self::TIOCSWINSZ, \FFI::addr($ws));
    if ($ret !== 0) {
      $err = self::errno();
      throw new \Exception("ioctl(TIOCSWINSZ) failed, errno={$err}");
    }
  }

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
    $libc = self::$instance->libc;
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
echo "EOF on fd=$fd\n";
        return false;
      }
      $e = self::errno();
      if ($e === 11) { // EAGAIN
        return true;
      }
      if ($e === 4) { // EINTR
        continue;
      }
echo "read($fd) failed errno=$e\n";
      return false;
    }
  }

  /** Write as much as possible from out-buffer. Call when POLLOUT. */
  public static function pumpWrite(int $fd): bool {
    $libc = self::$instance->libc;
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
      $e = self::errno();
      if ($e === 11) { // EAGAIN
        return true;
      }
      if ($e === 4) { // EINTR
        continue;
      }
      if ($e === 9) { // EBADF
echo "EBADF on write($fd)\n";
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
    $libc = self::$instance->libc;
    $n = count($fds);
    $pfds = $libc->new("struct pollfd[{$n}]");
    foreach ($fds as $i => $fd) {
      $pfds[$i]->fd = $fd;
      $pfds[$i]->events = self::POLLIN | (self::hasPendingWrite($fd) ? self::POLLOUT : 0);
      $pfds[$i]->revents = 0;
    }
    $rc = $libc->poll($pfds, $n, $timeout);
    if ($rc < 0) {
      $e = self::errno();
      if ($e === 4) { // EINTR
        return [];
      }
      throw new \Exception("poll failed errno=$e");
    }
    if ($rc === 0) { // TIMEOUT
      return [];
    }
    $err = [];
    for ($i = 0; $i < $n; $i++) {
      $fd = $pfds[$i]->fd;
      $re = $pfds[$i]->revents;
      if ($re & self::POLLIN) {
        if (!self::pumpRead($fd)) {
          $err[$fd] = true;
        }
      }
      if ($re & self::POLLOUT) {
        if (!self::pumpWrite($fd)) {
          $err[$fd] = true;
        }
      }
      if ($re & self::POLLNVAL) {
echo "POLLNVAL fd=$fd";
      }
      if ($re & self::POLLERR) {
echo "POLLERR fd=$fd";
      }
      if ($re & self::POLLHUP) {
echo "POLLHUP fd=$fd";
        // HUP does not mean “no more data” immediately; still drain POLLIN if set.
        // If you want, mark for shutdown once buffers empty.
      }
    }
    $out = [];
    $count = 0;
    foreach ($fds as $i => $fd) {
      if (isset($err[$fd])) {
        $out[] = ['fd' => $fd, 'msg' => false];
        continue;
      }
      if ($i === $terminalFd) {
    //    self::readAvailable($fd);
        continue;
      }
      while ($count < 100) {
        $msg = Message::receive($fd);
        if ($msg === null) { // no full frame available right now
          break;
        }
        $out[] = ['fd' => $fd, 'msg' => $msg];
        $count++;
      }
    }
echo "RECEIVED {$count}\n";
    return $out;
  }

  public static function readAvailable($fd): string {
    $libc = self::$instance->libc;
    $max = 8192;
    $buf = $libc->new("char[{$max}]");
    $n = $libc->read($fd, $buf, $max);
    if ($n > 0) {
      return \FFI::string($buf, $n);
    }
    if ($n === 0) {
echo "PTY EOF fd={$this->master}\n";
      return false;
    }
    $e = self::errno();
    if ($e === 11) { // EAGAIN
      return '';
    }
    if ($e === 4) { // EINTR
      return '';
    }
echo "PTY read failed errno={$e}\n";
  }

}
