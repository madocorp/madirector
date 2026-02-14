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

  const PF_UNIX = 1;
  const SOCK_STREAM = 1;

  const TCSANOW = 0;
  const ICANON = 0000002;
  const ECHO = 0000010;
  const ISIG = 0000001;
  const IXON = 0002000;
  const ICRNL = 0000400;
  const OPOST = 0000001;

  public static $instance;

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
    $libc->socketpair(self::PF_UNIX, self::SOCK_STREAM, 0, $sv);
    return [$sv[0], $sv[1]];
  }

  public static function read($fd, $len) {
    $libc = self::$instance->libc;
    $buf = \FFI::new("char[{$len}]");
    $n = $libc->read($fd, $buf, $len);
    if ($n > 0) {
      $data = \FFI::string($buf, $n);
    } else if ($n === 0) {
      $data = false; // EOF
    } else {
      $data = ''; // EAGAIN or ERROR
    }
    return $data;
  }

  public static function write($fd, $str) {
    $libc = self::$instance->libc;
    return $libc->write($fd, $str, strlen($str));
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

}
