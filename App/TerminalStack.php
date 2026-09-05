<?php

namespace MADIR\App;

use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Widgets\Dock;

/** Bottom-docked terminal stack using SPTK's repeated dock layout. */
class TerminalStack extends Dock {

  private const HORIZONTAL_INSET = 1;
  private ?TerminalBox $selectedBox = null;
  private string $selectedEdge = 'bottom';
  private int $scrollOffset = 0;

  public function __construct(string $name = '') {
    parent::__construct($name);
    $this->setGridAlignment('bottom');
  }

  public function layout(): void {
    $availableFrame = $this->terminalAvailableFrame();
    $heights = [];
    $totalHeight = 0;
    foreach ($this->children() as $child) {
      if (method_exists($child, 'setAvailableFrame')) {
        $child->setAvailableFrame($availableFrame);
      }
      $height = min(
        max(0, $availableFrame->height),
        max(1, $child->preferredRowsForColumns(max(1, $availableFrame->width)))
      );
      $heights[spl_object_id($child)] = $height;
      $totalHeight += $height;
    }
    $maxOffset = max(0, $totalHeight - $this->frame()->height);
    $this->scrollOffset = max(0, min($maxOffset, $this->scrollOffset));
    if ($this->selectedBox !== null && in_array($this->selectedBox, $this->children(), true)) {
      $this->alignSelected($heights, $maxOffset);
    }
    $y = $this->frame()->bottom() + $this->scrollOffset;
    foreach ($this->children() as $child) {
      $height = $heights[spl_object_id($child)] ?? 1;
      $y -= $height;
      if ($y >= $this->frame()->bottom() || $y + $height <= $this->frame()->y) {
        $child->setFrame(new Rect($availableFrame->x, $this->frame()->y, 0, 0));
        continue;
      }
      $child->setFrame(new Rect(
        $availableFrame->x,
        $y,
        $availableFrame->width,
        $height
      ));
    }
  }

  public function setSelectedBox(?TerminalBox $box, string $edge = 'bottom'): void {
    $this->selectedBox = $box;
    $this->selectedEdge = $edge === 'top' ? 'top' : 'bottom';
    $this->layout();
  }

  private function alignSelected(array $heights, int $maxOffset): void {
    $y = $this->frame()->bottom() + $this->scrollOffset;
    foreach ($this->children() as $child) {
      $height = $heights[spl_object_id($child)] ?? 1;
      $y -= $height;
      if ($child !== $this->selectedBox) {
        continue;
      }
      if ($this->selectedEdge === 'top') {
        $this->scrollOffset += $this->frame()->y - $y;
      } else {
        $this->scrollOffset += $this->frame()->bottom() - ($y + $height);
      }
      $this->scrollOffset = max(0, min($maxOffset, $this->scrollOffset));
      return;
    }
  }

  private function terminalAvailableFrame(): Rect {
    $frame = $this->parent()?->frame() ?? $this->frame();
    $inset = min(self::HORIZONTAL_INSET, intdiv(max(0, $frame->width), 2));
    return new Rect(
      $frame->x + $inset,
      $frame->y,
      max(0, $frame->width - $inset * 2),
      $frame->height
    );
  }

  public function render(RenderTarget $target): void {
    $target->pushClip($this->parent()?->frame() ?? $this->frame());
    foreach ($this->children() as $child) {
      $frame = $child->frame();
      if ($frame->width > 0 && $frame->height > 0) {
        $child->render($target);
      }
    }
    $target->popClip();
  }

}
