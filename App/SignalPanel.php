<?php

namespace MADIR\App;

use SPTK\Core\Style;
use SPTK\Widgets\List\ListView;
use SPTK\Widgets\Text\Text;

/** Present Linux signals in four lists with one shared description. */
final class SignalPanel {

  private const STANDARD = [
    'SIGHUP' => 'Reload configuration or report a disconnected terminal.',
    'SIGINT' => 'Interrupt the current operation, like Ctrl+C.',
    'SIGQUIT' => 'Quit and request a core dump.',
    'SIGILL' => 'Report an illegal instruction.',
    'SIGTRAP' => 'Report a tracing or breakpoint trap.',
    'SIGABRT' => 'Abort the process.',
    'SIGBUS' => 'Report an invalid memory access.',
    'SIGFPE' => 'Report an arithmetic error.',
    'SIGKILL' => 'Force immediate termination.',
    'SIGUSR1' => 'Application-defined user signal 1.',
    'SIGSEGV' => 'Report an invalid memory reference.',
    'SIGUSR2' => 'Application-defined user signal 2.',
    'SIGPIPE' => 'Report a write to a closed pipe.',
    'SIGALRM' => 'Deliver a timer alarm.',
    'SIGTERM' => 'Request graceful termination.',
    'SIGSTKFLT' => 'Report a stack fault.',
    'SIGCHLD' => 'Report a child process state change.',
    'SIGCONT' => 'Resume a stopped process.',
    'SIGSTOP' => 'Stop the process immediately.',
    'SIGTSTP' => 'Request a terminal stop, like Ctrl+Z.',
    'SIGTTIN' => 'Stop background terminal input.',
    'SIGTTOU' => 'Stop background terminal output.',
    'SIGURG' => 'Report urgent socket data.',
    'SIGXCPU' => 'Report a CPU time limit.',
    'SIGXFSZ' => 'Report a file size limit.',
    'SIGVTALRM' => 'Deliver a virtual timer alarm.',
    'SIGPROF' => 'Deliver a profiling timer alarm.',
    'SIGWINCH' => 'Report a terminal window size change.',
    'SIGIO' => 'Report available I/O.',
    'SIGPWR' => 'Report a power failure.',
    'SIGSYS' => 'Report an invalid system call.',
  ];

  private array $lists = [];
  private array $details = [];
  private int $column = 0;
  private Text $heading;
  private Text $description;
  private $refresh;

  /** Keep the intended targets even while a signal list has keyboard focus. */
  public function __construct(string $target, private array $targets, Style $style, ?callable $refresh = null) {
    $this->heading = new Text("Send signal to $target", $style, wrap: false);
    $this->description = new Text('', $style);
    $signals = [];
    foreach (self::STANDARD as $name => $description) {
      if (defined($name)) {
        $number = constant($name);
        $signals[$number] = [$name, $description];
      }
    }
    if (defined('SIGRTMIN') && defined('SIGRTMAX')) {
      for ($number = SIGRTMIN; $number <= SIGRTMAX; $number++) {
        $name = $number === SIGRTMAX ? 'SIGRTMAX' : ($number === SIGRTMIN ? 'SIGRTMIN' : 'SIGRTMIN+' . ($number - SIGRTMIN));
        $signals[$number] = [$name, 'Application-defined real-time signal.'];
      }
    }
    ksort($signals, SORT_NUMERIC);
    $groups = [
      'Process control' => [SIGTERM, SIGINT, SIGKILL],
      'Execution state' => [SIGSTOP, SIGCONT],
      'Application control' => [SIGHUP, SIGUSR1, SIGUSR2],
      'Other' => array_values(array_diff(array_keys($signals), [SIGTERM, SIGINT, SIGKILL, SIGSTOP, SIGCONT, SIGHUP, SIGUSR1, SIGUSR2])),
    ];
    foreach ($groups as $title => $numbers) {
      $items = [];
      foreach ($numbers as $number) {
        if (!isset($signals[$number])) {
          continue;
        }
        [$name, $description] = $signals[$number];
        $items[] = ['value' => (string)$number, 'label' => "[$number] $name"];
        $this->details[$number] = "$name ($number): $description";
      }
      $index = count($this->lists);
      $list = new ListView($items, filterable: false, searchable: false, style: $style, title: $title);
      $list->on('change', function() use ($index): void {
        if ($this->column === $index) {
          $this->updateDescription();
          if ($this->refresh !== null) {
            ($this->refresh)();
          }
        }
      });
      $this->lists[] = $list;
    }
    $this->updateDescription();
    $this->refresh = $refresh;
  }

  public function heading(): Text {
    return $this->heading;
  }

  public function description(): Text {
    return $this->description;
  }

  /** Return the four persistent list widgets in display order. */
  public function lists(): array {
    return $this->lists;
  }

  public function column(): int {
    return $this->column;
  }

  /** Move between signal categories while retaining each list's cursor. */
  public function moveColumn(int $step): bool {
    $next = max(0, min(count($this->lists) - 1, $this->column + $step));
    if ($next === $this->column) {
      return false;
    }
    $this->column = $next;
    $this->updateDescription();
    return true;
  }

  public function selectedSignal(): ?int {
    $value = $this->lists[$this->column]->activeValue();
    return $value === null ? null : (int)$value;
  }

  public function hasRunningTargets(): bool {
    foreach ($this->targets as $command) {
      if ($command->isRunning()) {
        return true;
      }
    }
    return false;
  }

  /** Send only to processes still running when Return is pressed. */
  public function sendSelected(): void {
    $signal = $this->selectedSignal();
    if ($signal === null) {
      return;
    }
    foreach ($this->targets as $command) {
      if ($command->isRunning()) {
        $command->terminate($signal);
      }
    }
  }

  private function updateDescription(): void {
    $signal = $this->selectedSignal();
    $this->description->setText($signal === null ? '' : $this->details[$signal]);
  }

}
