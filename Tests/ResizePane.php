<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use SPTK\App;
use SPTK\Core\Window;
use SPTK\SDLWrapper\SDL;

/** Send a key through SPTK's window input path. */
function resizeKey(Window $window, int $type, int $key): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => 0]]);
}

/** Send a printable resize or split character. */
function resizeText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

/** Leave terminal input with two Shift releases. */
function resizeCommandMode(Window $window): void {
  resizeKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  resizeKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Verify both axis mappings, keypad delivery, and nested pane resizing. */
function verifyResizePane(): void {
  $window = App::eventLoop()->windows()[0];
  $screen = $window->screen('screen0');
  $left = $screen->selectedLeaf();
  resizeCommandMode($window);
  resizeText($window, 'h');
  $right = $screen->selectedLeaf();
  resizeCommandMode($window);
  $leftWidth = $left->grid()->width;
  $rightWidth = $right->grid()->width;
  foreach (['+', '-', '*', '/'] as $oldShortcut) {
    resizeText($window, $oldShortcut);
  }
  if ($left->grid()->width !== $leftWidth || $right->grid()->width !== $rightWidth) {
    throw new RuntimeException('A printable operator still resized a pane.');
  }
  resizeText($window, 'w');
  if ($right->grid()->width !== $rightWidth + 1 || $left->grid()->width !== $leftWidth - 1) {
    throw new RuntimeException('Wider did not grow the last pane toward the left.');
  }
  resizeText($window, 'n');
  if ($right->grid()->width !== $rightWidth || count($screen->layout->leaves()) !== 3) {
    throw new RuntimeException('Narrower did not restore width.');
  }
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_LEFT);
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_KP_MULTIPLY);
  resizeText($window, '*');
  if ($left->grid()->width !== $leftWidth + 1) {
    throw new RuntimeException('Keypad multiply resized twice or used the wrong edge.');
  }
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_KP_DIVIDE);
  resizeText($window, '/');
  if ($left->grid()->width !== $leftWidth) {
    throw new RuntimeException('Keypad divide did not shrink exactly once.');
  }
  $leftHeight = $left->grid()->height;
  resizeText($window, 't');
  if ($left->grid()->height !== $leftHeight || $left->grid()->width !== $leftWidth) {
    throw new RuntimeException('Taller changed width when no height split exists.');
  }
  resizeText($window, 's');
  if (count($screen->layout->leaves()) !== 3 || $left->grid()->width !== $leftWidth) {
    throw new RuntimeException('Shorter created a split or changed width.');
  }
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RIGHT);
  resizeText($window, 'j');
  $lower = $screen->selectedLeaf();
  resizeCommandMode($window);
  $topHeight = $right->grid()->height;
  $lowerHeight = $lower->grid()->height;
  resizeText($window, 't');
  if ($lower->grid()->height !== $lowerHeight + 1 || $right->grid()->height !== $topHeight - 1) {
    throw new RuntimeException('Taller did not grow the last pane upward.');
  }
  resizeText($window, 's');
  if ($lower->grid()->height !== $lowerHeight) {
    throw new RuntimeException('Shorter did not shrink height.');
  }
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_UP);
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_KP_PLUS);
  resizeText($window, '+');
  if ($right->grid()->height !== $topHeight + 1) {
    throw new RuntimeException('Keypad plus resized twice or used the wrong edge.');
  }
  resizeKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_KP_MINUS);
  resizeText($window, '-');
  if ($right->grid()->height !== $topHeight) {
    throw new RuntimeException('Keypad minus did not shrink exactly once.');
  }
  $branchWidth = $right->grid()->width;
  resizeText($window, 'w');
  if ($right->grid()->width !== $branchWidth + 1 || $lower->grid()->width !== $branchWidth + 1) {
    throw new RuntimeException('Horizontal resizing did not move the nested branch.');
  }
  App::eventLoop()->quitWindow($window->id());
}

/** Run the GUI check after initialization. */
function scheduleResizePaneTest(): void {
  App::eventLoop()->after(1, 'verifyResizePane');
}

pcntl_signal(SIGALRM, 'scheduleResizePaneTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Resize pane test passed\n";
