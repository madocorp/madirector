<?php

namespace MADIR\App;

use SPTK\Core\Color;
use SPTK\Core\RenderTarget;
use SPTK\Widgets\Input;

/** One-line command input that can disappear while terminal focus is active. */
class CommandInput extends Input {

  private bool $visible = true;

  public function setVisible(bool $visible): void {
    $this->visible = $visible;
    $this->setPreferredRows($visible ? 1 : 0);
  }

  public function isVisible(): bool {
    return $this->visible;
  }

  public function preferredRowsForColumns(int $columns): int {
    return $this->visible ? parent::preferredRowsForColumns($columns) : 0;
  }

  public function isFocusable(): bool {
    return $this->visible && parent::isFocusable();
  }

  public function isTabStop(): bool {
    return $this->visible && parent::isTabStop();
  }

  public function commandLineFg(): Color {
    return $this->fieldFg();
  }

  public function commandLineBg(): Color {
    return $this->fieldBg();
  }

  protected function paint(RenderTarget $target): void {
    if (!$this->visible) {
      return;
    }
    parent::paint($target);
  }

}
