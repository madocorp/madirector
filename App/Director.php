<?php

namespace MADIR\App;

use MADIR\Completion\Engine as CompletionEngine;
use MADIR\Pty\CommanderHandler;
use SPTK\Core\{Color, Screen, Style, Window};
use SPTK\Events\KeyNormalizer;
use SPTK\SDLWrapper\SDL;
use SPTK\Rendering\TextMetrics;
use SPTK\Widgets\Input\Input;

/** Manage MaDirector sessions, command execution, and terminal tiles. */
final class Director {

  private array $sessions = [];
  private AppSession $session;
  private array $tiles = [];
  private DirectorLayout $layout;
  private Style $style;
  private Input $prompt;
  private bool $dirty = true;
  private int $selectedIndex = -1;
  private bool $quitRequested = false;
  private bool $zoomed = false;
  private bool $terminalActive = true;
  private bool $promptInput = true;
  private bool $lastRunning = false;
  private float $lastShiftRelease = 0.0;
  private Color $normalBackground;
  private array $groupInputCommands = [];
  private ?RunningCommand $groupInputOwner = null;
  private bool $suppressGroupShortcutText = false;
  private ?SignalPanel $signalPanel = null;
  private ?CompletionPanel $completionPanel = null;
  private ?TerminalTile $deleteCandidate = null;

  /** Attach the SPTK window to an initial MaDirector session. */
  public function __construct(private Window $window, private Screen $screen) {
    $this->normalBackground = $screen->background;
    $this->session = $this->newSession(0);
    $this->style = new Style(background: new Color(0, 0, 128), foreground: new Color(255, 255, 255));
    $promptStyle = $this->style->with(['Background' => new Color(52, 73, 94)]);
    $this->prompt = new Input('', $promptStyle, label: $this->session->commandSession->cwd());
    $this->prompt->setInputInterceptor($this->promptInputEvent(...));
    $this->prompt->on('select', $this->promptSelected(...));
    $this->prompt->on('activate', $this->promptActivated(...));
    $this->prompt->on('deactivate', $this->promptDeactivated(...));
    $this->layout = new DirectorLayout($window, $screen, $this->prompt, $this->style);
    CommanderHandler::setPeriodicRefreshCallback($this->poll(...));
    $this->rebuild();
  }

  /** Poll process results and redraw once for any changed terminal screen. */
  public function poll(): void {
    if ($this->quitRequested) {
      \SPTK\App::eventLoop()->quitWindow($this->window->id());
      return;
    }
    if ($this->signalPanel !== null) {
      if (!$this->signalPanel->hasRunningTargets()) {
        $this->signalPanel = null;
        if (!$this->session->hasRunningCommands()) {
          $this->activatePrompt();
        } else {
          $this->rebuild();
          $this->layout->revealSelection();
        }
        return;
      }
    }
    if ($this->deleteCandidate !== null && ($this->screen->activeLeaf() !== null || $this->selectedTile() !== $this->deleteCandidate)) {
      $this->clearDeleteConfirmation();
      $this->window->refreshLayout();
    }
    if (!$this->zoomed && $this->signalPanel === null) {
      $this->selectedIndex = $this->layout->indexOf($this->screen->selectedLeaf());
    }
    $running = $this->session->hasRunningCommands();
    $tile = $this->selectedTile();
    if ($this->lastRunning && !$running && !$tile?->searching()) {
      $this->activatePrompt();
      return;
    }
    $groupRunning = false;
    foreach ($this->groupInputCommands as $command) {
      $groupRunning = $groupRunning || $command->isRunning();
    }
    if ($this->groupInputCommands !== [] && !$groupRunning) {
      $this->enterNormal();
    }
    if ($tile !== null && $this->screen->activeLeaf() !== null && !$tile->scrolling() && !$tile->searching() && !$tile->isRunning() && !$groupRunning) {
      if ($this->zoomed) {
        $this->enterScroll();
      } else {
        $this->enterNormal();
      }
    }
    if ($this->session->runningCommand !== null && !$this->session->runningCommand->isRunning()) {
      $this->session->runningCommand = null;
    }
    if ($this->lastRunning !== $running) {
      $this->rebuild();
      return;
    }
    if ($this->dirty) {
      $this->rebuild();
    }
  }

  /** Parse and execute the command line through the shell logic. */
  public function submit(): bool {
    if ($this->screen->activeLeaf()?->instance() !== $this->prompt) {
      return false;
    }
    $source = trim($this->prompt->text());
    $this->completionPanel = null;
    if ($source === '') {
      return true;
    }
    $this->session->recordHistory($source);
    $this->prompt->setValue('');
    $this->run($source);
    return true;
  }

  /** Browse CLI history while active, or move between tiles after release. */
  public function history(int $step): bool {
    $active = $this->screen->activeLeaf();
    if ($active === null) {
      return $this->selectTerminal($step > 0 ? -1 : 1);
    }
    if ($active->instance() !== $this->prompt) {
      return false;
    }
    $value = $this->session->browseHistory($step, $this->prompt->text());
    if ($value !== null) {
      $this->prompt->setValue($value, true);
    }
    return true;
  }

  /** Move one command group up or down, with the prompt below the last group. */
  public function selectTerminal(int $step): bool {
    if (!$this->zoomed) {
      $this->selectedIndex = $this->layout->indexOf($this->screen->selectedLeaf());
    }
    if ($this->selectedTile() !== null && ($this->groupInputCommands !== [] || $this->zoomed || $this->screen->activeLeaf() !== null || $this->selectedTile()->scrolling())) {
      $this->enterNormal();
    }
    $next = $this->layout->moveSelection($this->selectedIndex, $step);
    if ($next !== $this->selectedIndex) {
      $this->selectedIndex = $next;
      $this->terminalActive = false;
    }
    return true;
  }

  /** Toggle PTY input and normal tile selection with F12. */
  public function toggleInput(): bool {
    if ($this->groupInputCommands !== []) {
      return $this->enterNormal();
    }
    $tile = $this->selectedTile();
    if ($tile === null) {
      return false;
    }
    if ($tile->scrolling()) {
      return $this->enterInput();
    }
    return $this->screen->activeLeaf() !== null ? $this->enterNormal() : $this->enterInput();
  }

  /** Switch terminal input after two Shift releases within 300 milliseconds. */
  public function shiftReleased(): bool {
    if ($this->selectedTile() === null) {
      $this->lastShiftRelease = 0.0;
      return false;
    }
    $now = microtime(true) * 1000;
    if ($this->lastShiftRelease === 0.0 || $now - $this->lastShiftRelease > 300) {
      $this->lastShiftRelease = $now;
      return false;
    }
    $this->lastShiftRelease = 0.0;
    return $this->toggleInput();
  }

  public function resetShiftGesture(): void {
    $this->lastShiftRelease = 0.0;
  }

  /** Enter scrollback on the selected terminal. */
  public function startScroll(): bool {
    return $this->enterScroll();
  }

  /** Expand the selected terminal tile to the full window. */
  public function zoom(): bool {
    if ($this->screen->activeLeaf() !== null || $this->selectedTile() === null) {
      return false;
    }
    $this->selectedIndex = $this->layout->indexOf($this->screen->selectedLeaf());
    $this->zoomed = true;
    $this->screen->background = new Color(0, 0, 0);
    $tile = $this->selectedTile();
    $tile->setZoomed(true);
    if (!$tile->isRunning()) {
      $tile->startScroll();
    }
    $this->terminalActive = true;
    $this->rebuild();
    return true;
  }

  /** Apply the documented mode keys after SPTK has offered them to the active widget. */
  public function keyDown(mixed $event): bool {
    $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
    $mod = KeyNormalizer::normalizeModifiers((int)$event->key->mod);
    if ($this->deleteCandidate !== null) {
      if ($mod === 0 && in_array($key, [ord('y'), SDL::KEY_RETURN], true)) {
        return $this->confirmDelete($this->deleteCandidate);
      }
      if ($mod === 0 && in_array($key, [ord('n'), SDL::KEY_ESCAPE], true)) {
        $this->clearDeleteConfirmation();
        $this->window->refreshLayout();
      }
      return true;
    }
    if ($this->screen->activeLeaf() === null && $this->signalPanel === null && $this->groupInputCommands === [] && !$this->zoomed) {
      if ($mod === SDL::MOD_CTRL && ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT)) {
        return $this->switchSession($key === SDL::KEY_LEFT ? -1 : 1);
      }
      if ($mod === 0 && ($key === SDL::KEY_HOME || $key === SDL::KEY_END)) {
        return $this->selectTerminal(($key === SDL::KEY_HOME ? -1 : 1) * (count($this->session->commands) + 1));
      }
    }
    if ($this->signalPanel !== null) {
      if ($mod === 0 && ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT)) {
        if ($this->signalPanel->moveColumn($key === SDL::KEY_LEFT ? -1 : 1)) {
          $leaf = $this->layout->signalLeaf($this->signalPanel->column());
          if ($leaf !== null) {
            $this->screen->activateLeaf($leaf);
            $this->window->refreshLayout();
          }
        }
        return true;
      }
      if ($mod === 0 && $key === SDL::KEY_RETURN) {
        $this->signalPanel->sendSelected();
        return $this->closeSignalPanel();
      }
      if ($mod === 0 && $key === SDL::KEY_ESCAPE) {
        return $this->closeSignalPanel();
      }
      return true;
    }
    if ($this->groupInputCommands !== []) {
      if ($key === SDL::KEY_F12 && in_array($mod, [0, SDL::MOD_SHIFT], true)) {
        return $mod === SDL::MOD_SHIFT ? $this->enterScroll() : $this->enterNormal();
      }
      $buffer = $this->groupInputOwner->buffer();
      $stream = TerminalInput::translate($event, $buffer->getApplicationCursorState(), $buffer->getApplicationKeypadState());
      if ($stream !== null) {
        $this->groupInputOwner->input($stream);
      }
      return true;
    }
    $activeTile = $this->selectedTile();
    if ($activeTile !== null && $this->screen->activeLeaf()?->instance() === $activeTile && !$activeTile->scrolling() && !$activeTile->searching()) {
      if ($key === SDL::KEY_F12 && in_array($mod, [0, SDL::MOD_SHIFT], true)) {
        return $mod === SDL::MOD_SHIFT ? $this->enterScroll() : $this->enterNormal();
      }
      return true;
    }
    $searchTile = $this->selectedTile();
    if ($searchTile?->searching()) {
      if ($mod === 0 && $key === SDL::KEY_RETURN) {
        $searchTile->finishSearch();
        $this->window->refreshLayout();
        return true;
      }
      if ($mod === 0 && $key === SDL::KEY_ESCAPE) {
        $searchTile->cancelSearch();
        $this->window->refreshLayout();
        return true;
      }
      return true;
    }
    if (in_array($key, [SDL::KEY_PAGEUP, SDL::KEY_PAGEDOWN], true) && in_array($mod, [0, SDL::MOD_CTRL], true)) {
      if ($mod === 0 && $this->selectedTile() !== null && $this->screen->activeLeaf() !== null) {
        return false;
      }
      return $this->selectTerminal($key === SDL::KEY_PAGEUP ? -1 : 1);
    }
    if ($this->screen->activeLeaf()?->instance() === $this->prompt) {
      return match ($key) {
        SDL::KEY_RETURN => $this->submit(),
        SDL::KEY_UP => $this->history(1),
        SDL::KEY_DOWN => $this->history(-1),
        default => false,
      };
    }
    $tile = $this->selectedTile();
    if ($tile === null) {
      return false;
    }
    $scrolling = $tile->scrolling();
    if ($key === SDL::KEY_F12 && in_array($mod, [0, SDL::MOD_SHIFT], true)) {
      return $mod === SDL::MOD_SHIFT ? $this->enterScroll() : $this->toggleInput();
    }
    if ($scrolling) {
      if ($mod === 0 && $key === ord('f')) {
        $tile->suppressNextTextInput();
        $tile->startSearch();
        $this->window->refreshLayout();
        return true;
      }
      if ($mod === 0 && ($key === ord('p') || $key === ord('n'))) {
        if ($tile->moveSearch($key === ord('p') ? -1 : 1)) {
          $this->window->refreshLayout();
        }
        return true;
      }
      if ($mod === 0 && in_array($key, [SDL::KEY_ESCAPE, ord('s')], true)) {
        return $this->enterNormal();
      }
      if ($mod === 0 && $key === SDL::KEY_RETURN) {
        return $tile->isRunning() ? $this->enterInput() : $this->enterNormal();
      }
      return false;
    }
    if ($mod === 0 && $key === ord('x') && !$tile->isRunning()) {
      return $this->confirmDelete($tile);
    }
    if ($mod === 0 && $key === ord('f')) {
      $this->enterScroll(true);
      $tile->startSearch();
      $this->window->refreshLayout();
      return true;
    }
    if ($key === SDL::KEY_DELETE && in_array($mod, [0, SDL::MOD_SHIFT], true)) {
      return $this->openSignalPanel($mod === SDL::MOD_SHIFT);
    }
    if ($key === SDL::KEY_RETURN) {
      return $mod === SDL::MOD_SHIFT ? $this->enterScroll() : $this->enterInput();
    }
    if ($mod === 0 && $key === ord('i')) {
      return $this->enterInput(true);
    }
    if ($mod === 0 && $key === ord('g')) {
      return $this->enterGroupInput();
    }
    if ($mod === 0 && $key === ord('s')) {
      return $this->enterScroll(true);
    }
    if (($key === ord('z') || $key === SDL::KEY_F11) && in_array($mod, [0, SDL::MOD_SHIFT], true)) {
      if ($key === ord('z')) {
        $tile->suppressNextTextInput();
      }
      $this->zoom();
      return $mod === SDL::MOD_SHIFT ? $this->enterScroll() : true;
    }
    return false;
  }

  /** Forward text input while SPTK's group focus keeps its children bright. */
  public function textInput(mixed $event): bool {
    if ($this->groupInputOwner === null) {
      return false;
    }
    if ($this->suppressGroupShortcutText) {
      $this->suppressGroupShortcutText = false;
      return true;
    }
    $stream = TerminalInput::translate($event);
    if ($stream === null) {
      return false;
    }
    $this->groupInputOwner->input($stream);
    return true;
  }

  private function selectedTile(): ?TerminalTile {
    $tile = $this->screen->selectedLeaf()?->instance();
    if ($tile instanceof TerminalTile) {
      return $tile;
    }
    return $this->groupInputOwner === null ? null : ($this->tiles[spl_object_id($this->groupInputOwner)] ?? null);
  }

  private function enterInput(bool $characterShortcut = false): bool {
    if ($this->groupInputCommands !== []) {
      $this->enterNormal();
    }
    $tile = $this->selectedTile();
    if ($tile === null) {
      return false;
    }
    if (!$tile->isRunning()) {
      return $tile->scrolling() ? true : $this->enterScroll($characterShortcut);
    }
    $this->clearGroupInput();
    if ($characterShortcut) {
      $tile->suppressNextTextInput();
    }
    $tile->stopScroll();
    $this->terminalActive = true;
    $this->screen->activateLeaf($this->screen->selectedLeaf());
    $this->window->refreshLayout();
    return true;
  }

  private function enterScroll(bool $characterShortcut = false): bool {
    if ($this->groupInputCommands !== []) {
      $this->enterNormal();
    }
    $tile = $this->selectedTile();
    if ($tile === null) {
      return false;
    }
    if ($characterShortcut) {
      $tile->suppressNextTextInput();
    }
    $this->clearGroupInput();
    $tile->startScroll();
    $this->terminalActive = true;
    $this->screen->activateLeaf($this->screen->selectedLeaf());
    $this->window->refreshLayout();
    return true;
  }

  private function enterNormal(): bool {
    $tile = $this->selectedTile();
    if ($tile === null) {
      return false;
    }
    $wasGroupInput = $this->groupInputCommands !== [];
    $this->clearGroupInput();
    if ($tile->searching()) {
      $tile->cancelSearch();
    }
    $tile->stopScroll();
    $tile->setZoomed(false);
    $this->terminalActive = false;
    $this->screen->release();
    if ($this->zoomed || $wasGroupInput) {
      $this->zoomed = false;
      $this->screen->background = $this->normalBackground;
      $this->rebuild();
    } else {
      $this->window->refreshLayout();
    }
    return true;
  }

  /** Send input from the selected tile to every running command in its group. */
  private function enterGroupInput(): bool {
    $tile = $this->selectedTile();
    if ($tile === null || $this->screen->activeLeaf() !== null || $this->zoomed) {
      return false;
    }
    $this->selectedIndex = $this->layout->indexOf($this->screen->selectedLeaf());
    $owner = $this->session->commands[$this->selectedIndex] ?? null;
    if ($owner === null) {
      return false;
    }
    $group = $this->session->commandGroupIds[$this->selectedIndex] ?? null;
    $commands = [];
    foreach ($this->session->commands as $index => $command) {
      if ($command === $owner || ($group !== null && ($this->session->commandGroupIds[$index] ?? null) === $group)) {
        $commands[] = $command;
      }
    }
    $running = false;
    foreach ($commands as $command) {
      $running = $running || $command->isRunning();
    }
    if (!$running) {
      return false;
    }
    $this->clearGroupInput();
    $this->groupInputCommands = $commands;
    $this->groupInputOwner = $owner;
    $owner->setInputCallback(static function(RunningCommand $source, string $input) use ($commands): void {
      foreach ($commands as $command) {
        $command->inputDirect($input);
      }
    });
    foreach ($commands as $command) {
      $this->tiles[spl_object_id($command)]->setGroupInputMode(true);
    }
    $this->suppressGroupShortcutText = true;
    $this->terminalActive = false;
    $this->rebuild();
    return true;
  }

  private function clearGroupInput(): void {
    $this->groupInputOwner?->setInputCallback(null);
    foreach ($this->groupInputCommands as $command) {
      $this->tiles[spl_object_id($command)]->setGroupInputMode(false);
    }
    $this->groupInputCommands = [];
    $this->groupInputOwner = null;
    $this->suppressGroupShortcutText = false;
  }

  /** Cycle through existing sessions without creating a new one. */
  private function switchSession(int $step): bool {
    $ids = array_keys($this->sessions);
    $position = array_search($this->session->id, $ids, true);
    $next = max(0, min(count($ids) - 1, $position + $step));
    if ($next === $position) {
      return true;
    }
    $this->clearDeleteConfirmation();
    $this->completionPanel = null;
    $this->session = $this->sessions[$ids[$next]];
    $this->selectedIndex = -1;
    $this->terminalActive = false;
    $this->promptInput = true;
    $this->rebuild();
    return true;
  }

  /** Require confirmation before removing a completed command tile. */
  private function confirmDelete(TerminalTile $tile): bool {
    if ($this->deleteCandidate !== $tile) {
      $this->clearDeleteConfirmation();
      $this->deleteCandidate = $tile;
      $tile->setDeleteConfirmation(true);
      $this->window->refreshLayout();
      return true;
    }
    if ($this->selectedTile() !== $tile) {
      $this->clearDeleteConfirmation();
      $this->window->refreshLayout();
      return true;
    }
    $index = $this->layout->indexOf($this->screen->selectedLeaf());
    $command = $this->session->commands[$index] ?? null;
    if ($command === null || $command->isRunning()) {
      $this->clearDeleteConfirmation();
      return true;
    }
    $this->clearDeleteConfirmation();
    array_splice($this->session->commands, $index, 1);
    array_splice($this->session->commandGroupIds, $index, 1);
    if ($this->session->runningCommand === $command) {
      $this->session->runningCommand = null;
    }
    unset($this->tiles[spl_object_id($command)]);
    $this->selectedIndex = min($index, count($this->session->commands) - 1);
    $this->terminalActive = false;
    $this->promptInput = $this->selectedIndex < 0;
    $this->rebuild();
    $this->layout->revealSelection();
    return true;
  }

  private function clearDeleteConfirmation(): void {
    $this->deleteCandidate?->setDeleteConfirmation(false);
    $this->deleteCandidate = null;
  }

  /** Open a signal chooser for one terminal or every command in its group. */
  private function openSignalPanel(bool $groupTarget): bool {
    if ($this->zoomed || $this->screen->activeLeaf() !== null) {
      return false;
    }
    $index = $this->layout->indexOf($this->screen->selectedLeaf());
    $owner = $this->session->commands[$index] ?? null;
    if ($owner === null) {
      return false;
    }
    $groupId = $this->session->commandGroupIds[$index] ?? null;
    $targets = [];
    foreach ($this->session->commands as $candidateIndex => $command) {
      if ($command === $owner || ($groupTarget && $groupId !== null && ($this->session->commandGroupIds[$candidateIndex] ?? null) === $groupId)) {
        $targets[] = $command;
      }
    }
    $running = array_filter($targets, static fn(RunningCommand $command): bool => $command->isRunning());
    if ($running === []) {
      return true;
    }
    $target = $groupTarget
      ? 'group containing terminal ' . ($index + 1) . ' (' . count($targets) . ' terminal' . (count($targets) === 1 ? '' : 's') . ')'
      : 'terminal ' . ($index + 1) . ': ' . $owner->title();
    $this->signalPanel = new SignalPanel($target, $targets, SystemStyle::from($this->style), $this->window->refreshLayout(...));
    $this->selectedIndex = $index;
    $this->terminalActive = false;
    $this->rebuild();
    return true;
  }

  /** Remove the chooser and return to the terminal that opened it. */
  private function closeSignalPanel(): bool {
    if ($this->signalPanel === null) {
      return false;
    }
    $this->signalPanel = null;
    $this->terminalActive = false;
    $this->rebuild();
    $this->layout->revealSelection();
    return true;
  }

  /** Terminate active commands when the application closes. */
  public function close(): void {
    foreach ($this->sessions as $session) {
      $session->terminateRunningCommands();
    }
  }

  /** Run parsed shell syntax and native commands without changing their parser or PTY. */
  private function run(string $source): void {
    try {
      $commands = $this->session->commandSession->parse($source);
    } catch (\Throwable $error) {
      $this->append(RunningCommand::completed(['sequence' => [], 'commandString' => $source], $this->session->commandSession->cwd(), $this->session->commandSession->getenv(), "Syntax error: {$error->getMessage()}\n", 1));
      $this->activatePrompt();
      return;
    }
    $pending = [];
    $group = count($commands) > 1 ? $this->session->nextGroupId++ : null;
    foreach ($commands as $command) {
      if ($this->internal($command, $output)) {
        if ($output !== true) {
          $this->append(RunningCommand::completed($command, $this->session->commandSession->cwd(), $this->session->commandSession->getenv(), (string)$output));
        }
        continue;
      }
      $running = new RunningCommand($command, $this->session->commandSession->cwd(), $this->session->commandSession->getenv());
      $this->terminalActive = true;
      $this->append($running, $group);
      $pending[] = $running;
    }
    if ($this->quitRequested) {
      return;
    }
    if ($pending === []) {
      $this->activatePrompt();
      return;
    }
    $this->rebuild();
    foreach ($pending as $running) {
      $running->start();
    }
  }

  /** Keep existing internal commands and handle session and exit routing. */
  private function internal(array $command, mixed &$output): bool {
    $source = trim($command['commandString']);
    if (SessionControl::exit($command, $this->sessions, $this->session, function(): void {
      $this->quitRequested = true;
    }, $output)) {
      $this->selectedIndex = -1;
      $this->pruneTiles();
      return true;
    }
    if (preg_match('/^(session|s)(?:\s|$)/', $source) || ctype_digit($source)) {
      $argument = ctype_digit($source) ? $source : trim(preg_replace('/^(session|s)\s*/', '', $source));
      $output = SessionControl::session($argument, $this->sessions, $this->session, $this->newSession(...));
      $this->selectedIndex = -1;
      $this->pruneTiles();
      return true;
    }
    return $this->session->commandSession->handleInternal($command, $output);
  }

  /** Create one session with the existing parser and command state. */
  private function newSession(int $id): AppSession {
    $session = new AppSession($id);
    $session->commandSession->setCompletionSources(
      static fn(): array => $session->history,
      fn(): array => array_map(static fn(AppSession $item): string => $item->name ?? (string)$item->id, $this->sessions)
    );
    $session->commandSession->setRunCommandStringsCallback(function(array $commands): void {
      foreach ($commands as $command) {
        $this->run($command);
      }
    });
    $this->sessions[$id] = $session;
    ksort($this->sessions, SORT_NUMERIC);
    return $session;
  }

  /** Add a command without touching its parser, PTY, or ANSI buffer. */
  private function append(RunningCommand $command, ?int $group = null): void {
    if (!$command->isRunning()) {
      $this->terminalActive = false;
    }
    $this->session->commands[] = $command;
    $this->session->commandGroupIds[] = $group;
    $this->session->runningCommand = $command->isRunning() ? $command : $this->session->runningCommand;
    $command->setRefreshCallback(function(): void {
      $this->dirty = true;
    });
    $tile = new TerminalTile($command, $this->style);
    $tile->on('select', $this->terminalSelected(...));
    $this->tiles[spl_object_id($command)] = $tile;
    $this->selectedIndex = count($this->session->commands) - 1;
  }

  /** Release terminal widgets removed by session clear or deletion. */
  private function pruneTiles(): void {
    $keep = [];
    foreach ($this->sessions as $session) {
      foreach ($session->commands as $command) {
        $keep[spl_object_id($command)] = true;
      }
    }
    foreach (array_keys($this->tiles) as $id) {
      if (!isset($keep[$id])) {
        unset($this->tiles[$id]);
      }
    }
  }

  /** Rebuild the tile tree and keep the prompt or selected PTY active. */
  private function rebuild(): void {
    $this->dirty = false;
    $this->lastRunning = $this->session->hasRunningCommands();
    $groupInputIndex = $this->groupInputOwner === null ? null : array_search($this->groupInputOwner, $this->session->commands, true);
    $this->selectedIndex = $this->layout->rebuild($this->session, $this->tiles, $this->selectedIndex, $this->zoomed, $this->terminalActive, $this->promptInput, $groupInputIndex === false ? null : $groupInputIndex, $this->signalPanel, $this->completionPanel);
  }

  /** Restore the command line after the last process or an immediate command finishes. */
  private function activatePrompt(): void {
    $this->clearGroupInput();
    $this->signalPanel = null;
    $this->completionPanel = null;
    $tile = $this->selectedTile();
    if ($tile !== null) {
      $tile->stopScroll();
      $tile->setZoomed(false);
    }
    $this->screen->release();
    $this->zoomed = false;
    $this->screen->background = $this->normalBackground;
    $this->selectedIndex = -1;
    $this->terminalActive = false;
    $this->promptInput = true;
    $this->rebuild();
  }

  /** Enter prompt editing as soon as layout navigation selects it. */
  private function promptSelected(): void {
    $leaf = $this->screen->selectedLeaf();
    if ($leaf?->instance() === $this->prompt) {
      $this->screen->activateLeaf($leaf);
      $this->layout->revealSelection();
    }
  }

  private function terminalSelected(): void {
    $this->layout->revealSelection();
  }

  /** Remember that the prompt has entered SPTK input mode. */
  private function promptActivated(): void {
    $this->promptInput = true;
  }

  /** Keep the prompt released when its layout is rebuilt. */
  private function promptDeactivated(): void {
    $this->promptInput = false;
    $this->dismissCompletion();
  }

  /** Keep completion keys in the CLI while suggestions are displayed above it. */
  private function promptInputEvent(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT) {
      $this->dismissCompletion();
      return false;
    }
    if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
      return false;
    }
    $mod = KeyNormalizer::normalizeModifiers((int)$event->key->mod);
    $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
    if ($key === SDL::KEY_TAB && ($mod === 0 || $mod === SDL::MOD_SHIFT)) {
      return $this->completionPanel === null ? $this->completePrompt() : $this->closeCompletion();
    }
    if ($this->completionPanel === null) {
      return false;
    }
    if ($mod === 0 && ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT)) {
      $this->completionPanel->moveColumn($key === SDL::KEY_LEFT ? -1 : 1);
      $this->window->refreshLayout();
      return true;
    }
    if ($mod === 0 && ($key === SDL::KEY_UP || $key === SDL::KEY_DOWN)) {
      $this->completionPanel->moveRow($key === SDL::KEY_UP ? -1 : 1);
      $this->window->refreshLayout();
      return true;
    }
    if ($mod === 0 && $key === SDL::KEY_RETURN) {
      $selected = $this->completionPanel->selected();
      if ($selected !== null) {
        $this->prompt->replaceBeforeCursor(TextMetrics::length($this->completionPanel->prefix), $selected);
      }
      return $this->closeCompletion();
    }
    if ($mod === 0 && $key === SDL::KEY_ESCAPE) {
      return $this->closeCompletion();
    }
    $this->dismissCompletion();
    return false;
  }

  private function completePrompt(): bool {
    $line = $this->prompt->text();
    $cursor = $this->prompt->cursorPosition();
    $result = CompletionEngine::suggest($line, $cursor, $this->session->commandSession);
    if ($result['total'] === 1) {
      $group = reset($result['groups']);
      $candidate = $group[0];
      $this->prompt->replaceBeforeCursor(TextMetrics::length($result['prefix']), $candidate);
      $this->window->refreshLayout();
      return true;
    }
    $this->completionPanel = new CompletionPanel($result['prefix'], $result['groups'], SystemStyle::from($this->style));
    $this->rebuild();
    return true;
  }

  private function dismissCompletion(): void {
    if ($this->completionPanel !== null) {
      $this->completionPanel = null;
      $this->dirty = true;
    }
  }

  private function closeCompletion(): bool {
    $this->dismissCompletion();
    $this->rebuild();
    return true;
  }

}
