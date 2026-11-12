<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\App\TerminalTile;
use SPTK\App;
use SPTK\Core\Window;
use SPTK\SDLWrapper\SDL;

/** Deliver one key event to the current screen. */
function screenKey(Window $window, int $type, int $key): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => 0]]);
}

/** Deliver one printable character to the current screen. */
function screenText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

/** Enter command mode from a terminal. */
function releaseScreenInput(Window $window): void {
  screenKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  screenKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Verify lazy creation and retained pane state for numbered screens. */
function verifyNumberedScreens(): void {
  $window = App::eventLoop()->windows()[0];
  $screen0 = $window->screen('screen0');
  $first = $screen0->selectedLeaf();
  if ($window->currentScreenId() !== 'screen0' || !$first?->instance() instanceof TerminalTile
    || $window->screen('screen3')->layout->leaves()[0]->instance() instanceof TerminalTile) {
    throw new RuntimeException('Only screen zero should start with a shell.');
  }
  screenText($window, '3');
  if ($window->currentScreenId() !== 'screen0') {
    throw new RuntimeException('A terminal-mode digit switched screens.');
  }
  releaseScreenInput($window);
  screenText($window, '3');
  $screen3 = $window->screen('screen3');
  if ($window->currentScreenId() !== 'screen3' || !$screen3->activeLeaf()?->instance() instanceof TerminalTile
    || $window->screen('screen1')->layout->leaves()[0]->instance() instanceof TerminalTile) {
    throw new RuntimeException('Screen three was not created on first use.');
  }
  releaseScreenInput($window);
  screenText($window, 'h');
  $selected3 = $screen3->selectedLeaf();
  if (count($screen3->layout->leaves()) !== 3) {
    throw new RuntimeException('Screen three did not keep its split.');
  }
  releaseScreenInput($window);
  screenText($window, '9');
  if ($window->currentScreenId() !== 'screen9' || count($window->screen('screen9')->layout->leaves()) !== 2) {
    throw new RuntimeException('Screen nine did not start with one terminal.');
  }
  releaseScreenInput($window);
  screenText($window, '3');
  if ($window->currentScreenId() !== 'screen3' || $screen3->selectedLeaf() !== $selected3
    || $screen3->activeLeaf() !== $selected3 || count($screen3->layout->leaves()) !== 3) {
    throw new RuntimeException('Switching back did not restore screen three.');
  }
  releaseScreenInput($window);
  screenText($window, '0');
  if ($window->currentScreenId() !== 'screen0' || $screen0->selectedLeaf() !== $first
    || $screen0->activeLeaf() !== $first) {
    throw new RuntimeException('Zero did not select the original first screen.');
  }
  App::eventLoop()->quitWindow($window->id());
}

/** Run the GUI check after initialization. */
function scheduleNumberedScreenTest(): void {
  App::eventLoop()->after(1, 'verifyNumberedScreens');
}

pcntl_signal(SIGALRM, 'scheduleNumberedScreenTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Numbered screen test passed\n";
