<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\App\RunningShell;
use SPTK\App;
use SPTK\Core\{Clipboard, Window};
use SPTK\SDLWrapper\SDL;

function multiplexKey(Window $window, int $type, int $key, int $mod = 0): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => $mod]]);
}

function multiplexText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

function multiplexCommandMode(Window $window): void {
  multiplexKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  multiplexKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Read queued PTY bytes before the next event-loop poll. */
function queuedInput(RunningShell $shell): string {
  $process = (new ReflectionProperty($shell, 'process'))->getValue($shell);
  return (new ReflectionProperty($process, 'pending'))->getValue($process);
}

/** Verify group input across panes, splits, and numbered screens. */
function verifyMultiplexInput(): void {
  $window = App::eventLoop()->windows()[0];
  $screen0 = $window->screen('screen0');
  $first = $screen0->selectedLeaf();
  multiplexCommandMode($window);
  multiplexText($window, 'h');
  $second = $screen0->selectedLeaf();
  multiplexCommandMode($window);
  multiplexText($window, 'm');
  if ($screen0->activeLeaf() !== $second) {
    throw new RuntimeException('Enabling multiplex did not activate the selected terminal.');
  }
  multiplexText($window, 'x');
  if (!str_ends_with(queuedInput($first->instance()->shell()), 'x')
    || !str_ends_with(queuedInput($second->instance()->shell()), 'x')) {
    throw new RuntimeException('Multiplex input did not reach both terminals.');
  }
  multiplexCommandMode($window);
  multiplexText($window, 'm');
  if ($screen0->activeLeaf() !== null) {
    throw new RuntimeException('Turning multiplex off left the terminal active in command mode.');
  }
  $firstPending = queuedInput($first->instance()->shell());
  multiplexKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  multiplexText($window, 'y');
  if (queuedInput($first->instance()->shell()) !== $firstPending
    || !str_ends_with(queuedInput($second->instance()->shell()), 'xy')) {
    throw new RuntimeException('Disabling multiplex did not restore single-terminal input.');
  }
  multiplexCommandMode($window);
  multiplexText($window, 'm');
  multiplexCommandMode($window);
  multiplexText($window, 'h');
  $third = $screen0->selectedLeaf();
  multiplexText($window, 'q');
  foreach ([$first, $second, $third] as $leaf) {
    if (!str_ends_with(queuedInput($leaf->instance()->shell()), 'q')) {
      throw new RuntimeException('A terminal created during multiplex missed group input.');
    }
  }
  multiplexCommandMode($window);
  multiplexText($window, '1');
  $screen1 = $window->screen('screen1');
  $firstPending = queuedInput($first->instance()->shell());
  multiplexText($window, 'r');
  if (!str_ends_with(queuedInput($screen1->selectedLeaf()->instance()->shell()), 'r')
    || queuedInput($first->instance()->shell()) !== $firstPending) {
    throw new RuntimeException('Multiplex input crossed into another screen.');
  }
  multiplexCommandMode($window);
  multiplexText($window, '0');
  multiplexText($window, 's');
  foreach ([$first, $second, $third] as $leaf) {
    if (!str_ends_with(queuedInput($leaf->instance()->shell()), 'qs')) {
      throw new RuntimeException('Switching screens lost multiplex input state.');
    }
  }
  multiplexCommandMode($window);
  Clipboard::set('P');
  multiplexText($window, 'v');
  foreach ([$first, $second, $third] as $leaf) {
    if (!str_ends_with(queuedInput($leaf->instance()->shell()), 'qsP')) {
      throw new RuntimeException('Command-mode paste missed a multiplexed terminal.');
    }
  }
  multiplexCommandMode($window);
  multiplexKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('2'), SDL::MOD_SHIFT);
  multiplexText($window, '@');
  $groupPending = queuedInput($first->instance()->shell());
  multiplexText($window, 'u');
  if (queuedInput($first->instance()->shell()) !== $groupPending
    || !str_ends_with(queuedInput($third->instance()->shell()), 'u')) {
    throw new RuntimeException('A terminal moved out of a multiplex screen kept broadcasting to its old group.');
  }
  multiplexCommandMode($window);
  multiplexText($window, '0');
  multiplexText($window, 'v');
  if (!str_ends_with(queuedInput($first->instance()->shell()), 'qsPv')
    || !str_ends_with(queuedInput($second->instance()->shell()), 'qsPv')
    || str_ends_with(queuedInput($third->instance()->shell()), 'v')) {
    throw new RuntimeException('Moving a terminal changed the source screen multiplex group.');
  }
  App::eventLoop()->quitWindow($window->id());
}

function scheduleMultiplexInputTest(): void {
  App::eventLoop()->after(1, 'verifyMultiplexInput');
}

pcntl_signal(SIGALRM, 'scheduleMultiplexInputTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Multiplex input test passed\n";
