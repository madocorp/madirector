<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\Screen\ScreenBuffer;

$buffer = new ScreenBuffer(2, 12);
set_error_handler(static function(int $severity, string $message): never {
  throw new ErrorException($message, 0, $severity);
});
try {
  $buffer->parse("\e[38;2;12;34;56mA\e[48;2;1;2;3mB\e[38;2mC\e[38mD");
} finally {
  restore_error_handler();
}
$row = $buffer->getRows()[0];
if ($row[0][ScreenBuffer::FG] !== 0x0c2238 || $row[1][ScreenBuffer::BG] !== 0x010203) {
  throw new RuntimeException('Truecolor SGR must preserve RGB component order.');
}

echo "Terminal ANSI checks passed\n";
