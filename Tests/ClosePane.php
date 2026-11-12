<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\App\TerminalTile;
use SPTK\App;
use SPTK\Core\Window;
use SPTK\SDLWrapper\SDL;

/** Send a keyboard event through the current screen. */
function closePaneKey(Window $window, int $type, int $key): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => 0]]);
}

/** Send one printable command character. */
function closePaneText(Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

/** Release input so command characters operate on tiles. */
function closePaneCommandMode(Window $window): void {
  closePaneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  closePaneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
}

/** Accept the selected terminal's close confirmation. */
function closePaneConfirm(Window $window): void {
  closePaneText($window, 'x');
  $screen = $window->screen($window->currentScreenId());
  if (!$screen->statusBar?->confirming()) {
    throw new RuntimeException('Closing a terminal did not ask for confirmation.');
  }
  closePaneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
}

/** Check pane collapse, screen fallback, and the final empty screen. */
function verifyClosePane(): void {
  $window = App::eventLoop()->windows()[0];
  $screen0 = $window->screen('screen0');
  $first = $screen0->selectedLeaf();
  closePaneText($window, 'x');
  if (count($screen0->layout->leaves()) !== 2 || $screen0->statusBar !== null) {
    throw new RuntimeException('Terminal-mode x requested a close.');
  }
  closePaneCommandMode($window);
  closePaneText($window, 'h');
  $right = $screen0->selectedLeaf();
  closePaneCommandMode($window);
  closePaneText($window, 'x');
  if (!$screen0->statusBar?->confirming() || count($screen0->layout->leaves()) !== 3
    || !$right->instance()->shell()->isRunning()) {
    throw new RuntimeException('A terminal closed before confirmation.');
  }
  closePaneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE);
  if ($screen0->statusBar !== null || count($screen0->layout->leaves()) !== 3
    || !$right->instance()->shell()->isRunning()) {
    throw new RuntimeException('Cancelling terminal close did not preserve the pane.');
  }
  closePaneConfirm($window);
  if (count($screen0->layout->leaves()) !== 2 || $screen0->selectedLeaf() !== $first
    || $screen0->activeLeaf() !== null || $right->instance()->shell()->isRunning()) {
    throw new RuntimeException('Closing the right pane did not select its neighbour.');
  }
  closePaneText($window, 'j');
  $lower = $screen0->selectedLeaf();
  closePaneCommandMode($window);
  closePaneConfirm($window);
  if (count($screen0->layout->leaves()) !== 2 || $screen0->selectedLeaf() !== $first
    || $lower->instance()->shell()->isRunning()) {
    throw new RuntimeException('Closing the lower pane did not restore the top terminal.');
  }
  closePaneText($window, '3');
  $screen3 = $window->screen('screen3');
  $old3 = $screen3->selectedLeaf();
  closePaneCommandMode($window);
  closePaneConfirm($window);
  if ($window->currentScreenId() !== 'screen0' || $screen0->activeLeaf() !== $first
    || $screen3->layout->leaves()[0]->instance() instanceof TerminalTile
    || $old3->instance()->shell()->isRunning()) {
    throw new RuntimeException('Closing the last pane did not return to the previous screen.');
  }
  closePaneCommandMode($window);
  closePaneText($window, '3');
  if ($screen3->selectedLeaf() === $old3 || !$screen3->activeLeaf()?->instance() instanceof TerminalTile) {
    throw new RuntimeException('Revisiting a closed screen did not start a new terminal.');
  }
  closePaneCommandMode($window);
  closePaneText($window, '0');
  closePaneCommandMode($window);
  closePaneConfirm($window);
  if ($window->currentScreenId() !== 'screen3' || $screen0->layout->leaves()[0]->instance() instanceof TerminalTile) {
    throw new RuntimeException('Closing screen zero did not return to screen three.');
  }
  closePaneCommandMode($window);
  closePaneConfirm($window);
  if ($window->currentScreenId() !== 'screen3' || $screen3->layout->leaves()[0]->instance() instanceof TerminalTile
    || $screen3->activeLeaf() !== null) {
    throw new RuntimeException('Closing the final terminal should leave an empty command screen.');
  }
  $window->handleEvent((object)['type' => SDL::SDL_EVENT_WINDOW_CLOSE_REQUESTED]);
  if (!$screen3->statusBar?->confirming()) {
    throw new RuntimeException('The empty screen could not confirm GUI exit.');
  }
  closePaneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE);
  if ($screen3->statusBar !== null) {
    throw new RuntimeException('Cancelling GUI exit left the empty screen status visible.');
  }
  closePaneText($window, '3');
  if (!$screen3->activeLeaf()?->instance() instanceof TerminalTile) {
    throw new RuntimeException('An empty command screen did not reopen its terminal.');
  }
  $left = $screen3->selectedLeaf();
  closePaneCommandMode($window);
  closePaneText($window, 'h');
  closePaneCommandMode($window);
  closePaneText($window, 'j');
  closePaneCommandMode($window);
  closePaneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_LEFT);
  if ($screen3->selectedLeaf() !== $left) {
    throw new RuntimeException('Could not select the pane beside a nested split.');
  }
  closePaneConfirm($window);
  if (count($screen3->layout->leaves()) !== 3 || !$screen3->selectedLeaf()?->instance() instanceof TerminalTile
    || $left->instance()->shell()->isRunning()) {
    throw new RuntimeException('Closing beside a nested split did not preserve the sibling subtree.');
  }
  App::eventLoop()->quitWindow($window->id());
}

/** Run the GUI check after initialization. */
function scheduleClosePaneTest(): void {
  App::eventLoop()->after(1, 'verifyClosePane');
}

pcntl_signal(SIGALRM, 'scheduleClosePaneTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Close pane test passed\n";
