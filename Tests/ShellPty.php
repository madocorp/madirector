<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\Pty\{Libc, ShellProcess};

new Libc();
putenv('SHELL=/bin/sh');
$shell = new ShellProcess(24, 80);
$shell->resize(17, 63);
$shell->input("printf 'TTY=%s SIZE=%s TERM=%s\\n' \"$(tty)\" \"$(stty size)\" \"\$TERM\"\nexit\n");
$output = '';
$deadline = microtime(true) + 3;
while (microtime(true) < $deadline) {
  $output .= $shell->poll();
  if (!$shell->isRunning()) {
    break;
  }
  usleep(10000);
}
$shell->close();
if (!preg_match('~TTY=/dev/pts/\d+ SIZE=17 63 TERM=xterm-256color~', $output)) {
  fwrite(STDERR, "Shell PTY test failed:\n" . $output);
  exit(1);
}
echo "Shell PTY test passed\n";
