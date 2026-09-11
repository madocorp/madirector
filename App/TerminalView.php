<?php

namespace MADIR\App;

use MADIR\Screen\ScreenBuffer;
use SPTK\Core\Element;
use SPTK\Core\InputAction;
use SPTK\Core\PixelSurfaceElement;
use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Core\SurfaceRenderTarget;

/** Paints a ScreenBuffer through the current SPTK grid render target. */
class TerminalView extends Element implements PixelSurfaceElement {

  public const DEFAULT_CELL_HEIGHT = 16;
  private const FONT_FAMILIES = ['LiberationMono-Bold', 'Liberation Mono', 'NimbusMonoPS-Regular'];
  private const BOX_DRAWING = [
    '─' => ['left', 'right'],
    '│' => ['up', 'down'],
    '┌' => ['right', 'down'],
    '┐' => ['left', 'down'],
    '└' => ['right', 'up'],
    '┘' => ['left', 'up'],
    '├' => ['up', 'right', 'down'],
    '┤' => ['up', 'left', 'down'],
    '┬' => ['left', 'right', 'down'],
    '┴' => ['left', 'right', 'up'],
    '┼' => ['left', 'right', 'up', 'down'],
  ];
  private bool $selected = false;
  private bool $inputRedirected = false;
  private bool $scrollMode = false;
  private bool $zoomed = false;
  private int $scrollOffset = 0;
  private $doubleShiftCallback = null;

  public function __construct(string $name, private RunningCommand $command, private int $cellHeight = self::DEFAULT_CELL_HEIGHT) {
    parent::__construct($name);
    $this->cellHeight = max(1, $this->cellHeight);
    $this->focusable = true;
    $this->setPreferredRows(12);
    $this->setPreferredColumns(80);
  }

  public function refresh(): void {
    $this->invalidateRender();
  }

  public function setDoubleShiftCallback(?callable $callback): void {
    $this->doubleShiftCallback = $callback;
  }

  public function setTerminalState(bool $selected, bool $inputRedirected, bool $scrollMode, bool $zoomed = false): void {
    if (
      $this->selected !== $selected
      || $this->inputRedirected !== $inputRedirected
      || $this->scrollMode !== $scrollMode
      || $this->zoomed !== $zoomed
    ) {
      $this->invalidateRender();
    }
    if (!$this->scrollMode && $scrollMode) {
      $this->scrollOffset = $this->maxScrollOffset();
    }
    $this->selected = $selected;
    $this->inputRedirected = $inputRedirected;
    $this->scrollMode = $scrollMode;
    $this->zoomed = $zoomed;
    if (!$this->scrollMode) {
      $this->scrollOffset = 0;
    } else {
      $this->scrollOffset = max(0, min($this->scrollOffset, $this->maxScrollOffset()));
    }
  }

  public function handle(\SPTK\Core\InputEvent $event): bool {
    if ($event->type === 'key-release' && InputAction::normalizedKey($event->key) === 'Shift') {
      return $this->doubleShiftCallback !== null && call_user_func($this->doubleShiftCallback);
    }
    if ($this->scrollMode) {
      return $this->handleScrollKey($event);
    }
    if (!$this->inputRedirected || !$this->command->isRunning()) {
      return false;
    }
    $stream = InputTranslator::translate(
      $event,
      $this->command->buffer()->getApplicationCursorState(),
      $this->command->buffer()->getApplicationKeypadState()
    );
    if ($stream === null) {
      return false;
    }
    $this->command->input($stream);
    return true;
  }

  public function renderPixelSurface(SurfaceRenderTarget $target, Rect $pixelFrame): void {
    $cellWidth = $target->cellWidth();
    $columns = max(1, intdiv($pixelFrame->width, $cellWidth));
    $rows = self::rowsForPixelHeightWithCellHeight($pixelFrame->height, $this->cellHeight);
    $gridWidth = $columns * $cellWidth;
    $gridHeight = $rows * $this->cellHeight;
    $gridX = $pixelFrame->x + max(0, intdiv($pixelFrame->width - $gridWidth, 2));
    $gridY = $pixelFrame->y + max(0, intdiv($pixelFrame->height - $gridHeight, 2));
    $this->command->resize($rows, $columns);
    $this->clampScrollOffset();
    $target->fillPixels($pixelFrame, '#000000');
    $bufferRows = $this->command->buffer()->getRows($this->activeScrollOffset());
    $cursor = $this->cursorForActiveScrollOffset();
    $font = [
      'family' => self::FONT_FAMILIES[0],
      'families' => self::FONT_FAMILIES,
      'size' => $this->cellHeight,
    ];
    for ($y = 0; $y < $rows; $y++) {
      $row = $bufferRows[$y] ?? [];
      for ($x = 0; $x < $columns; $x++) {
        $cell = $row[$x] ?? null;
        if ($cell === null) {
          continue;
        }
        $glyph = (string)($cell[ScreenBuffer::GLYPH] ?? ' ');
        $fg = $this->color((int)($cell[ScreenBuffer::FG] ?? 0xffffff));
        $bg = $this->color((int)($cell[ScreenBuffer::BG] ?? 0x000000));
        if (((int)($cell[ScreenBuffer::ATTR] ?? 0) & ScreenBuffer::A_REVERSE) > 0) {
          [$fg, $bg] = [$bg, $fg];
        }
        if (is_array($cursor) && $cursor[0] === $y && $cursor[1] === $x) {
          [$fg, $bg] = [$bg, $fg];
        }
        $rect = new Rect(
          $gridX + $x * $cellWidth,
          $gridY + $y * $this->cellHeight,
          $cellWidth,
          $this->cellHeight
        );
        if ($bg !== '#000000') {
          $target->fillPixels($rect, $bg);
        }
        if ($glyph !== ' ') {
          if (!$this->drawBoxGlyph($target, $glyph, $rect, $fg)) {
            $target->drawTextPixels($glyph, $rect, $fg, $font);
          }
        }
      }
    }
  }

  public static function rowsForPixelHeight(int $pixelHeight): int {
    return self::rowsForPixelHeightWithCellHeight($pixelHeight, self::DEFAULT_CELL_HEIGHT);
  }

  public static function pixelHeightForRows(int $rows): int {
    return self::pixelHeightForRowsWithCellHeight($rows, self::DEFAULT_CELL_HEIGHT);
  }

  public static function rowsForPixelHeightWithCellHeight(int $pixelHeight, int $cellHeight): int {
    return max(1, intdiv($pixelHeight, max(1, $cellHeight)));
  }

  public static function pixelHeightForRowsWithCellHeight(int $rows, int $cellHeight): int {
    return max(1, $rows) * max(1, $cellHeight);
  }

  protected function paint(RenderTarget $target): void {
    $target->fill($this->frame, ' ', '#ffffff', '#000000');
    $rows = $this->command->buffer()->getRows($this->activeScrollOffset());
    $cursor = $this->cursorForActiveScrollOffset();
    for ($y = 0; $y < $this->frame->height; $y++) {
      $row = $rows[$y] ?? [];
      for ($x = 0; $x < $this->frame->width; $x++) {
        $cell = $row[$x] ?? null;
        if ($cell === null) {
          continue;
        }
        $glyph = (string)($cell[ScreenBuffer::GLYPH] ?? ' ');
        $fg = $this->color((int)($cell[ScreenBuffer::FG] ?? 0xffffff));
        $bg = $this->color((int)($cell[ScreenBuffer::BG] ?? 0x000000));
        if (((int)($cell[ScreenBuffer::ATTR] ?? 0) & ScreenBuffer::A_REVERSE) > 0) {
          [$fg, $bg] = [$bg, $fg];
        }
        if (is_array($cursor) && $cursor[0] === $y && $cursor[1] === $x) {
          [$fg, $bg] = [$bg, $fg];
        }
        $target->put($this->frame->x + $x, $this->frame->y + $y, $glyph, $fg, $bg);
      }
    }
  }

  private function color(int $color): string {
    return sprintf('#%06x', $color & 0xffffff);
  }

  private function handleScrollKey(\SPTK\Core\InputEvent $event): bool {
    if ($event->type !== 'key') {
      return false;
    }
    $page = max(1, $this->command->buffer()->getRowCount() - 1);
    if (InputAction::up($event, 'terminal-scroll')) {
      return $this->scrollTo($this->scrollOffset - 1);
    }
    if (InputAction::down($event, 'terminal-scroll')) {
      return $this->scrollTo($this->scrollOffset + 1);
    }
    if (InputAction::pageUp($event, 'terminal-scroll')) {
      return $this->scrollTo($this->scrollOffset - $page);
    }
    if (InputAction::pageDown($event, 'terminal-scroll')) {
      return $this->scrollTo($this->scrollOffset + $page);
    }
    if (InputAction::home($event, 'terminal-scroll')) {
      return $this->scrollTo(0);
    }
    if (InputAction::end($event, 'terminal-scroll')) {
      return $this->scrollTo($this->maxScrollOffset());
    }
    return false;
  }

  private function scrollTo(int $offset): bool {
    $offset = max(0, min($offset, $this->maxScrollOffset()));
    if ($offset === $this->scrollOffset) {
      return true;
    }
    $this->scrollOffset = $offset;
    $this->invalidateRender();
    return true;
  }

  private function activeScrollOffset(): int|false {
    return $this->scrollMode ? $this->scrollOffset : false;
  }

  private function cursorForActiveScrollOffset(): array|false {
    $cursor = $this->command->buffer()->getCursor();
    if (!is_array($cursor)) {
      return false;
    }
    $offset = $this->activeScrollOffset();
    if ($offset === false) {
      return $cursor;
    }
    [$row, $col] = $this->command->buffer()->getCursorDocumentPosition();
    return [$row - $offset, $col];
  }

  private function clampScrollOffset(): void {
    if ($this->scrollMode) {
      $this->scrollOffset = max(0, min($this->scrollOffset, $this->maxScrollOffset()));
    }
  }

  private function maxScrollOffset(): int {
    return max(0, $this->command->buffer()->countLines() - $this->command->buffer()->getRowCount());
  }

  private function drawBoxGlyph(SurfaceRenderTarget $target, string $glyph, Rect $rect, string $color): bool {
    $parts = self::BOX_DRAWING[$glyph] ?? null;
    if ($parts === null) {
      return false;
    }
    if ($rect->width <= 0 || $rect->height <= 0) {
      return true;
    }
    $centerX = $rect->x + intdiv($rect->width, 2);
    $centerY = $rect->y + intdiv($rect->height, 2);
    $right = $rect->x + $rect->width;
    $bottom = $rect->y + $rect->height;
    foreach ($parts as $part) {
      switch ($part) {
        case 'left':
          $target->fillPixels(new Rect($rect->x, $centerY, $centerX - $rect->x + 1, 1), $color);
          break;
        case 'right':
          $target->fillPixels(new Rect($centerX, $centerY, $right - $centerX, 1), $color);
          break;
        case 'up':
          $target->fillPixels(new Rect($centerX, $rect->y, 1, $centerY - $rect->y + 1), $color);
          break;
        case 'down':
          $target->fillPixels(new Rect($centerX, $centerY, 1, $bottom - $centerY), $color);
          break;
      }
    }
    return true;
  }

}
