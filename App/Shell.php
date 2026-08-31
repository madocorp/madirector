<?php

namespace MADIR\App;

use SPTK\Core\Element;
use SPTK\Core\InputAction;
use SPTK\Core\InputEvent;
use SPTK\Core\Place;
use SPTK\Core\Theme;
use SPTK\Widgets\Dock;

/** Builds the first new-SPTK Madirector migration shell. */
class Shell {

  private Dock $root;
  private InsetDock $terminalArea;
  private CommandLine $commandInput;
  /** @var RunningCommand[] */
  private array $commands = [];
  /** @var TerminalBox[] */
  private array $terminalBoxes = [];
  private ?RunningCommand $runningCommand = null;
  private ?int $selectedTerminalIndex = null;
  private bool $inputRedirected = false;

  public function __construct(private int $fontSize = TerminalView::DEFAULT_CELL_HEIGHT) {
    $this->fontSize = max(1, $this->fontSize);
  }

  public function build(): Element {
    $this->root = new class('root', $this) extends Dock {
      public function __construct(string $name, private Shell $shell) {
        parent::__construct($name);
      }

      protected function handleShortcut(InputEvent $event): bool {
        return $this->shell->shortcut($event);
      }

      protected function shouldLayoutChild(Element $child): bool {
        return true;
      }

      protected function shouldRenderChild(Element $child): bool {
        return true;
      }
    };
    $this->root->setTheme(new Theme(bg: '#000088'));

    $this->terminalArea = new InsetDock('terminal-area');
    $this->commandInput = new CommandLine('command-line');

    $this->root->place($this->terminalArea, Place::fill());
    $this->terminalArea->place($this->commandInput);

    $this->commandInput->requestFocus();
    return $this->root;
  }

  public function shortcut(InputEvent $event): bool {
    if ($event->type === 'key' && InputAction::normalizedKey($event->key) === 'F12') {
      return $this->toggleInputRedirection();
    }
    if ($this->inputRedirected) {
      return false;
    }
    if (InputAction::pageUp($event, 'madirector-terminals')) {
      return $this->selectPreviousTerminal();
    }
    if (InputAction::pageDown($event, 'madirector-terminals')) {
      return $this->selectNextTerminalOrCommandLine();
    }
    if ($this->runningCommand !== null && $this->selectedTerminalIndex !== null) {
      return InputAction::newline($event, 'madirector-command');
    }
    if (!InputAction::newline($event, 'madirector-command')) {
      return false;
    }
    $command = trim($this->commandInput->text());
    if ($command === '') {
      return true;
    }
    $this->run($command);
    $this->commandInput->setText('');
    return true;
  }

  public function poll(): void {
    if ($this->runningCommand !== null && !$this->runningCommand->isRunning()) {
      $this->inputRedirected = false;
      $this->refreshTerminalStates();
      $this->runningCommand = null;
      $this->showCommandInput();
      $this->terminalArea?->layout();
    }
  }

  private function run(string $command): void {
    $this->hideCommandInput();
    $this->inputRedirected = true;
    $runningCommand = new RunningCommand($command, getcwd(), getenv());
    $terminalBox = new TerminalBox('terminal-box-' . count($this->commands), $runningCommand, $this->fontSize);
    $runningCommand->attachBox($terminalBox);
    $this->commands[] = $runningCommand;
    $this->terminalBoxes[] = $terminalBox;
    $this->runningCommand = $runningCommand;
    $this->selectedTerminalIndex = count($this->terminalBoxes) - 1;
    $this->refreshTerminalStates();
    $this->terminalArea->place($terminalBox);
    $this->terminalArea->layout();
    $runningCommand->start();
    $terminalBox->requestTerminalFocus();
    $this->terminalArea->layout();
  }

  private function toggleInputRedirection(): bool {
    if ($this->selectedTerminalIndex === null || !isset($this->terminalBoxes[$this->selectedTerminalIndex])) {
      return true;
    }
    if ($this->commands[$this->selectedTerminalIndex]->isRunning() === false) {
      $this->inputRedirected = false;
      $this->hideCommandInput();
      $this->refreshTerminalStates();
      return true;
    }
    $this->inputRedirected = !$this->inputRedirected;
    if ($this->inputRedirected) {
      $this->terminalBoxes[$this->selectedTerminalIndex]->requestTerminalFocus();
    }
    $this->hideCommandInput();
    $this->refreshTerminalStates();
    return true;
  }

  private function selectPreviousTerminal(): bool {
    if ($this->terminalBoxes === []) {
      return true;
    }
    if ($this->selectedTerminalIndex === null) {
      $this->selectedTerminalIndex = count($this->terminalBoxes) - 1;
    } else {
      $this->selectedTerminalIndex = max(0, $this->selectedTerminalIndex - 1);
    }
    $this->inputRedirected = false;
    $this->hideCommandInput();
    $this->terminalBoxes[$this->selectedTerminalIndex]->requestTerminalFocus();
    $this->refreshTerminalStates();
    $this->terminalArea?->layout();
    return true;
  }

  private function selectNextTerminalOrCommandLine(): bool {
    if ($this->terminalBoxes === []) {
      $this->selectedTerminalIndex = null;
      $this->inputRedirected = false;
      $this->showCommandInput();
      return true;
    }
    if ($this->selectedTerminalIndex === null) {
      $this->selectedTerminalIndex = 0;
    } else if ($this->selectedTerminalIndex >= count($this->terminalBoxes) - 1) {
      $this->selectedTerminalIndex = null;
    } else {
      $this->selectedTerminalIndex++;
    }
    $this->inputRedirected = false;
    if ($this->selectedTerminalIndex === null) {
      $this->showCommandInput();
    } else {
      $this->hideCommandInput();
      $this->terminalBoxes[$this->selectedTerminalIndex]->requestTerminalFocus();
    }
    $this->refreshTerminalStates();
    $this->terminalArea?->layout();
    return true;
  }

  private function refreshTerminalStates(): void {
    foreach ($this->terminalBoxes as $index => $box) {
      $selected = $this->selectedTerminalIndex === $index;
      $box->setTerminalState(
        $selected,
        $selected && $this->inputRedirected && $this->commands[$index]->isRunning()
      );
    }
  }

  private function hideCommandInput(): void {
    $this->commandInput->setVisible(false);
    $this->terminalArea?->layout();
  }

  private function showCommandInput(): void {
    $this->selectedTerminalIndex = null;
    $this->inputRedirected = false;
    $this->refreshTerminalStates();
    $this->commandInput->setVisible(true);
    $this->terminalArea?->layout();
    $this->commandInput->requestFocus();
  }

}
