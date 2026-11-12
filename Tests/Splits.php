<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use SPTK\App;
use SPTK\Layout\{LayoutLeaf, LayoutNode, LayoutSeparator};
use SPTK\SDLWrapper\SDL;

/** Find a visible SPTK separator between two siblings in the tile tree. */
function hasPaneSeparator(LayoutNode $layout, LayoutLeaf $before, LayoutLeaf $after): bool {
  $children = (new ReflectionProperty($layout, 'children'))->getValue($layout);
  foreach ($children as $index => $child) {
    if ($child === $before && ($children[$index + 1] ?? null) instanceof LayoutSeparator && ($children[$index + 2] ?? null) === $after) {
      $area = (new ReflectionProperty($children[$index + 1], 'area'))->getValue($children[$index + 1]);
      return $area !== null && $area->width > 0 && $area->height > 0;
    }
    if ($child instanceof LayoutNode && hasPaneSeparator($child, $before, $after)) {
      return true;
    }
  }
  return false;
}

/** Send one key event through the window's normal input routing. */
function paneKey(SPTK\Core\Window $window, int $type, int $key): void {
  $window->handleEvent((object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => 0]]);
}

/** Send a printable command character through SPTK text input. */
function paneText(SPTK\Core\Window $window, string $text): void {
  $ffi = App::sdl()->ffi;
  $event = $ffi->new('SDL_Event');
  $bytes = $ffi->new('char[2]');
  \FFI::memcpy($bytes, $text . "\0", 2);
  $event->type = SDL::SDL_EVENT_TEXT_INPUT;
  $event->text->windowID = $window->id();
  $event->text->text = \FFI::cast('char*', $bytes);
  $window->handleEvent($event);
}

/** Verify mode changes, navigation, and both split directions. */
function verifySplits(): void {
  $window = App::eventLoop()->windows()[0];
  $screen = $window->screen('screen0');
  $first = $screen->selectedLeaf();
  if ($screen->activeLeaf() !== $first) {
    throw new RuntimeException('The first terminal must start in terminal mode.');
  }
  paneText($window, 'h');
  if (count($screen->layout->leaves()) !== 2) {
    throw new RuntimeException('A split key reached command mode from the active terminal.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  if ($screen->activeLeaf() !== null || $screen->selectedLeaf() !== $first) {
    throw new RuntimeException('Double Shift did not release terminal input.');
  }
  paneText($window, ',');
  paneText($window, '|');
  paneText($window, '=');
  if (count($screen->layout->leaves()) !== 2) {
    throw new RuntimeException('An old split symbol still created a pane.');
  }
  paneText($window, 'h');
  $leaves = $screen->layout->leaves();
  $right = $screen->selectedLeaf();
  if (count($leaves) !== 3 || $screen->activeLeaf() !== $right || $right === $first
    || $right->grid()->x <= $first->grid()->x || !hasPaneSeparator($screen->layout, $first, $right)) {
    throw new RuntimeException('Horizontal split did not place a new active pane on the right.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_LEFT);
  if ($screen->selectedLeaf() !== $first || $screen->activeLeaf() !== null) {
    throw new RuntimeException('SPTK did not navigate to the left pane in command mode.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  if ($screen->activeLeaf() !== $first) {
    throw new RuntimeException('Return did not return input to the selected terminal.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneText($window, 'j');
  $leaves = $screen->layout->leaves();
  $lower = $screen->selectedLeaf();
  if (count($leaves) !== 4 || $screen->activeLeaf() !== $lower || $lower->grid()->y <= $first->grid()->y
    || !hasPaneSeparator($screen->layout, $first, $lower)) {
    throw new RuntimeException('Vertical split did not place a new active pane below the selected pane.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  $fullLayout = $screen->layout;
  $zoomedLeaf = $screen->selectedLeaf();
  paneText($window, 'z');
  if ($screen->layout !== $fullLayout) {
    throw new RuntimeException('Terminal-mode z changed the layout.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  $originalGrids = [];
  foreach ($fullLayout->leaves() as $leaf) {
    $originalGrids[spl_object_id($leaf)] = [$leaf->grid()->x, $leaf->grid()->y, $leaf->grid()->width, $leaf->grid()->height];
  }
  paneText($window, 'z');
  if ($screen->layout === $fullLayout || count($screen->layout->leaves()) !== 2
    || $screen->selectedLeaf() !== $zoomedLeaf || $screen->activeLeaf() !== $zoomedLeaf
    || $zoomedLeaf->grid()->width !== $screen->layout->grid()->width
    || $zoomedLeaf->grid()->height !== $screen->layout->grid()->height) {
    throw new RuntimeException('Command-mode z did not maximize and activate the selected terminal.');
  }
  paneText($window, 'z');
  if ($screen->layout === $fullLayout) {
    throw new RuntimeException('Terminal-mode z restored the layout.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneText($window, '1');
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneText($window, '0');
  if ($screen->layout === $fullLayout) {
    throw new RuntimeException('Switching screens lost the zoom state.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneText($window, 'z');
  if ($screen->layout !== $fullLayout || $screen->selectedLeaf() !== $zoomedLeaf || count($fullLayout->leaves()) !== 4) {
    throw new RuntimeException('The second z did not restore the layout.');
  }
  foreach ($fullLayout->leaves() as $leaf) {
    if ([$leaf->grid()->x, $leaf->grid()->y, $leaf->grid()->width, $leaf->grid()->height] !== $originalGrids[spl_object_id($leaf)]) {
      throw new RuntimeException('Restoring zoom changed the pane sizes.');
    }
  }
  paneText($window, 'z');
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneKey($window, SDL::SDL_EVENT_KEY_UP, SDL::KEY_LSHIFT);
  paneText($window, 'x');
  if (!$screen->statusBar?->confirming()) {
    throw new RuntimeException('Closing a zoomed terminal did not ask for confirmation.');
  }
  paneKey($window, SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_RETURN);
  if ($screen->layout !== $fullLayout || count($fullLayout->leaves()) !== 3
    || $screen->activeLeaf() !== null || $screen->selectedLeaf() === $zoomedLeaf) {
    throw new RuntimeException('Closing a zoomed terminal did not restore and select a neighboring pane.');
  }
  App::eventLoop()->quitWindow($window->id());
}

/** Schedule the GUI check after app initialization. */
function scheduleSplitTest(): void {
  App::eventLoop()->after(1, 'verifySplits');
}

pcntl_signal(SIGALRM, 'scheduleSplitTest');
pcntl_alarm(1);
new MADIR\Pty\Libc();
new App();
echo "Split and mode test passed\n";
