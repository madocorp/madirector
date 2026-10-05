<?php

namespace MADIR\App;

use SPTK\Core\Style;
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\List\ListView;
use SPTK\Widgets\Text\Text;

/** Display completion groups while the command line keeps keyboard focus. */
final class CompletionPanel {

  private ?Text $noMatches;
  private array $lists = [];
  private int $column = 0;

  public function __construct(
    public readonly string $prefix,
    array $groups,
    Style $style
  ) {
    $this->noMatches = $groups === [] ? new Text('No matches', $style, wrap: false) : null;
    foreach ($groups as $name => $candidates) {
      $items = array_map(static fn(string $value): array => ['value' => $value, 'label' => $value], $candidates);
      $this->lists[] = new ListView($items, filterable: false, searchable: false, style: $style, title: $name);
    }
    if ($this->lists !== []) {
      $this->lists[0]->emit('activate');
    }
  }

  public function noMatches(): ?Text {
    return $this->noMatches;
  }

  public function lists(): array {
    return $this->lists;
  }

  public function moveColumn(int $step): void {
    if ($this->lists === []) {
      return;
    }
    $this->lists[$this->column]->emit('deactivate');
    $this->column = ($this->column + $step + count($this->lists)) % count($this->lists);
    $this->lists[$this->column]->emit('activate');
  }

  public function moveRow(int $step): void {
    if ($this->lists === []) {
      return;
    }
    $this->lists[$this->column]->handleInput((object)[
      'type' => SDL::SDL_EVENT_KEY_DOWN,
      'key' => (object)['key' => $step < 0 ? SDL::KEY_UP : SDL::KEY_DOWN, 'mod' => 0, 'repeat' => false],
    ]);
  }

  public function selected(): ?string {
    return $this->lists === [] ? null : $this->lists[$this->column]->activeValue();
  }

}
