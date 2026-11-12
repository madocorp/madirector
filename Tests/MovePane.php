<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use SPTK\App;
use SPTK\Core\Window;
use SPTK\SDLWrapper\SDL;

function moveKey(Window $window, int $type, int $key, int $mod = 0): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => $mod]]);
}

function moveText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

function moveCommandMode(Window $window): void {
  moveKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  moveKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Check that swapping follows navigation and moving preserves the shell. */
function verifyMovePane(): void {
  $window = App::eventLoop()->windows()[0];
  $source = $window->screen('screen0');
  $first = $source->selectedLeaf();
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RIGHT, SDL::MOD_SHIFT);
  if ($source->selectedLeaf() !== $first) {
    throw new RuntimeException('Shift+arrow operated in terminal mode.');
  }
  moveCommandMode($window);
  moveText($window, 'h');
  $second = $source->selectedLeaf();
  moveCommandMode($window);
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_LEFT, SDL::MOD_SHIFT);
  if ($source->selectedLeaf() !== $second || $second->grid()->x >= $first->grid()->x
    || $source->activeLeaf() !== null) {
    throw new RuntimeException('Shift+left did not swap the selected terminal to the left.');
  }
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RIGHT, SDL::MOD_SHIFT);
  if ($second->grid()->x <= $first->grid()->x || $source->selectedLeaf() !== $second) {
    throw new RuntimeException('Shift+right did not swap the terminal back.');
  }
  moveText($window, 'j');
  $third = $source->selectedLeaf();
  moveCommandMode($window);
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_UP, SDL::MOD_SHIFT);
  if ($third->grid()->y >= $second->grid()->y || $source->selectedLeaf() !== $third) {
    throw new RuntimeException('Shift+up did not swap the selected terminal upward.');
  }
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_DOWN, SDL::MOD_SHIFT);
  if ($third->grid()->y <= $second->grid()->y || $source->selectedLeaf() !== $third) {
    throw new RuntimeException('Shift+down did not swap the terminal back.');
  }
  moveText($window, 'x');
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  if ($source->selectedLeaf() !== $second || $third->instance()->shell()->isRunning()) {
    throw new RuntimeException('Closing the vertical test pane did not restore its neighbor.');
  }
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('2'), SDL::MOD_SHIFT);
  moveText($window, '@');
  $destination = $window->screen('screen2');
  if ($window->currentScreenId() !== 'screen2' || $destination->selectedLeaf() !== $second
    || $destination->activeLeaf() !== $second || $source->selectedLeaf() !== $first
    || count($source->layout->leaves()) !== 2 || !$second->instance()->shell()->isRunning()) {
    throw new RuntimeException('Shift+2 did not transfer the live terminal to the empty screen.');
  }
  moveCommandMode($window);
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('0'), SDL::MOD_SHIFT);
  if ($window->currentScreenId() !== 'screen2' || $destination->selectedLeaf() !== $second) {
    throw new RuntimeException('Shift+0 moved a terminal into an occupied screen.');
  }
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('3'), SDL::MOD_SHIFT);
  moveText($window, '+');
  if ($window->currentScreenId() !== 'screen3' || $window->screen('screen3')->selectedLeaf() !== $second
    || count($destination->layout->leaves()) !== 2 || !$second->instance()->shell()->isRunning()) {
    throw new RuntimeException('Moving the last terminal did not leave its old screen empty.');
  }
  moveCommandMode($window);
  moveText($window, '4');
  $screen4 = $window->screen('screen4');
  moveCommandMode($window);
  moveText($window, 'x');
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  if ($window->currentScreenId() !== 'screen3') {
    throw new RuntimeException('Closing screen four did not return to screen three.');
  }
  moveCommandMode($window);
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('4'), SDL::MOD_SHIFT);
  moveText($window, '$');
  if ($window->currentScreenId() !== 'screen4' || $screen4->selectedLeaf() !== $second
    || $screen4->activeLeaf() !== $second || !$second->instance()->shell()->isRunning()) {
    throw new RuntimeException('Moving into a previously used empty screen did not keep the terminal.');
  }
  moveCommandMode($window);
  moveKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('7'), SDL::MOD_SHIFT);
  moveText($window, '=');
  if ($window->currentScreenId() !== 'screen7' || count($window->screen('screen7')->layout->leaves()) !== 2
    || $window->screen('screen7')->selectedLeaf() !== $second) {
    throw new RuntimeException('Shift+7 conflicted with the former equals split shortcut.');
  }
  App::eventLoop()->quitWindow($window->id());
}

function scheduleMovePaneTest(): void {
  App::eventLoop()->after(1, 'verifyMovePane');
}

pcntl_signal(SIGALRM, 'scheduleMovePaneTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Move pane test passed\n";
