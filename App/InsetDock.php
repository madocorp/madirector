<?php

namespace MADIR\App;

use SPTK\Core\Rect;
use SPTK\Core\RenderTarget;
use SPTK\Core\Element;

/** Gives its children a fixed one-cell inset within the parent frame. */
class InsetDock extends Element {

  public function __construct(string $name = '', private int $inset = 1) {
    parent::__construct($name);
  }

  public function place(Element $child): static {
    return $this->add($child);
  }

  public function layout(): void {
    $frame = $this->frame();
    $inset = min($this->inset, intdiv(max(0, $frame->width), 2), intdiv(max(0, $frame->height), 2));
    $inner = new Rect(
      $frame->x + $inset,
      $frame->y + $inset,
      max(0, $frame->width - $inset * 2),
      max(0, $frame->height - $inset)
    );
    $y = $inner->bottom();
    $visibleChildren = array_values(array_filter($this->children(), fn($child): bool => $child->preferredRowsForColumns(max(1, $inner->width)) > 0));
    usort($visibleChildren, function (Element $a, Element $b): int {
      if ($a instanceof CommandLine && !$b instanceof CommandLine) {
        return 1;
      }
      if (!$a instanceof CommandLine && $b instanceof CommandLine) {
        return -1;
      }
      return 0;
    });
    foreach (array_reverse($visibleChildren) as $child) {
      if ($child instanceof CommandLine) {
        $height = min(max(1, $child->preferredRowsForColumns(max(1, $frame->width))), max(0, $frame->height));
        $child->setFrame(new Rect(
          $frame->x,
          $frame->bottom() - $height,
          $frame->width,
          $height
        ));
        $y = min($y, $frame->bottom() - $height);
        continue;
      }
      if (method_exists($child, 'setAvailableFrame')) {
        $child->setAvailableFrame($inner);
      }
      $height = min($inner->height, max(1, $child->preferredRowsForColumns(max(1, $inner->width))));
      $y -= $height;
      if ($y < $inner->y) {
        $child->setFrame(new Rect($inner->x, $inner->y, 0, 0));
        continue;
      }
      $child->setFrame(new Rect(
        $inner->x,
        $y,
        $inner->width,
        $height
      ));
    }
    foreach ($this->children() as $child) {
      if (!in_array($child, $visibleChildren, true)) {
        $child->setFrame(new Rect($inner->x, $inner->y, 0, 0));
      }
    }
  }

  public function render(RenderTarget $target): void {
    $target->pushClip($this->frame());
    foreach ($this->children() as $child) {
      $frame = $child->frame();
      if ($frame->width > 0 && $frame->height > 0) {
        $child->render($target);
      }
    }
    foreach ($this->children() as $child) {
      $frame = $child->frame();
      if ($child instanceof CommandLine && $frame->width > 0 && $frame->height > 0) {
        $target->drawLine($frame->x, $frame->y, $frame->right(), $frame->y, '#ffffff', 2);
      }
    }
    $target->popClip();
  }

  protected function paint(RenderTarget $target): void {
    ;
  }

}
