<?php

namespace MADIR\App;

use MADIR\Screen\ScreenBuffer;
use SPTK\Core\{Cell, Clipboard, Color, ScrollIndicator, Style, TextCursor, Widget};
use SPTK\Events\{KeyNormalizer, WidgetEventEmitter};
use SPTK\Layout\Tile;
use SPTK\Rendering\{GridWriter, PixelRenderer, TextMetrics};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Input\Input;

/** Paint one existing PTY screen buffer into a scrollable SPTK terminal tile. */
final class TerminalTile extends Widget {

  use WidgetEventEmitter;

  private int $scrollOffset = 0;
  private bool $scrollMode = false;
  private bool $inputMode = false;
  private bool $groupInputMode = false;
  private bool $zoomed = false;
  private bool $suppressNextTextInput = false;
  private bool $deleteConfirmation = false;
  private bool $searchMode = false;
  private bool $paintedImages = false;
  private ?Input $searchInput = null;
  private ?TerminalSearch $search = null;
  private int $maximumBodyRows = 23;
  private int $viewportWidth = 1;
  private int $viewportHeight = 1;
  private array $scrollLines = [];
  private ?TextCursor $scrollCursor = null;
  private int|false $preferredColumn = false;
  private Color $black;
  private Color $white;

  /** Bind one running or completed command to this tile. */
  public function __construct(private RunningCommand $command, private Style $style = new Style()) {
    $this->black = new Color(0, 0, 0);
    $this->white = new Color(255, 255, 255);
    $this->on('activate', $this->activate(...));
    $this->on('deactivate', $this->deactivate(...));
  }

  /** Grow with visible output, except when an alternate-screen program needs the full view. */
  public function preferredHeight(): ?int {
    $rows = $this->command->isAltScreenActive() ? $this->maximumBodyRows : $this->command->outputRows();
    return min($this->maximumBodyRows, max(1, $rows)) + 1;
  }

  /** Bound the terminal body by the space above the command prompt. */
  public function setMaximumBodyRows(int $rows): void {
    $this->maximumBodyRows = max(1, $rows);
  }

  /** Paint the command header and every row while the writer clips the visible fragment. */
  public function paint(GridWriter $writer): void {
    if ($writer->width() < 1 || $writer->height() < 1) {
      return;
    }
    $bodyStart = $this->zoomed && !$this->searchMode ? 0 : 1;
    $rows = max(1, $writer->height() - $bodyStart);
    $this->viewportWidth = $writer->width();
    $this->viewportHeight = $rows;
    $this->command->resize($this->zoomed ? $rows : $this->maximumBodyRows, max(1, $writer->width()));
    if ($this->scrollMode) {
      $this->syncScrollLines();
      $this->revealScrollCursor();
    }
    $this->clampScroll();
    $writer->fill($this->white, $this->black);
    if ($bodyStart > 0) {
      $this->paintHeader($writer);
    }
    $bufferRows = $this->scrollMode
      ? $this->command->buffer()->getDocumentRows($this->scrollOffset, $rows)
      : $this->command->buffer()->getRows();
    $cursor = $this->scrollMode ? false : $this->command->buffer()->getCursor();
    $selection = $this->scrollMode && $this->scrollCursor?->hasSelection() ? $this->scrollCursor->selectionRange() : null;
    for ($y = 0; $y < $rows; $y++) {
      $documentRow = $this->scrollOffset + $y;
      $indices = [];
      if ($selection !== null) {
        foreach (TextMetrics::cells($this->scrollLines[$documentRow] ?? '', 0, $writer->width()) as $x => $cell) {
          $indices[$x] = $cell[2];
        }
      }
      foreach ($bufferRows[$y] ?? [] as $x => $data) {
        if ($x >= $writer->width()) {
          break;
        }
        $glyph = (string)($data[ScreenBuffer::GLYPH] ?? ' ');
        if ($glyph === '') {
          continue;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $glyph)) {
          $glyph = ' ';
        }
        $fg = $this->color((int)($data[ScreenBuffer::FG] ?? 0xffffff));
        $bg = $this->color((int)($data[ScreenBuffer::BG] ?? 0));
        if (((int)($data[ScreenBuffer::ATTR] ?? 0) & ScreenBuffer::A_REVERSE) !== 0 || ($cursor !== false && $cursor === [$y, $x])) {
          [$fg, $bg] = [$bg, $fg];
        }
        if ($selection !== null && isset($indices[$x]) && $indices[$x] < TextMetrics::length($this->scrollLines[$documentRow] ?? '')
          && [$documentRow, $indices[$x]] >= [$selection[0], $selection[1]]
          && [$documentRow, $indices[$x]] < [$selection[2], $selection[3]]) {
          $fg = $this->black;
          $bg = $this->style->selected;
        }
        $writer->put((int)$x, $y + $bodyStart, new Cell($glyph, $fg, $bg, TextMetrics::glyphWidth($glyph)));
      }
      if ($selection !== null && $documentRow < count($this->scrollLines) - 1) {
        $end = TextMetrics::length($this->scrollLines[$documentRow]);
        if ([$documentRow, $end] >= [$selection[0], $selection[1]] && [$documentRow, $end] < [$selection[2], $selection[3]]) {
          $x = TextMetrics::width($this->scrollLines[$documentRow]);
          if ($x < $writer->width()) {
            $writer->set($x, $y + $bodyStart, '¶', $this->black, $this->style->selected);
          }
        }
      }
    }
    if ($this->scrollMode) {
      $above = ScrollIndicator::fit(ScrollIndicator::label($this->scrollOffset, $rows, '▲'), $writer->width(), true);
      $below = ScrollIndicator::fit(ScrollIndicator::label(max(0, count($this->scrollLines) - $this->scrollOffset - $rows), $rows, '▼'), $writer->width(), true);
      if ($above !== '') {
        $writer->write($writer->width() - TextMetrics::width($above), $bodyStart, $above, $this->black, $this->style->highlight);
      }
      if ($below !== '') {
        $writer->write($writer->width() - TextMetrics::width($below), $bodyStart + $rows - 1, $below, $this->black, $this->style->highlight);
      }
    }
    if ($this->scrollMode && $this->scrollCursor !== null) {
      [$row, $column] = $this->scrollCursor->position();
      $y = $row - $this->scrollOffset;
      $x = TextMetrics::width(TextMetrics::slice($this->scrollLines[$row] ?? '', 0, $column));
      if ($y >= 0 && $y < $rows && $x < $writer->width()) {
        $glyph = TextMetrics::slice($this->scrollLines[$row], $column, 1);
        if ($glyph === '' && $row < count($this->scrollLines) - 1) {
          $glyph = '¶';
        }
        if ($glyph === '' || $glyph === "\t" || preg_match('/[\x00-\x1f\x7f]/', $glyph)
          || $x + TextMetrics::glyphWidth($glyph) > $writer->width()) {
          $glyph = ' ';
        }
        $writer->set($x, $y + $bodyStart, $glyph, $this->black, $this->style->selected);
      }
    }
  }

  /** Paint the tile padding with the app color while terminal cells stay black. */
  public function background(): Color {
    return $this->zoomed ? $this->black : $this->style->background;
  }

  /** Inline images require the full terminal fragment to be repainted. */
  public function paintsPixels(): bool {
    return $this->paintedImages || $this->command->images()->hasPlacements();
  }

  /** Clip inline images to the terminal tile's visible stack fragment. */
  public function clipsPixelViewport(): bool {
    return true;
  }

  /** Terminal glyphs remain visible above inline images. */
  public function pixelBehindText(): bool {
    return $this->command->images()->hasPlacements();
  }

  /** Paint image placements at document cells below the title row. */
  public function paintClippedPixels(PixelRenderer $renderer, Tile $area, Tile $clip, bool $selected): void {
    $placements = $this->command->images()->placements();
    $this->paintedImages = $placements !== [];
    if ($placements === []) {
      return;
    }
    $cellWidth = \SPTK\App::font()->cellWidth();
    $cellHeight = \SPTK\App::font()->cellHeight();
    $bodyStart = $this->zoomed && !$this->searchMode ? 0 : 1;
    $bodyTop = $area->y + $bodyStart * $cellHeight;
    $bodyClip = $clip->intersect(new Tile($area->x, $bodyTop, $area->width, max(0, $area->height - $bodyStart * $cellHeight)));
    if ($bodyClip->width < 1 || $bodyClip->height < 1) {
      return;
    }
    $buffer = $this->command->buffer();
    $documentTop = $this->scrollMode ? $this->scrollOffset
      : $buffer->getCursorDocumentPosition()[0] - $buffer->getCursorPosition()[0];
    foreach ($placements as $placement) {
      if ($placement['alternate'] !== $buffer->isAltScreenActive()) {
        continue;
      }
      $destination = new Tile(
        $area->x + $placement['column'] * $cellWidth + $placement['cellXOffset'],
        $bodyTop + ($placement['row'] - $documentTop) * $cellHeight + $placement['cellYOffset'],
        $placement['columns'] * $cellWidth,
        $placement['rows'] * $cellHeight,
      );
      $renderer->image($placement['raster'], $destination, $bodyClip, $selected);
    }
  }

  /** Keep the command on the left and status or controls against the right edge. */
  private function paintHeader(GridWriter $writer): void {
    $width = $writer->width();
    $titleBackground = ($this->scrollMode || $this->deleteConfirmation) ? $this->style->selected : (($this->inputMode || $this->groupInputMode) ? $this->style->highlight : $this->white);
    $writer->fillRow(0, $this->black, $titleBackground);
    $leftReserve = $this->deleteConfirmation ? 12 : 20;
    $rightLimit = max(0, $width - min($leftReserve, intdiv($width, 2)) - 1);
    if ($this->deleteConfirmation) {
      $rightText = $rightLimit >= 22 ? 'Delete terminal? [Y/N]'
        : ($rightLimit >= 13 ? 'Delete? [Y/N]' : ($rightLimit >= 5 ? '[Y/N]' : 'Y/N'));
    } else if ($this->search !== null) {
      $rightText = 'Search ' . ($this->search->count() === 0 ? '0 matches' : ($this->search->index() + 1) . '/' . $this->search->count());
    } else {
      $rightText = $this->command->status();
    }
    $rightWidth = $this->searchMode ? min(40, $rightLimit) : min(TextMetrics::width($rightText), $rightLimit);
    $leftWidth = $width - $rightWidth - ($rightWidth > 0 ? 1 : 0);
    $title = str_replace(["\n", "\r", "\t"], ' ', $this->command->title());
    $writer->region(0, 0, $leftWidth, 1)->write(0, 0, $title, $this->black, $titleBackground);
    if ($rightWidth === 0) {
      return;
    }
    $right = $writer->region($width - $rightWidth, 0, $rightWidth, 1);
    if ($this->searchMode) {
      $labelWidth = $rightWidth >= 12 ? 6 : 0;
      if ($labelWidth > 0) {
        $right->write(0, 0, 'Find: ', $this->black, $titleBackground);
      }
      $this->searchInput?->paint($writer->region($width - $rightWidth + $labelWidth, 0, $rightWidth - $labelWidth, 1));
      return;
    }
    $right->write(max(0, $rightWidth - TextMetrics::width($rightText)), 0, $rightText, $this->black, $titleBackground);
  }

  /** Keep the blue tile padding bright when this terminal is unselected. */
  public function dimBackgroundWhenUnselected(): bool {
    return false;
  }

  /** Forward active input to the PTY or move within its scrollback. */
  public function handleInput(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT && $this->suppressNextTextInput) {
      $this->suppressNextTextInput = false;
      return true;
    }
    if ($this->searchMode) {
      if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
        $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
        if ($key === SDL::KEY_RETURN || $key === SDL::KEY_ESCAPE) {
          return false;
        }
      }
      return $this->searchInput->handleInput($event);
    }
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      $this->suppressNextTextInput = false;
      $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
      if ($key === SDL::KEY_F12 && in_array(KeyNormalizer::normalizeModifiers((int)$event->key->mod), [0, SDL::MOD_SHIFT], true)) {
        return false;
      }
      if ($this->scrollMode) {
        return $this->handleScrollKey($key, (int)$event->key->mod);
      }
    }
    if ($this->scrollMode) {
      return false;
    }
    if (!$this->inputMode || !$this->command->isRunning()) {
      return false;
    }
    $stream = TerminalInput::translate($event, $this->command->buffer()->getApplicationCursorState(), $this->command->buffer()->getApplicationKeypadState());
    if ($stream === null) {
      return in_array($event->type, [SDL::SDL_EVENT_KEY_DOWN, SDL::SDL_EVENT_TEXT_INPUT], true);
    }
    $this->command->input($stream);
    return true;
  }

  /** Keep Return and Escape available to interactive programs. */
  public function releaseNotification(int $key, int $modifiers): ?string {
    return $this->searchMode || $this->command->isRunning() ? null : parent::releaseNotification($key, $modifiers);
  }

  /** Edit a search query in the title while keeping terminal output visible. */
  public function startSearch(): void {
    $this->searchInput = new Input('', SystemStyle::from($this->style));
    $this->searchInput->emit('activate');
    $this->searchMode = true;
  }

  public function searching(): bool {
    return $this->searchMode;
  }

  /** Leave the search field while remaining in scroll mode. */
  public function cancelSearch(): void {
    $this->searchInput?->emit('deactivate');
    $this->searchMode = false;
  }

  /** Search the terminal document and select its first match in scroll mode. */
  public function finishSearch(): bool {
    $pattern = $this->searchInput?->text() ?? '';
    $this->cancelSearch();
    if (trim($pattern) === '') {
      return false;
    }
    $this->startScroll();
    $this->syncScrollLines();
    $this->search = new TerminalSearch($pattern, $this->scrollLines);
    $this->scrollCursor?->collapse();
    $this->selectSearchHit();
    return true;
  }

  public function hasSearch(): bool {
    return $this->search !== null;
  }

  /** Move to the previous or next match, wrapping around the document. */
  public function moveSearch(int $step): bool {
    if ($this->search?->move($step) === null) {
      return false;
    }
    $this->selectSearchHit();
    return true;
  }

  /** Enter scrollback from a screen shortcut. */
  public function startScroll(): void {
    if ($this->scrollMode) {
      return;
    }
    $this->scrollMode = true;
    $this->syncScrollLines();
    [$row, $column] = $this->command->buffer()->getCursorDocumentPosition();
    $this->scrollCursor?->setPosition($row, $column);
    $this->scrollOffset = $this->maxScroll();
  }

  /** Leave scrollback and resume the live terminal view. */
  public function stopScroll(): void {
    $this->scrollMode = false;
    $this->search = null;
    $this->scrollOffset = 0;
    $this->scrollCursor = null;
    $this->scrollLines = [];
    $this->preferredColumn = false;
  }

  /** Report whether this tile is showing scrollback. */
  public function scrolling(): bool {
    return $this->scrollMode;
  }

  public function isRunning(): bool {
    return $this->command->isRunning();
  }

  public function setZoomed(bool $zoomed): void {
    $this->zoomed = $zoomed;
  }

  public function setGroupInputMode(bool $enabled): void {
    $this->groupInputMode = $enabled;
  }

  public function setDeleteConfirmation(bool $enabled): void {
    $this->deleteConfirmation = $enabled;
  }

  /** Discard the character generated by a shortcut that just activated this tile. */
  public function suppressNextTextInput(): void {
    $this->suppressNextTextInput = true;
  }

  /** Mark this tile as the active input target. */
  private function activate(): void {
    $this->inputMode = true;
  }

  /** Stop accepting PTY input after focus leaves. */
  private function deactivate(): void {
    $this->inputMode = false;
  }

  /** Convert a packed terminal RGB value to an SPTK color. */
  private function color(int $value): Color {
    return new Color(($value >> 16) & 255, ($value >> 8) & 255, $value & 255);
  }

  /** Track document rows while a running command appends output. */
  private function syncScrollLines(): void {
    $this->scrollLines = $this->command->buffer()->getLines();
    if ($this->scrollLines === []) {
      $this->scrollLines = [''];
    }
    if ($this->scrollCursor === null) {
      $this->scrollCursor = new TextCursor($this->scrollLines);
    } else {
      $this->scrollCursor->restoreState($this->scrollCursor->selectionState());
    }
  }

  /** Use the Text widget's caret and selection controls on terminal rows. */
  private function handleScrollKey(int $key, int $modifiers): bool {
    $this->syncScrollLines();
    $cursor = $this->scrollCursor;
    $select = ($modifiers & SDL::MOD_SHIFT) !== 0;
    $document = ($modifiers & SDL::MOD_CTRL) !== 0;
    if ($document && $key === ord('a')) {
      $cursor->selectAll();
    } else if ($document && ($key === ord('c') || $key === SDL::KEY_INSERT)) {
      Clipboard::set($cursor->selectedText());
      return true;
    } else if ($document && $key === SDL::KEY_PAGEUP) {
      $cursor->moveDocumentStart($select);
      $this->preferredColumn = false;
    } else if ($document && $key === SDL::KEY_PAGEDOWN) {
      $cursor->moveDocumentEnd($select);
      $this->preferredColumn = false;
    } else if ($key === SDL::KEY_LEFT) {
      $cursor->moveLeft($select);
      $this->preferredColumn = false;
    } else if ($key === SDL::KEY_RIGHT) {
      $cursor->moveRight($select);
      $this->preferredColumn = false;
    } else if ($key === SDL::KEY_UP || $key === SDL::KEY_DOWN) {
      if ($key === SDL::KEY_UP) {
        $cursor->moveUp($select);
      } else {
        $cursor->moveDown($select);
      }
    } else if ($key === SDL::KEY_PAGEUP || $key === SDL::KEY_PAGEDOWN) {
      $this->movePageEdge($key === SDL::KEY_PAGEDOWN, $select);
    } else if ($document && ($key === SDL::KEY_HOME || $key === SDL::KEY_END)) {
      [$row] = $cursor->position();
      $column = $key === SDL::KEY_HOME ? 0 : TextMetrics::index($this->scrollLines[$row], $this->viewportWidth - 1);
      $cursor->setPosition($row, $column, $select);
      $this->preferredColumn = false;
    } else if ($key === SDL::KEY_HOME) {
      $cursor->moveLineStart($select);
      $this->preferredColumn = false;
    } else if ($key === SDL::KEY_END) {
      $cursor->moveLineEnd($select);
      $this->preferredColumn = false;
    } else {
      return false;
    }
    $this->revealScrollCursor();
    return true;
  }

  /** Match Text's first page press to the viewport edge, then page onward. */
  private function movePageEdge(bool $end, bool $select): void {
    [$row, $column] = $this->scrollCursor->position();
    $this->preferredColumn = $this->preferredColumn === false
      ? TextMetrics::column($this->scrollLines[$row], $column) : $this->preferredColumn;
    $edge = $end ? min(count($this->scrollLines) - 1, $this->scrollOffset + $this->viewportHeight - 1) : $this->scrollOffset;
    if ($row === $edge) {
      $this->scrollOffset = max(0, min($this->maxScroll(), $this->scrollOffset + ($end ? 1 : -1) * $this->viewportHeight));
      $edge = $end ? min(count($this->scrollLines) - 1, $this->scrollOffset + $this->viewportHeight - 1) : $this->scrollOffset;
    }
    $this->scrollCursor->setPosition($edge, TextMetrics::index($this->scrollLines[$edge], $this->preferredColumn), $select);
  }

  /** Keep the scroll caret visible without moving the PTY cursor. */
  private function revealScrollCursor(): void {
    if ($this->scrollCursor === null) {
      return;
    }
    [$row] = $this->scrollCursor->position();
    $this->scrollOffset = max(0, min($this->scrollOffset, $row));
    $this->scrollOffset = max($this->scrollOffset, $row - $this->viewportHeight + 1);
    $this->clampScroll();
  }

  /** Keep the current scrollback row in range after new PTY output. */
  private function clampScroll(): void {
    $this->scrollOffset = max(0, min($this->scrollOffset, $this->maxScroll()));
  }

  private function selectSearchHit(): void {
    $hit = $this->search?->current();
    if ($hit === null || $this->scrollCursor === null) {
      return;
    }
    [$row, $start, $end] = $hit;
    $this->scrollCursor->setPosition($row, $start);
    $this->scrollCursor->setPosition($row, $end, true);
    $this->revealScrollCursor();
  }

  /** Return the last scrollback starting row. */
  private function maxScroll(): int {
    return max(0, $this->command->buffer()->countLines() - $this->viewportHeight);
  }

}
