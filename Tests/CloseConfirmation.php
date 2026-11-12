<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use SPTK\App;
use SPTK\SDLWrapper\SDL;

/** Exercise a native close request, its paired quit event, and cancellation. */
function verifyCloseConfirmation(): void {
  $loop = App::eventLoop();
  $window = $loop->windows()[0];
  $screen = $window->screen('screen0');
  if ($screen->statusBar !== null || $screen->layout->leaves()[1]->grid()->height !== 0) {
    throw new RuntimeException('The empty status slot must be hidden.');
  }
  $window->handleEvent((object)['type' => SDL::SDL_EVENT_WINDOW_CLOSE_REQUESTED]);
  $loopClass = new ReflectionClass($loop);
  $loopClass->getMethod('handleSdlEvent')->invoke($loop, (object)['type' => SDL::SDL_QUIT]);
  if ($loopClass->getProperty('running')->getValue($loop) !== true || !$screen->statusBar?->confirming()
    || $screen->layout->leaves()[1]->grid()->height !== 1) {
    throw new RuntimeException('The close confirmation did not remain visible.');
  }
  $window->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0]]);
  if ($screen->statusBar !== null || $screen->layout->leaves()[1]->grid()->height !== 0) {
    throw new RuntimeException('Cancelling did not hide the empty status slot.');
  }
  $window->handleEvent((object)['type' => SDL::SDL_EVENT_WINDOW_CLOSE_REQUESTED]);
  $window->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0]]);
  if ($loop->windows() !== []) {
    throw new RuntimeException('Accepting did not close the window.');
  }
}

/** Schedule the GUI check after the SPTK app has initialized. */
function scheduleCloseConfirmationTest(): void {
  App::eventLoop()->after(1, 'verifyCloseConfirmation');
}

pcntl_signal(SIGALRM, 'scheduleCloseConfirmationTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Close confirmation test passed\n";
