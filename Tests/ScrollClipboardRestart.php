<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use SPTK\App;
use SPTK\Core\{Clipboard, Window};
use SPTK\SDLWrapper\SDL;

function scrollKey(Window $window, int $type, int $key, int $mod = 0): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => $mod]]);
}

function scrollText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

function scrollCommandMode(Window $window): void {
  scrollKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  scrollKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Read input queued for a PTY before the next timer poll. */
function scrollQueuedInput(MADIR\App\RunningShell $shell): string {
  $process = (new ReflectionProperty($shell, 'process'))->getValue($shell);
  return (new ReflectionProperty($process, 'pending'))->getValue($process);
}

/** Verify browsing, clipboard copy and paste, and restarting an exited pane. */
function verifyScrollClipboardRestart(): void {
  $window = App::eventLoop()->windows()[0];
  $screen = $window->screen('screen0');
  $leaf = $screen->selectedLeaf();
  $shell = $leaf->instance()->shell();
  $shell->buffer()->parse("COPYMARK\r\n");
  scrollCommandMode($window);
  scrollText($window, 'b');
  if ($screen->activeLeaf() !== $leaf || !$leaf->instance()->scrolling()) {
    throw new RuntimeException('Browse did not activate the scroll cursor.');
  }
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('a'), SDL::MOD_CTRL);
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('c'), SDL::MOD_CTRL);
  if (!str_contains(Clipboard::get(), 'COPYMARK')) {
    throw new RuntimeException('Ctrl+C did not copy selected terminal output.');
  }
  Clipboard::set('replace-me');
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, ord('c'));
  if (!str_contains(Clipboard::get(), 'COPYMARK')) {
    throw new RuntimeException('Scroll-mode c did not copy selected terminal output.');
  }
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE);
  if ($screen->activeLeaf() !== null || $leaf->instance()->scrolling()) {
    throw new RuntimeException('Escape did not leave scroll mode for command mode.');
  }
  Clipboard::set('replace-me');
  scrollText($window, 'c');
  if (!str_contains(Clipboard::get(), 'COPYMARK') || $screen->activeLeaf() !== null) {
    throw new RuntimeException('Command-mode c did not copy the last scroll selection.');
  }
  scrollText($window, 'b');
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  if ($screen->activeLeaf() !== $leaf || $leaf->instance()->scrolling()) {
    throw new RuntimeException('Return did not resume terminal input from scroll mode.');
  }
  scrollCommandMode($window);
  Clipboard::set('PASTEMARK');
  scrollText($window, 'v');
  if ($screen->activeLeaf() !== $leaf || !str_ends_with(scrollQueuedInput($shell), 'PASTEMARK')) {
    throw new RuntimeException('Command-mode v did not paste and activate the terminal.');
  }
  scrollCommandMode($window);
  $shell->close();
  scrollText($window, 'b');
  if ($screen->activeLeaf() !== $leaf || !$leaf->instance()->scrolling()) {
    throw new RuntimeException('Browse cannot open an exited terminal.');
  }
  scrollKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE);
  $oldLayout = $screen->layout;
  scrollText($window, 'r');
  $replacement = $screen->selectedLeaf();
  if ($screen->layout !== $oldLayout || $replacement === $leaf
    || $screen->activeLeaf() !== $replacement || !$replacement->instance()->shell()->isRunning()) {
    throw new RuntimeException('Restart did not replace the exited shell in its pane.');
  }
  App::eventLoop()->quitWindow($window->id());
}

function scheduleScrollClipboardRestartTest(): void {
  App::eventLoop()->after(1, 'verifyScrollClipboardRestart');
}

pcntl_signal(SIGALRM, 'scheduleScrollClipboardRestartTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Scroll, clipboard, and restart test passed\n";
