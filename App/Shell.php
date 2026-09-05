<?php

namespace MADIR\App;

use MADIR\Command\HelpFormatter;
use SPTK\Core\Element;
use SPTK\Core\InputAction;
use SPTK\Core\InputEvent;
use SPTK\Core\Len;
use SPTK\Core\Place;
use SPTK\Core\Theme;
use SPTK\Widgets\Dock;

/** Builds the first new-SPTK Madirector migration shell. */
class Shell {

  private Dock $root;
  private TerminalStack $terminalArea;
  private CommandLine $commandInput;
  /** @var AppSession[] */
  private array $sessions = [];
  private AppSession $session;
  private bool $inputRedirected = false;
  private $quitCallback = null;

  public function __construct(private int $fontSize = TerminalView::DEFAULT_CELL_HEIGHT, ?callable $quitCallback = null) {
    $this->fontSize = max(1, $this->fontSize);
    $this->quitCallback = $quitCallback;
    $this->session = $this->createSession(0);
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
        if ($child instanceof CommandLine) {
          return $child->isVisible();
        }
        return true;
      }

      protected function shouldRenderChild(Element $child): bool {
        if ($child instanceof CommandLine) {
          return $child->isVisible();
        }
        return true;
      }

      protected function separatorColor(Element $child, Place $placement): \SPTK\Core\Color|string|int {
        if ($child instanceof CommandLine) {
          return '#ffffff';
        }
        return parent::separatorColor($child, $placement);
      }
    };
    $this->root->setTheme(new Theme(bg: '#000088'));

    $this->terminalArea = new TerminalStack('terminal-area');
    $this->commandInput = new CommandLine('command-line');

    $this->root->place($this->commandInput, Place::dock('bottom', null, Len::separator()));
    $this->root->place($this->terminalArea, Place::fill());

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
    if ($this->session->runningCommand !== null && $this->session->selectedTerminalIndex !== null) {
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
    foreach ($this->sessions as $session) {
      if ($session->runningCommand !== null && !$session->runningCommand->isRunning()) {
        $session->runningCommand = null;
      }
    }
    if ($this->session->runningCommand === null && $this->inputRedirected) {
      $this->inputRedirected = false;
      $this->refreshTerminalStates();
      $this->showCommandInput();
      $this->terminalArea?->layout();
    }
  }

  private function run(string $command): void {
    try {
      $parsedCommands = $this->session->commandSession->parse($command);
    } catch (\Throwable $e) {
      $parsedCommands = [[
        'sequence' => [],
        'commandString' => $command,
      ]];
      $this->appendCompletedCommand($parsedCommands[0], "Syntax error: {$e->getMessage()}\n", 1);
      $this->showCommandInput();
      return;
    }

    $startedExternal = false;
    foreach ($parsedCommands as $parsedCommand) {
      if ($this->handleExitInternal($parsedCommand, $output)) {
        if ($output !== true) {
          $this->appendCompletedCommand($parsedCommand, (string)$output);
        }
        continue;
      }
      if ($this->handleSessionInternal($parsedCommand, $output)) {
        if ($output !== true) {
          $this->appendCompletedCommand($parsedCommand, (string)$output);
        }
        continue;
      }
      if ($this->session->commandSession->handleInternal($parsedCommand, $output)) {
        if ($output !== true) {
          $this->appendCompletedCommand($parsedCommand, (string)$output);
        }
        continue;
      }
      $this->startExternalCommand($parsedCommand);
      $startedExternal = true;
    }
    if (!$startedExternal) {
      $this->showCommandInput();
    }
  }

  private function handleExitInternal(array $command, mixed &$output): bool {
    $argv = $command['sequence'][0]['pipeline'][0]['argv'] ?? [];
    if (($argv[0] ?? null) !== 'exit') {
      return false;
    }
    $force = false;
    $target = null;
    for ($i = 1; $i < count($argv); $i++) {
      switch ($argv[$i]) {
        case '-a':
          if ($this->hasRunningCommands() && !$force) {
            $output = "There are running commands.\nUse \e[1;37mexit -f -a\e[0m to kill them and quit.\n";
            return true;
          }
          if ($force) {
            $this->terminateAllRunningCommands();
          }
          if ($this->quitCallback !== null) {
            call_user_func($this->quitCallback);
          }
          $output = true;
          return true;
        case '-c':
          $target = $this->session;
          break;
        case '-f':
          $force = true;
          break;
        case '-s':
          $i++;
          $sessionName = $argv[$i] ?? '';
          if ($sessionName === '') {
            $output = "Specify a session.\n";
            return true;
          }
          $target = $this->findSession($sessionName);
          if ($target === null) {
            $output = "Session not found.\n";
            return true;
          }
          break;
        default:
          $output = "Unknown option \"{$argv[$i]}\".\n";
          return true;
      }
    }
    if ($target === null) {
      $output = $this->exitHelp();
      return true;
    }
    if ($target->hasRunningCommands()) {
      if (!$force) {
        $output = "Session has running commands.\nUse \e[1;37m-f\e[0m to kill them.\n";
        return true;
      }
      $target->terminateRunningCommands();
    }
    $output = $this->closeSession($target);
    return true;
  }

  private function exitHelp(): string {
    return "\e[1;37m\"exit\" closes a session or quits the application.\e[0m\n"
      . HelpFormatter::rows([
        ['exit', 'Show this help message.'],
        ['exit -c', 'Close the current session if nothing is running.'],
        ['exit -s ID|name', 'Close a specific session if nothing is running in it.'],
        ['exit -f', 'Kill running commands in the target session, then close it.'],
        ['exit -a', 'Quit MaDirector.'],
      ], 18);
  }

  private function handleSessionInternal(array $command, mixed &$output): bool {
    $commandString = trim($command['commandString']);
    if (ctype_digit($commandString)) {
      $this->selectSession((int)$commandString);
      $output = true;
      return true;
    }
    if (!preg_match('/^(session|s)( |$)/', $commandString)) {
      return false;
    }
    $argument = trim(preg_replace('/^(session|s)( |$)/', '', $commandString, 1));
    if ($argument === '') {
      $output = $this->sessionHelp();
      return true;
    }
    if ($argument === '-l') {
      $output = $this->sessionListText();
      return true;
    }
    if (str_starts_with($argument, '-n')) {
      $this->session->name = trim(substr($argument, 2)) ?: null;
      $output = true;
      return true;
    }
    if ($argument === '-c') {
      $this->clearCurrentSession();
      $output = true;
      return true;
    }
    if (str_starts_with($argument, '-d')) {
      $output = $this->deleteSession(trim(substr($argument, 2)));
      return true;
    }
    if (ctype_digit($argument)) {
      $this->selectSession((int)$argument);
    } else {
      $this->selectSessionByName($argument);
    }
    $output = true;
    return true;
  }

  private function sessionHelp(): string {
    return "\e[1;37m\"session\" is an internal command used to manage sessions.\e[0m\n"
      . "Each session has an ID and may have a name.\n"
      . "If a session does not exist, it will be created.\n"
      . "The short form of this command is \e[1;37m\"s\"\e[0m. You can also switch sessions by typing the session ID without using the command.\n"
      . HelpFormatter::rows([
        ['session', 'Show this help message.'],
        ['session -l', 'List all sessions.'],
        ['session -n name', 'Set a name for the current session.'],
        ['session ID|name', 'Switch to the specified session.'],
        ['session -d ID', 'Delete the specified session.'],
        ['session -c', 'Clear the session screen by removing all completed commands.'],
      ], 22);
  }

  private function sessionListText(): string {
    $list = [];
    foreach ($this->sessions as $id => $session) {
      $current = $session === $this->session ? '*' : ' ';
      $list[] = str_pad($current . $id, 3, ' ', STR_PAD_LEFT) . ' ' . ($session->name ?? '');
    }
    return implode("\n", $list) . "\n";
  }

  private function selectSession(int $id): void {
    if (!isset($this->sessions[$id])) {
      $this->createSession($id);
      ksort($this->sessions, SORT_NUMERIC);
    }
    $this->session = $this->sessions[$id];
    $this->inputRedirected = false;
    $this->rebuildTerminalStack();
    $this->showCommandInput();
  }

  private function selectSessionByName(string $name): void {
    foreach ($this->sessions as $session) {
      if ($session->name === $name) {
        $this->session = $session;
        $this->inputRedirected = false;
        $this->rebuildTerminalStack();
        $this->showCommandInput();
        return;
      }
    }
    for ($id = 0; $id < 999; $id++) {
      if (!isset($this->sessions[$id])) {
        $session = $this->createSession($id);
        $session->name = $name;
        ksort($this->sessions, SORT_NUMERIC);
        $this->selectSession($id);
        return;
      }
    }
  }

  private function deleteSession(string $id): string|bool {
    if ($id === '' || !ctype_digit($id)) {
      return "Specify a sessionId!\n";
    }
    $id = (int)$id;
    if (!isset($this->sessions[$id])) {
      return "Session {$id} not found.\n";
    }
    if ($this->sessions[$id]->hasRunningCommands()) {
      return "Session {$id} has a running command.\n";
    }
    return $this->closeSession($this->sessions[$id]);
  }

  private function closeSession(AppSession $session): string|bool {
    if (count($this->sessions) === 1) {
      return "You can't delete the last session.\n";
    }
    $wasCurrent = $session === $this->session;
    unset($this->sessions[$session->id]);
    ksort($this->sessions, SORT_NUMERIC);
    if ($wasCurrent) {
      $firstId = array_key_first($this->sessions);
      $this->session = $this->sessions[$firstId];
      $this->inputRedirected = false;
      $this->rebuildTerminalStack();
      $this->showCommandInput();
    }
    return $this->sessionListText();
  }

  private function findSession(string $name): ?AppSession {
    if (ctype_digit($name)) {
      return $this->sessions[(int)$name] ?? null;
    }
    foreach ($this->sessions as $session) {
      if ($session->name === $name) {
        return $session;
      }
    }
    return null;
  }

  private function hasRunningCommands(): bool {
    foreach ($this->sessions as $session) {
      if ($session->hasRunningCommands()) {
        return true;
      }
    }
    return false;
  }

  private function terminateAllRunningCommands(): void {
    foreach ($this->sessions as $session) {
      $session->terminateRunningCommands();
    }
  }

  private function clearCurrentSession(): void {
    $commands = [];
    $terminalBoxes = [];
    foreach ($this->session->commands as $index => $command) {
      if ($command->isRunning()) {
        $commands[] = $command;
        $terminalBoxes[] = $this->session->terminalBoxes[$index];
      }
    }
    $this->session->commands = $commands;
    $this->session->terminalBoxes = $terminalBoxes;
    $this->session->selectedTerminalIndex = null;
    $this->rebuildTerminalStack();
  }

  private function createSession(int $id): AppSession {
    $session = new AppSession($id);
    $session->commandSession->setRunCommandStringsCallback(fn(array $commands) => $this->runCommandStrings($commands));
    $this->sessions[$id] = $session;
    return $session;
  }

  private function runCommandStrings(array $commands): void {
    foreach ($commands as $command) {
      $this->run($command);
    }
  }

  private function startExternalCommand(array $command): void {
    $this->hideCommandInput();
    $this->inputRedirected = true;
    $runningCommand = new RunningCommand($command, $this->session->commandSession->cwd(), $this->session->commandSession->getenv());
    $terminalBox = $this->appendCommand($runningCommand);
    $this->session->runningCommand = $runningCommand;
    $runningCommand->start();
    $terminalBox->requestTerminalFocus();
    $this->terminalArea->layout();
  }

  private function appendCompletedCommand(array $command, string $output, int $returnValue = 0): void {
    $runningCommand = RunningCommand::completed($command, $this->session->commandSession->cwd(), $this->session->commandSession->getenv(), $output, $returnValue);
    $this->appendCommand($runningCommand);
  }

  private function appendCommand(RunningCommand $runningCommand): TerminalBox {
    $terminalBox = new TerminalBox('terminal-box-' . $this->session->id . '-' . count($this->session->commands), $runningCommand, $this->fontSize);
    $runningCommand->attachBox($terminalBox);
    $this->session->commands[] = $runningCommand;
    $this->session->terminalBoxes[] = $terminalBox;
    $this->session->selectedTerminalIndex = count($this->session->terminalBoxes) - 1;
    $this->refreshTerminalStates();
    $this->rebuildTerminalStack();
    $this->terminalArea->setSelectedBox($terminalBox, 'bottom');
    return $terminalBox;
  }

  private function toggleInputRedirection(): bool {
    if ($this->session->selectedTerminalIndex === null || !isset($this->session->terminalBoxes[$this->session->selectedTerminalIndex])) {
      return true;
    }
    if ($this->session->commands[$this->session->selectedTerminalIndex]->isRunning() === false) {
      $this->inputRedirected = false;
      $this->hideCommandInput();
      $this->refreshTerminalStates();
      return true;
    }
    $this->inputRedirected = !$this->inputRedirected;
    if ($this->inputRedirected) {
      $this->session->terminalBoxes[$this->session->selectedTerminalIndex]->requestTerminalFocus();
    }
    $this->hideCommandInput();
    $this->refreshTerminalStates();
    return true;
  }

  private function selectPreviousTerminal(): bool {
    if ($this->session->terminalBoxes === []) {
      return true;
    }
    if ($this->session->selectedTerminalIndex === null) {
      $this->session->selectedTerminalIndex = count($this->session->terminalBoxes) - 1;
    } else {
      $this->session->selectedTerminalIndex = max(0, $this->session->selectedTerminalIndex - 1);
    }
    $this->inputRedirected = false;
    $this->hideCommandInput();
    $this->session->terminalBoxes[$this->session->selectedTerminalIndex]->requestTerminalFocus();
    $this->refreshTerminalStates();
    $this->terminalArea?->setSelectedBox($this->session->terminalBoxes[$this->session->selectedTerminalIndex], 'top');
    return true;
  }

  private function selectNextTerminalOrCommandLine(): bool {
    if ($this->session->terminalBoxes === []) {
      $this->session->selectedTerminalIndex = null;
      $this->inputRedirected = false;
      $this->showCommandInput();
      return true;
    }
    if ($this->session->selectedTerminalIndex === null) {
      $this->showCommandInput();
      return true;
    } else if ($this->session->selectedTerminalIndex >= count($this->session->terminalBoxes) - 1) {
      $this->session->selectedTerminalIndex = null;
    } else {
      $this->session->selectedTerminalIndex++;
    }
    $this->inputRedirected = false;
    if ($this->session->selectedTerminalIndex === null) {
      $this->showCommandInput();
    } else {
      $this->hideCommandInput();
      $this->session->terminalBoxes[$this->session->selectedTerminalIndex]->requestTerminalFocus();
    }
    $this->refreshTerminalStates();
    if ($this->session->selectedTerminalIndex !== null) {
      $this->terminalArea?->setSelectedBox($this->session->terminalBoxes[$this->session->selectedTerminalIndex], 'bottom');
    }
    return true;
  }

  private function refreshTerminalStates(): void {
    foreach ($this->session->terminalBoxes as $index => $box) {
      $selected = $this->session->selectedTerminalIndex === $index;
      $box->setTerminalState(
        $selected,
        $selected && $this->inputRedirected && $this->session->commands[$index]->isRunning()
      );
    }
  }

  private function rebuildTerminalStack(): void {
    foreach ($this->terminalArea->children() as $child) {
      $this->terminalArea->remove($child);
    }
    foreach (array_reverse($this->session->terminalBoxes) as $box) {
      $this->terminalArea->place($box, Place::dock('bottom', Len::content()));
    }
    $this->terminalArea->layout();
  }

  private function hideCommandInput(): void {
    $this->commandInput->setVisible(false);
    $this->root?->layout();
    $this->terminalArea?->layout();
  }

  private function showCommandInput(): void {
    $this->session->selectedTerminalIndex = null;
    $this->inputRedirected = false;
    $this->refreshTerminalStates();
    $this->commandInput->setVisible(true);
    $this->terminalArea?->setSelectedBox(null);
    $this->root?->layout();
    $this->terminalArea?->layout();
    $this->commandInput->requestFocus();
  }

}
