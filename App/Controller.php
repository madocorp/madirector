<?php

namespace MADIR\App;

use MADIR\Pty\ShellProcess;
use SPTK\Core\{Clipboard, Color, Screen, Style, WidgetSelection, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\{LayoutLeaf, LayoutNode, LayoutSeparator};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\StatusBar\StatusBar;

/** Manage numbered screens, terminal panes, and command mode. */
final class Controller {

  private static ?Window $window = null;
  private static array $shells = [];
  private static array $screenStates = [];
  private static array $screenHistory = [];
  private static float $lastShiftRelease = 0.0;
  private static ?string $keypadText = null;
  private static float $keypadTime = 0.0;
  private static bool $suppressMoveText = false;

  /** Open screen zero with its first live shell. */
  public static function init(EventContext $event): void {
    self::$window = \SPTK\App::eventLoop()->windows()[0];
    self::initializeScreen(0);
    self::$screenHistory = [0];
    self::updateTitle(0);
    self::$window->setCloseRequestHandler(self::requestClose(...));
    self::$window->refreshLayout();
  }

  /** Start a screen's shell only when that screen is first used. */
  private static function initializeScreen(int $index, ?LayoutLeaf $initialLeaf = null): void {
    $screen = self::$window->screen('screen' . $index);
    $placeholder = $screen->layout->leaves()[0];
    $hiddenStatus = $screen->layout->leaves()[1];
    $style = new Style(background: new Color(0, 0, 0), foreground: new Color(255, 255, 255));
    $status = new StatusBar($style);
    $status->setId('status');
    $visibleStatus = new LayoutLeaf('StatusBar', '1*', '1', $status, navigate: false);
    $leaf = $initialLeaf ?? self::newTerminalLeaf($index);
    $screen->layout->replaceChild($placeholder, $leaf);
    $screen->setLayout($screen->layout);
    $screen->activateLeaf($leaf);
    $screen->setInputGuard(self::guardInputMode(...));
    self::$screenStates[$index] = ['screen' => $screen, 'placeholder' => $placeholder,
      'hiddenStatus' => $hiddenStatus, 'visibleStatus' => $visibleStatus,
      'status' => $status, 'hasTerminal' => true, 'fullLayout' => null, 'multiplex' => false];
  }

  /** Poll all shells, repainting only when the visible screen changes. */
  public static function tick(EventContext $event): void {
    if (self::$window === null) {
      return;
    }
    ShellProcess::reapClosed();
    $current = self::currentIndex();
    $changed = false;
    foreach (self::$shells as $index => $group) {
      foreach ($group as $shell) {
        if ($shell->poll() && $index === $current) {
          $changed = true;
        }
      }
    }
    $screen = self::$screenStates[$current]['screen'];
    if ($screen->activeLeaf()?->instance() instanceof TerminalTile && !$screen->activeLeaf()->instance()->isRunning()) {
      $screen->release();
      $changed = true;
    }
    if ($changed) {
      self::$window->refreshLayout();
    }
  }

  /** Release terminal input after two quick Shift releases. */
  private static function guardInputMode(mixed $event): bool {
    $screen = self::$window?->screen('screen' . self::currentIndex());
    if ($screen === null || $screen->statusBar?->confirming()) {
      return false;
    }
    $active = $screen->activeLeaf();
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN && $active?->instance() instanceof TerminalTile
      && $active->instance()->scrolling()
      && in_array((int)$event->key->key, [SDL::KEY_ESCAPE, SDL::KEY_RETURN], true)) {
      $resumeInput = (int)$event->key->key === SDL::KEY_RETURN && $active->instance()->isRunning();
      $screen->release();
      if ($resumeInput) {
        $screen->activateLeaf($active);
      }
      return true;
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT && self::$suppressMoveText) {
      self::$suppressMoveText = false;
      return true;
    }
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN && !in_array((int)$event->key->key, [SDL::KEY_LSHIFT, SDL::KEY_RSHIFT], true)) {
      self::$suppressMoveText = false;
      self::$lastShiftRelease = 0.0;
      if (!in_array((int)$event->key->key, [SDL::KEY_KP_PLUS, SDL::KEY_KP_MINUS, SDL::KEY_KP_MULTIPLY, SDL::KEY_KP_DIVIDE], true)) {
        self::$keypadText = null;
      }
      return false;
    }
    if ($event->type !== SDL::SDL_EVENT_KEY_UP || !in_array((int)$event->key->key, [SDL::KEY_LSHIFT, SDL::KEY_RSHIFT], true)) {
      return false;
    }
    if (!$screen->activeLeaf()?->instance() instanceof TerminalTile) {
      self::$lastShiftRelease = 0.0;
      return false;
    }
    $now = microtime(true) * 1000;
    if (self::$lastShiftRelease > 0 && $now - self::$lastShiftRelease <= 300) {
      self::$lastShiftRelease = 0.0;
      self::$keypadText = null;
      $screen->release();
      return true;
    }
    self::$lastShiftRelease = $now;
    return false;
  }

  /** Interpret screen numbers and letter commands in command mode. */
  public static function commandTextInput(EventContext $event): bool {
    $screen = self::$window?->screen('screen' . self::currentIndex());
    if ($screen === null || $screen->activeLeaf() !== null) {
      return false;
    }
    $symbol = \FFI::string($event->input->text->text);
    if (self::$keypadText !== null) {
      $duplicate = $symbol === self::$keypadText && microtime(true) - self::$keypadTime < 0.25;
      self::$keypadText = null;
      if ($duplicate) {
        return true;
      }
    }
    if (strlen($symbol) === 1 && ctype_digit($symbol)) {
      return self::switchScreen((int)$symbol);
    }
    if ($symbol === 'h') {
      return self::splitSelected($screen, 'horizontal');
    }
    if ($symbol === 'j') {
      return self::splitSelected($screen, 'vertical');
    }
    if ($symbol === 'c') {
      return self::copySelected($screen);
    }
    if ($symbol === 'x') {
      return self::requestPaneClose($screen);
    }
    if ($symbol === 'z') {
      return self::toggleZoom($screen);
    }
    if ($symbol === 'm') {
      return self::toggleMultiplex($screen);
    }
    if ($symbol === 'b') {
      return self::browseSelected($screen);
    }
    if ($symbol === 'r') {
      return self::restartSelected($screen);
    }
    if ($symbol === 'v') {
      return self::pasteSelected($screen);
    }
    return self::resizeForLetter($screen, $symbol);
  }

  /** Browse terminal output with the existing selection and clipboard controls. */
  private static function browseSelected(Screen $screen): bool {
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    $selected->instance()->startScroll();
    $screen->activateLeaf($selected);
    self::$window->refreshLayout();
    return true;
  }

  /** Copy the text selected during the last scroll session. */
  private static function copySelected(Screen $screen): bool {
    $selected = $screen->selectedLeaf();
    return $selected?->instance() instanceof TerminalTile && $selected->instance()->copySelection();
  }

  /** Paste into the selected live terminal, then give it keyboard input. */
  private static function pasteSelected(Screen $screen): bool {
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile || !$selected->instance()->canActivate()) {
      return false;
    }
    $screen->activateLeaf($selected);
    $bytes = Clipboard::get();
    if ($bytes !== '') {
      if (self::$screenStates[self::currentIndex()]['multiplex']) {
        self::broadcastInput(self::currentIndex(), $bytes);
      } else {
        $selected->instance()->shell()->input($bytes);
      }
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Replace an exited shell in place and activate its new terminal. */
  private static function restartSelected(Screen $screen): bool {
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile || $selected->instance()->isRunning()) {
      return false;
    }
    self::restoreZoom($screen);
    $selected = $screen->selectedLeaf();
    $index = self::currentIndex();
    $oldShell = $selected->instance()->shell();
    $replacement = self::newTerminalLeaf($index);
    $replacement->setWidth($selected->width());
    $replacement->setHeight($selected->height());
    if (!$screen->layout->replaceChild($selected, $replacement)) {
      throw new \LogicException('Terminal to restart is missing from the layout.');
    }
    $screen->setLayout($screen->layout);
    $screen->activateLeaf($replacement);
    $oldShell->close();
    self::$shells[$index] = array_values(array_filter(self::$shells[$index], fn(RunningShell $candidate): bool => $candidate !== $oldShell));
    self::$window->refreshLayout();
    return true;
  }

  /** Toggle input fanout for every terminal on this screen. */
  private static function toggleMultiplex(Screen $screen): bool {
    $index = self::currentIndex();
    $enabled = !self::$screenStates[$index]['multiplex'];
    $selected = $screen->selectedLeaf();
    if ($enabled && (!$selected?->instance() instanceof TerminalTile || !$selected->instance()->canActivate())) {
      return false;
    }
    self::$screenStates[$index]['multiplex'] = $enabled;
    self::applyMultiplexState($index);
    self::updateTitle($index);
    if ($enabled) {
      $screen->activateLeaf($selected);
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Update each live tile's input route after a screen or layout change. */
  private static function applyMultiplexState(int $index): void {
    $state = self::$screenStates[$index];
    $layout = $state['fullLayout'] ?? $state['screen']->layout;
    foreach ($layout->leaves() as $leaf) {
      $tile = $leaf->instance();
      if (!$tile instanceof TerminalTile) {
        continue;
      }
      $tile->setGroupInputMode($state['multiplex']);
      $tile->setInputHandler($state['multiplex'] ? fn(string $bytes) => self::broadcastInput($index, $bytes) : null);
    }
  }

  /** Deliver one translated input sequence to every running shell on a screen. */
  private static function broadcastInput(int $index, string $bytes): void {
    foreach (self::$shells[$index] ?? [] as $shell) {
      if ($shell->isRunning()) {
        $shell->input($bytes);
      }
    }
  }

  /** Identify a screen with active group input in the window title. */
  private static function updateTitle(int $index): void {
    self::$window->setTitle('madirector — Screen ' . $index . (self::$screenStates[$index]['multiplex'] ? ' [multiplex]' : ''));
  }

  /** Show only the selected terminal, or put back this screen's full layout. */
  private static function toggleZoom(Screen $screen): bool {
    $index = self::currentIndex();
    if (self::$screenStates[$index]['fullLayout'] !== null) {
      self::restoreZoom($screen);
      return true;
    }
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    self::$screenStates[$index]['fullLayout'] = $screen->layout;
    $zoom = new LayoutNode('vertical', '1*', '1*');
    $zoom->addLeaf($selected);
    $zoom->addLeaf(self::$screenStates[$index]['hiddenStatus']);
    $screen->setLayout($zoom);
    $screen->activateLeaf($selected);
    self::$window->refreshLayout();
    return true;
  }

  /** Restore hidden panes before changing their layout. */
  private static function restoreZoom(Screen $screen): void {
    $index = self::currentIndex();
    $fullLayout = self::$screenStates[$index]['fullLayout'];
    if ($fullLayout === null) {
      return;
    }
    self::$screenStates[$index]['fullLayout'] = null;
    $screen->setLayout($fullLayout);
    self::$window->refreshLayout();
  }

  /** Handle keypad operators even when SDL emits no text-input event for them. */
  public static function commandKeyDown(EventContext $event): bool {
    $screen = self::$window?->screen('screen' . self::currentIndex());
    if ($screen === null || $screen->activeLeaf() !== null) {
      return false;
    }
    $key = (int)$event->input->key->key;
    $mod = (int)$event->input->key->mod;
    if (($mod & SDL::MOD_SHIFT) !== 0 && ($mod & (SDL::MOD_CTRL | SDL::MOD_ALT | SDL::MOD_MODE)) === 0) {
      $direction = match ($key) {
        SDL::KEY_LEFT => 'left',
        SDL::KEY_RIGHT => 'right',
        SDL::KEY_UP => 'up',
        SDL::KEY_DOWN => 'down',
        default => null,
      };
      if ($direction !== null) {
        return self::swapSelected($screen, $direction);
      }
      $scancode = (int)($event->input->key->scancode ?? 0);
      $number = $key >= ord('0') && $key <= ord('9') ? $key - ord('0')
        : ($scancode >= 30 && $scancode <= 39 ? ($scancode - 29) % 10 : null);
      if ($number !== null) {
        self::moveSelectedToScreen($screen, $number);
        self::$suppressMoveText = true;
        return true;
      }
    }
    $symbol = match ($key) {
      SDL::KEY_KP_PLUS => '+',
      SDL::KEY_KP_MINUS => '-',
      SDL::KEY_KP_MULTIPLY => '*',
      SDL::KEY_KP_DIVIDE => '/',
      default => null,
    };
    if ($symbol === null) {
      return false;
    }
    self::$keypadText = $symbol;
    self::$keypadTime = microtime(true);
    return match ($symbol) {
      '+' => self::resizeSelected($screen, 'vertical', 1),
      '-' => self::resizeSelected($screen, 'vertical', -1),
      '*' => self::resizeSelected($screen, 'horizontal', 1),
      '/' => self::resizeSelected($screen, 'horizontal', -1),
    };
  }

  /** Exchange the selected pane with the pane arrow navigation would reach. */
  private static function swapSelected(Screen $screen, string $direction): bool {
    self::restoreZoom($screen);
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return true;
    }
    $navigation = new WidgetSelection($screen->layout->leaves(true), $screen->layout->movementLeaves());
    $navigation->select($selected);
    if (!$navigation->move($direction)) {
      return true;
    }
    $neighbor = $navigation->selectedLeaf();
    if (!$neighbor?->instance() instanceof TerminalTile) {
      return true;
    }
    $temporary = new LayoutLeaf('Empty', '1*', '1*', new \SPTK\Widgets\Empty\Placeholder(new Color(0, 0, 0)));
    $width = $selected->width();
    $height = $selected->height();
    if (!$screen->layout->replaceChild($selected, $temporary)
      || !$screen->layout->replaceChild($neighbor, $selected)
      || !$screen->layout->replaceChild($temporary, $neighbor)) {
      throw new \LogicException('Terminal to swap is missing from the layout.');
    }
    $selected->setWidth($neighbor->width());
    $selected->setHeight($neighbor->height());
    $neighbor->setWidth($width);
    $neighbor->setHeight($height);
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
    return true;
  }

  /** Transfer one live terminal to a vacant numbered screen. */
  private static function moveSelectedToScreen(Screen $screen, int $target): bool {
    $source = self::currentIndex();
    if ($target === $source || (self::$screenStates[$target]['hasTerminal'] ?? false)) {
      return false;
    }
    self::restoreZoom($screen);
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    $shell = $selected->instance()->shell();
    self::removeSelectedFromLayout($screen, $selected, $source);
    self::$shells[$source] = array_values(array_filter(self::$shells[$source], fn(RunningShell $candidate): bool => $candidate !== $shell));
    self::$shells[$target][] = $shell;
    $selected->setWidth('1*');
    $selected->setHeight('1*');
    if (!isset(self::$screenStates[$target])) {
      self::initializeScreen($target, $selected);
    } else {
      $state = self::$screenStates[$target];
      $destination = $state['screen'];
      $destination->layout->replaceChild($state['placeholder'], $selected);
      $destination->setLayout($destination->layout);
      $destination->activateLeaf($selected);
      self::$screenStates[$target]['hasTerminal'] = true;
    }
    self::applyMultiplexState($target);
    self::switchScreen($target);
    return true;
  }

  /** Map mnemonic letters to growth and shrinkage on the two axes. */
  private static function resizeForLetter(Screen $screen, string $symbol): bool {
    return match ($symbol) {
      't' => self::resizeSelected($screen, 'vertical', 1),
      's' => self::resizeSelected($screen, 'vertical', -1),
      'w' => self::resizeSelected($screen, 'horizontal', 1),
      'n' => self::resizeSelected($screen, 'horizontal', -1),
      default => false,
    };
  }

  /** Show the numbered screen, creating its terminal on first use. */
  private static function switchScreen(int $index): bool {
    if (!isset(self::$screenStates[$index])) {
      self::initializeScreen($index);
    } else if (!self::$screenStates[$index]['hasTerminal']) {
      $state = self::$screenStates[$index];
      $screen = $state['screen'];
      $leaf = self::newTerminalLeaf($index);
      $screen->layout->replaceChild($state['placeholder'], $leaf);
      $screen->setLayout($screen->layout);
      $screen->activateLeaf($leaf);
      self::$screenStates[$index]['hasTerminal'] = true;
    }
    self::$lastShiftRelease = 0.0;
    self::$keypadText = null;
    self::forgetScreen($index);
    self::$screenHistory[] = $index;
    self::$window->setCurrentScreenId('screen' . $index);
    self::updateTitle($index);
    $screen = self::$screenStates[$index]['screen'];
    $selected = $screen->selectedLeaf();
    if ($selected?->instance() instanceof TerminalTile && $selected->instance()->canActivate()) {
      $screen->activateLeaf($selected);
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Ask before ending the selected terminal and its shell. */
  private static function requestPaneClose(Screen $screen): bool {
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    $state = self::$screenStates[self::currentIndex()];
    $screen->layout->replaceChild($state['hiddenStatus'], $state['visibleStatus']);
    $screen->setLayout($screen->layout);
    $message = $selected->instance()->isRunning() ? 'Close terminal and end its shell?' : 'Close terminal?';
    $state['status']->confirm($message, function() use ($screen, $selected): void {
      self::cancelClose();
      if ($screen->selectedLeaf() === $selected) {
        self::closeSelected($screen);
      }
    }, self::cancelClose(...));
    self::$window->refreshLayout();
    return true;
  }

  /** Close the selected pane, preserving its sibling or returning to an earlier screen. */
  private static function closeSelected(Screen $screen): bool {
    self::restoreZoom($screen);
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    $index = self::currentIndex();
    $shell = $selected->instance()->shell();
    self::removeSelectedFromLayout($screen, $selected, $index);
    $shell->close();
    self::$shells[$index] = array_values(array_filter(self::$shells[$index], fn(RunningShell $candidate): bool => $candidate !== $shell));
    $previous = self::$screenHistory === [] ? null : end(self::$screenHistory);
    if (!self::$screenStates[$index]['hasTerminal'] && $previous !== null) {
      self::switchScreen($previous);
    } else {
      self::$window->refreshLayout();
    }
    return true;
  }

  /** Remove a terminal tile but leave its shell available for transfer or closing. */
  private static function removeSelectedFromLayout(Screen $screen, LayoutLeaf $selected, int $index): void {
    $path = $screen->layout->pathTo($selected);
    if ($path === null) {
      throw new \LogicException('Selected terminal is missing from the layout.');
    }
    $parent = $path === null ? null : end($path);
    if (!$parent instanceof LayoutNode) {
      throw new \LogicException('Selected terminal is missing from the layout.');
    }
    if ($parent === $screen->layout) {
      $placeholder = self::$screenStates[$index]['placeholder'];
      $screen->layout->replaceChild($selected, $placeholder);
      $screen->setLayout($screen->layout);
      self::$screenStates[$index]['hasTerminal'] = false;
      self::forgetScreen($index);
    } else {
      $children = $parent->childTiles();
      if (count($children) !== 2) {
        throw new \LogicException('A terminal split must have two children.');
      }
      $sibling = $children[0] === $selected ? $children[1] : $children[0];
      $neighbor = self::nearestLeaf($sibling, $selected);
      $screen->layout->replaceChild($parent, $sibling);
      $screen->setLayout($screen->layout);
      $screen->selectLeaf($neighbor);
    }
  }

  /** Remove a screen from recent-use order. */
  private static function forgetScreen(int $index): void {
    $remaining = [];
    foreach (self::$screenHistory as $screen) {
      if ($screen !== $index) {
        $remaining[] = $screen;
      }
    }
    self::$screenHistory = $remaining;
  }

  /** Select the closest surviving terminal in the sibling subtree. */
  private static function nearestLeaf(LayoutNode|LayoutLeaf $sibling, LayoutLeaf $removed): LayoutLeaf {
    $leaves = $sibling instanceof LayoutLeaf ? [$sibling] : $sibling->leaves(true);
    $source = $removed->grid();
    $nearest = $leaves[0];
    $distance = PHP_INT_MAX;
    foreach ($leaves as $leaf) {
      $grid = $leaf->grid();
      $candidate = abs(2 * $grid->x + $grid->width - 2 * $source->x - $source->width)
        + abs(2 * $grid->y + $grid->height - 2 * $source->y - $source->height);
      if ($candidate < $distance) {
        $nearest = $leaf;
        $distance = $candidate;
      }
    }
    return $nearest;
  }

  /** Replace one pane with two equal panes and focus the new shell. */
  private static function splitSelected(Screen $screen, string $direction): bool {
    self::restoreZoom($screen);
    $current = $screen->selectedLeaf();
    if (!$current?->instance() instanceof TerminalTile) {
      return false;
    }
    $newLeaf = self::newTerminalLeaf(self::currentIndex());
    $split = new LayoutNode($direction, $current->width(), $current->height());
    $split->addLeaf($current);
    $split->addSeparator(new LayoutSeparator());
    $split->addLeaf($newLeaf);
    if (!$screen->layout->replaceChild($current, $split)) {
      throw new \LogicException('Selected terminal is missing from the layout.');
    }
    $screen->setLayout($screen->layout);
    $screen->activateLeaf($newLeaf);
    self::applyMultiplexState(self::currentIndex());
    self::$window->refreshLayout();
    return true;
  }

  /** Grow or shrink the selected pane at its nearest split on one axis. */
  private static function resizeSelected(Screen $screen, string $direction, int $step): bool {
    self::restoreZoom($screen);
    $selected = $screen->selectedLeaf();
    if (!$selected?->instance() instanceof TerminalTile) {
      return false;
    }
    $path = $screen->layout->pathTo($selected);
    if ($path === null) {
      throw new \LogicException('Selected terminal is missing from the layout.');
    }
    $branch = $selected;
    for ($index = count($path) - 1; $index >= 0; $index--) {
      $split = $path[$index];
      if ($split !== $screen->layout && $split->direction() === $direction) {
        $children = $split->childTiles();
        if (count($children) === 2 && ($children[0] === $branch || $children[1] === $branch)) {
          [$first, $second] = $children;
          $firstSize = $direction === 'horizontal' ? $first->grid()->width : $first->grid()->height;
          $secondSize = $direction === 'horizontal' ? $second->grid()->width : $second->grid()->height;
          $change = $branch === $first ? $step : -$step;
          $min = $direction === 'horizontal' ? 4 : 2;
          if ($firstSize + $change < $min || $secondSize - $change < $min) {
            return true;
          }
          if ($direction === 'horizontal') {
            $first->setWidth(($firstSize + $change) . '*');
            $second->setWidth(($secondSize - $change) . '*');
          } else {
            $first->setHeight(($firstSize + $change) . '*');
            $second->setHeight(($secondSize - $change) . '*');
          }
          self::$window->refreshLayout();
          return true;
        }
      }
      $branch = $split;
    }
    return true;
  }

  /** Create one live pane with its own shell, PTY, and screen buffer. */
  private static function newTerminalLeaf(int $screenIndex): LayoutLeaf {
    $shell = new RunningShell();
    self::$shells[$screenIndex][] = $shell;
    $tile = new TerminalTile($shell, new Style(background: new Color(0, 0, 0), foreground: new Color(255, 255, 255)));
    $tile->setZoomed(true);
    if (self::$screenStates[$screenIndex]['multiplex'] ?? false) {
      $tile->setGroupInputMode(true);
      $tile->setInputHandler(fn(string $bytes) => self::broadcastInput($screenIndex, $bytes));
    }
    return new LayoutLeaf('Terminal', '1*', '1*', $tile);
  }

  /** Return the current screen's number from its SPTK identifier. */
  private static function currentIndex(): int {
    return (int)substr(self::$window->currentScreenId(), strlen('screen'));
  }

  /** Ask before closing the GUI, with or without running shells. */
  private static function requestClose(): bool {
    if (self::$window === null) {
      return true;
    }
    $state = self::$screenStates[self::currentIndex()];
    if ($state['status']->confirming()) {
      return false;
    }
    $screen = $state['screen'];
    $screen->layout->replaceChild($state['hiddenStatus'], $state['visibleStatus']);
    $screen->setLayout($screen->layout);
    $running = 0;
    foreach (self::$shells as $group) {
      foreach ($group as $shell) {
        if ($shell->isRunning()) {
          $running++;
        }
      }
    }
    $message = $running === 0 ? 'Close madirector?' : 'Close madirector and end the running shells?';
    $state['status']->confirm($message, self::confirmClose(...), self::cancelClose(...));
    self::$window->refreshLayout();
    return false;
  }

  /** Complete an approved close request. */
  private static function confirmClose(): void {
    \SPTK\App::eventLoop()->quitWindow(self::$window->id());
  }

  /** Collapse the empty status tile after cancellation. */
  private static function cancelClose(): void {
    $state = self::$screenStates[self::currentIndex()];
    $screen = $state['screen'];
    $screen->layout->replaceChild($state['visibleStatus'], $state['hiddenStatus']);
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** End every shell when the app leaves its event loop. */
  public static function close(EventContext $event): void {
    foreach (self::$shells as $group) {
      foreach ($group as $shell) {
        $shell->close();
      }
    }
    self::$shells = [];
    self::$screenStates = [];
    self::$screenHistory = [];
    self::$lastShiftRelease = 0.0;
    self::$keypadText = null;
    self::$suppressMoveText = false;
    self::$window = null;
  }

}
