<?php

namespace MADIR\App;

use SPTK\Core\{Screen, Style, Window};
use SPTK\Layout\{LayoutLeaf, LayoutNode};
use SPTK\Widgets\Input\Input;

/** Rebuild the visible terminal stack and prompt from MaDirector session state. */
final class DirectorLayout {

  private ?LayoutNode $stack = null;
  private array $terminalLeaves = [];
  private ?LayoutLeaf $promptLeaf = null;
  private ?AppSession $currentSession = null;
  private int $lastSelection = -1;
  private array $groups = [];
  private array $groupForIndex = [];
  private ?LayoutLeaf $groupInputFocus = null;
  private ?int $groupInputIndex = null;
  private array $signalLeaves = [];
  private ?LayoutLeaf $signalHeadingLeaf = null;
  private ?LayoutLeaf $signalDescriptionLeaf = null;
  private bool $building = false;

  /** Keep the window and prompt widgets while tiles are rebuilt. */
  public function __construct(private Window $window, private Screen $screen, private Input $prompt, private Style $style) {
  }

  /** Rebuild the bottom-anchored stack and restore its selected tile. */
  public function rebuild(AppSession $session, array &$tiles, int $selectedIndex, bool $zoomed = false, bool $terminalActive = true, bool $promptInput = true, ?int $groupInputIndex = null, ?SignalPanel $signalPanel = null, ?CompletionPanel $completionPanel = null): int {
    $this->groupInputFocus = null;
    $this->groupInputIndex = $groupInputIndex;
    $this->signalLeaves = [];
    $this->signalHeadingLeaf = null;
    $this->signalDescriptionLeaf = null;
    if ($zoomed && isset($session->commands[$selectedIndex])) {
      $this->stack = null;
      $this->terminalLeaves = [];
      $this->promptLeaf = null;
      $this->currentSession = $session;
      $this->lastSelection = $selectedIndex;
      $command = $session->commands[$selectedIndex];
      $tile = $tiles[spl_object_id($command)] ??= new TerminalTile($command, $this->style);
      $root = new LayoutNode('vertical', '1*', '1*');
      $leaf = new LayoutLeaf('Terminal', '1*', '1*', $tile);
      $root->addLeaf($leaf);
      $this->screen->setLayout($root);
      $this->building = true;
      $this->screen->activateLeaf($leaf);
      $this->building = false;
      $this->window->refreshLayout();
      return $selectedIndex;
    }
    $grid = $this->screen->layout->grid();
    $availableHeight = max(2, $grid->height - ($this->prompt->preferredHeight() ?? 1) - 2);
    $singleHeight = max(2, min($availableHeight, (int)floor($grid->height * 2 / 3)));
    $resetViewport = $this->stack === null || $this->currentSession !== $session;
    $followBottom = $resetViewport || $this->stack->scrollOffset() === $this->stack->maxScrollOffset();
    $scrollOffset = $followBottom ? PHP_INT_MAX : $this->stack->scrollOffset();
    $stack = new LayoutNode('vertical', '1*', '1*');
    $stack->setOverflow(true);
    $stack->setScrollOffset($completionPanel === null ? $scrollOffset : PHP_INT_MAX);
    $stack->addNode(new LayoutNode('vertical', '1*', '0*'));
    $leaves = [];
    $this->groups = [];
    $this->groupForIndex = [];
    foreach ($session->commands as $index => $command) {
      $id = $session->commandGroupIds[$index] ?? null;
      $key = $id === null ? "command:$index" : "parallel:$id";
      $this->groups[$key][] = $index;
    }
    $this->groups = array_values($this->groups);
    foreach ($this->groups as $groupIndex => $indices) {
      $groupInput = $groupInputIndex !== null && in_array($groupInputIndex, $indices, true);
      foreach ($indices as $index) {
        $this->groupForIndex[$index] = $groupIndex;
      }
      if (count($indices) === 1) {
        $index = $indices[0];
        $command = $session->commands[$index];
        $tile = $tiles[spl_object_id($command)] ??= new TerminalTile($command, $this->style);
        $tile->setMaximumBodyRows($singleHeight - 1);
        $leaf = new LayoutLeaf('Terminal', '1*', 'auto', $tile);
        if ($groupInput) {
          $group = new LayoutNode('vertical', '1*', 'auto', navigateChildren: false);
          $group->addLeaf($leaf);
          $stack->addNode($group);
          $this->groupInputFocus = $group->leaves(true)[0];
        } else {
          $stack->addLeaf($leaf);
        }
        $leaves[$index] = $leaf;
        if ($signalPanel !== null && $selectedIndex === $index) {
          $this->addSignalPanel($stack, $signalPanel);
        }
        continue;
      }
      $count = count($indices);
      $columns = $count <= 4 ? 2 : ($count <= 9 ? 3 : 4);
      $rows = (int)ceil($count / $columns);
      $cellHeight = max(2, intdiv(max(2, $availableHeight - ($columns - 1)), $columns));
      $groupHeight = $rows * $cellHeight + $rows - 1;
      $group = new LayoutNode('vertical', '1*', (string)$groupHeight, navigateChildren: !$groupInput);
      for ($row = 0; $row < $rows; $row++) {
        $line = new LayoutNode('horizontal', '1*', (string)$cellHeight);
        for ($column = 0; $column < $columns; $column++) {
          $index = $indices[$row * $columns + $column] ?? null;
          if ($index === null) {
            $line->addNode(new LayoutNode('vertical', '1*', '1*'));
            continue;
          }
          $command = $session->commands[$index];
          $tile = $tiles[spl_object_id($command)] ??= new TerminalTile($command, $this->style);
          $tile->setMaximumBodyRows($cellHeight - 1);
          $leaf = new LayoutLeaf('Terminal', '1*', '1*', $tile);
          $line->addLeaf($leaf);
          $leaves[$index] = $leaf;
        }
        $group->addNode($line);
      }
      $stack->addNode($group);
      if ($groupInput) {
        $this->groupInputFocus = $group->leaves(true)[0];
      }
      if ($signalPanel !== null && in_array($selectedIndex, $indices, true)) {
        $this->addSignalPanel($stack, $signalPanel);
      }
    }
    $this->prompt->setLabel($session->commandSession->cwd());
    if ($completionPanel !== null) {
      $this->addCompletionPanel($stack, $completionPanel);
    }
    $this->promptLeaf = new LayoutLeaf('Input', '1*', 'auto', $this->prompt, paintBackground: false);
    $stack->addLeaf($this->promptLeaf);
    $this->stack = $stack;
    $this->terminalLeaves = $leaves;
    $this->currentSession = $session;
    $this->screen->setLayout($stack);
    $this->screen->measureGrid($grid);
    $this->building = true;
    if ($signalPanel !== null && isset($this->signalLeaves[$signalPanel->column()])) {
      $this->screen->activateLeaf($this->signalLeaves[$signalPanel->column()]);
    } else if ($groupInputIndex !== null && $selectedIndex === $groupInputIndex && $this->groupInputFocus !== null) {
      $this->screen->selectLeaf($this->groupInputFocus);
    } else if ($selectedIndex >= 0 && isset($leaves[$selectedIndex])) {
      $leaf = $leaves[$selectedIndex];
      if ($terminalActive) {
        $this->screen->activateLeaf($leaf);
      } else {
        $this->screen->selectLeaf($leaf);
      }
    } else {
      $selectedIndex = -1;
      if ($promptInput) {
        $this->screen->activateLeaf($this->promptLeaf);
      } else {
        $this->screen->selectLeaf($this->promptLeaf);
      }
    }
    if ($signalPanel !== null && $this->signalDescriptionLeaf !== null) {
      $target = $this->signalDescriptionLeaf->grid()->y + $this->signalDescriptionLeaf->grid()->height
        - $this->signalHeadingLeaf->grid()->y;
      $this->reveal($target <= $this->stack->grid()->height ? $this->signalDescriptionLeaf : $this->screen->selectedLeaf(), 1, false);
    } else if ($resetViewport || $selectedIndex !== $this->lastSelection) {
      $this->reveal($this->screen->selectedLeaf(), 1, false);
    }
    $this->lastSelection = $selectedIndex;
    $this->building = false;
    $this->window->refreshLayout();
    return $selectedIndex;
  }

  /** Move to the previous or next command group, treating the prompt as the bottom group. */
  public function moveSelection(int $selectedIndex, int $step): int {
    if ($this->stack === null || $step === 0) {
      return $selectedIndex;
    }
    $position = $selectedIndex === -1 ? count($this->groups) : ($this->groupForIndex[$selectedIndex] ?? count($this->groups));
    $next = max(0, min(count($this->groups), $position + $step));
    if ($next === $position || $this->groups === []) {
      return $selectedIndex;
    }
    $targetIndex = $next === count($this->groups) ? -1 : $this->groups[$next][0];
    $leaf = $targetIndex === -1 ? $this->promptLeaf : $this->terminalLeaves[$targetIndex];
    if ($targetIndex === -1) {
      $this->screen->activateLeaf($leaf);
    } else {
      $this->screen->selectLeaf($leaf);
    }
    $this->lastSelection = $targetIndex;
    return $this->lastSelection;
  }

  /** Keep arrow-selected terminals in view when layout focus crosses a group edge. */
  public function revealSelection(): void {
    if ($this->building || $this->stack === null) {
      return;
    }
    $leaf = $this->screen->selectedLeaf();
    if ($leaf === null) {
      return;
    }
    $viewport = $this->stack->grid();
    $step = $leaf->grid()->y < $viewport->y ? -1 : 1;
    $this->reveal($leaf, $step);
  }

  /** Map SPTK's arrow-key selection back to the command index. */
  public function indexOf(?LayoutLeaf $leaf): int {
    if ($leaf === $this->groupInputFocus) {
      return $this->groupInputIndex ?? -1;
    }
    foreach ($this->terminalLeaves as $index => $terminalLeaf) {
      if ($leaf === $terminalLeaf) {
        return $index;
      }
    }
    return -1;
  }

  /** Return the focused signal list after the latest layout rebuild. */
  public function signalLeaf(int $column): ?LayoutLeaf {
    return $this->signalLeaves[$column] ?? null;
  }

  /** Add a full-width signal chooser after its command group. */
  private function addSignalPanel(LayoutNode $stack, SignalPanel $panel): void {
    $section = new LayoutNode('vertical', '1*', 'auto');
    $this->signalHeadingLeaf = new LayoutLeaf('Text', '1*', '1', $panel->heading(), navigate: false);
    $section->addLeaf($this->signalHeadingLeaf);
    $columns = new LayoutNode('horizontal', '1*', '8');
    foreach ($panel->lists() as $index => $list) {
      $leaf = new LayoutLeaf('List', '1*', '1*', $list);
      $columns->addLeaf($leaf);
      $this->signalLeaves[$index] = $leaf;
    }
    $section->addNode($columns);
    $this->signalDescriptionLeaf = new LayoutLeaf('Text', '1*', '1', $panel->description(), navigate: false);
    $section->addLeaf($this->signalDescriptionLeaf);
    $stack->addNode($section);
  }

  /** Place suggestions directly above the command line. */
  private function addCompletionPanel(LayoutNode $stack, CompletionPanel $panel): void {
    if ($panel->noMatches() !== null) {
      $stack->addLeaf(new LayoutLeaf('Text', '1*', '1', $panel->noMatches(), navigate: false));
      return;
    }
    $columns = new LayoutNode('horizontal', '1*', '7');
    foreach ($panel->lists() as $list) {
      $columns->addLeaf(new LayoutLeaf('List', '1*', '1*', $list, navigate: false));
    }
    $stack->addNode($columns);
  }

  /** Bring a clipped tile to the edge reached by the movement direction. */
  private function reveal(LayoutLeaf $leaf, int $step, bool $redraw = true): void {
    $viewport = $this->stack->grid();
    $tile = $leaf->grid();
    if ($tile->y >= $viewport->y && $tile->y + $tile->height <= $viewport->y + $viewport->height) {
      return;
    }
    $edge = $step < 0 ? $tile->y - $viewport->y : $tile->y + $tile->height - $viewport->y - $viewport->height;
    $this->stack->setScrollOffset($this->stack->scrollOffset() + $edge);
    if ($redraw) {
      $this->window->refreshLayout();
    } else {
      $this->screen->measureGrid($viewport);
    }
  }

}
