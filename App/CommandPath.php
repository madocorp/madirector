<?php

namespace MADIR\App;

use SPTK\Core\Element;
use SPTK\Core\RenderTarget;

/** Shows the current working directory above the command prompt. */
class CommandPath extends Element {

  private bool $visible = true;
  private string $workingDirectory = '';

  public function __construct(string $name = '') {
    parent::__construct($name);
    $this->setPreferredRows(1);
  }

  public function setVisible(bool $visible): void {
    $this->visible = $visible;
    $this->setPreferredRows($visible ? 1 : 0);
  }

  public function isVisible(): bool {
    return $this->visible;
  }

  public function setWorkingDirectory(string $workingDirectory): void {
    if ($this->workingDirectory !== $workingDirectory) {
      $this->workingDirectory = $workingDirectory;
      $this->invalidateRender();
    }
  }

  public function preferredRowsForColumns(int $columns): int {
    return $this->visible ? 1 : 0;
  }

  protected function paint(RenderTarget $target): void {
    if (!$this->visible) {
      return;
    }
    $target->fill($this->frame, ' ', $this->theme->fg, $this->theme->bg);
    if ($this->frame->width > 0) {
      $target->write($this->frame->x, $this->frame->y, $this->promptPath(), $this->theme->muted, $this->theme->bg);
    }
  }

  private function promptPath(): string {
    $path = rtrim($this->workingDirectory, '/');
    return $path === '' ? '/' : $path;
  }

}
