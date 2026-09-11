<?php

namespace MADIR\App;

use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Core\SurfaceRenderTarget;
use SPTK\Widgets\Dock;

/** Bottom-docked terminal stack using SPTK's repeated dock layout. */
class TerminalStack extends Dock {

  private const HORIZONTAL_INSET = 1;
  private ?TerminalBox $selectedBox = null;
  private string $selectedEdge = 'bottom';
  private int $scrollOffset = 0;
  private bool $zoomMode = false;

  public function __construct(string $name = '') {
    parent::__construct($name);
    $this->setGridAlignment('bottom');
    $this->setGridOverflow(true);
  }

  public function layout(): void {
    if ($this->zoomMode && $this->selectedBox !== null && $this->selectedBoxInTree()) {
      $this->layoutZoomed();
      return;
    }
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
    if ($this->selectedBox !== null && $this->selectedBoxInTree()) {
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

  public function setZoomMode(bool $zoomMode): void {
    if ($this->zoomMode !== $zoomMode) {
      $this->zoomMode = $zoomMode;
      $this->scrollOffset = 0;
      $this->layout();
    }
  }

  private function layoutZoomed(): void {
    $availableFrame = $this->terminalAvailableFrame(null, false);
    foreach ($this->children() as $child) {
      if ($child === $this->selectedBox || ($child instanceof TerminalGroup && $this->selectedBox !== null && $child->containsBox($this->selectedBox))) {
        $child->setFrame(new Rect($availableFrame->x, $availableFrame->y, 0, 0));
        if ($this->selectedBox !== null) {
          $this->selectedBox->setAvailableFrame($availableFrame);
          $this->selectedBox->setFrame($availableFrame);
        }
      } else {
        $child->setFrame(new Rect($availableFrame->x, $availableFrame->y, 0, 0));
      }
    }
  }

  private function alignSelected(array $heights, int $maxOffset): void {
    $y = $this->frame()->bottom() + $this->scrollOffset;
    foreach ($this->children() as $child) {
      $height = $heights[spl_object_id($child)] ?? 1;
      $y -= $height;
      if ($child !== $this->selectedBox && (!$child instanceof TerminalGroup || $this->selectedBox === null || !$child->containsBox($this->selectedBox))) {
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

  private function terminalAvailableFrame(?Rect $frame = null, bool $inset = true): Rect {
    $frame ??= $this->frame();
    $inset = $inset ? min(self::HORIZONTAL_INSET, intdiv(max(0, $frame->width), 2)) : 0;
    return new Rect(
      $frame->x + $inset,
      $frame->y,
      max(0, $frame->width - $inset * 2),
      $frame->height
    );
  }

  public function render(RenderTarget $target): void {
    if (
      $this->zoomMode
      && $this->selectedBox !== null
      && $this->selectedBoxInTree()
      && $target instanceof SurfaceRenderTarget
    ) {
      $this->selectedBox->renderZoomSurface($target, $target->currentSurfacePixelRect());
      return;
    }
    $target->pushClip($this->frame());
    foreach ($this->children() as $child) {
      $frame = $child->frame();
      if ($frame->width > 0 && $frame->height > 0) {
        $child->render($target);
      }
    }
    $target->popClip();
  }

  private function selectedBoxInTree(): bool {
    if ($this->selectedBox === null) {
      return false;
    }
    foreach ($this->children() as $child) {
      if ($child === $this->selectedBox) {
        return true;
      }
      if ($child instanceof TerminalGroup && $child->containsBox($this->selectedBox)) {
        return true;
      }
    }
    return false;
  }

}
