<?php

namespace MADIR\App;

/** Apply MaDirector's session and exit commands to retained session state. */
final class SessionControl {

  /** Route a session command and update the selected session when requested. */
  public static function session(string $argument, array &$sessions, AppSession &$current, callable $create): string|bool {
    if ($argument === '-l') {
      return self::list($sessions, $current);
    }
    if (str_starts_with($argument, '-n ')) {
      $current->name = trim(substr($argument, 3)) ?: null;
      return true;
    }
    if ($argument === '-c') {
      $commands = [];
      $groups = [];
      foreach ($current->commands as $index => $command) {
        if ($command->isRunning()) {
          $commands[] = $command;
          $groups[] = $current->commandGroupIds[$index] ?? null;
        }
      }
      $current->commands = $commands;
      $current->commandGroupIds = $groups;
      return true;
    }
    if (str_starts_with($argument, '-d ')) {
      $target = self::find(trim(substr($argument, 3)), $sessions);
      if ($target === null) {
        return "Session not found.\n";
      }
      return self::remove($target, $sessions, $current, false);
    }
    if ($argument === '') {
      return "session ID|name | -l | -n NAME | -c | -d ID\n";
    }
    $id = ctype_digit($argument) ? (int)$argument : null;
    if ($id === null) {
      $found = self::find($argument, $sessions);
      $id = $found?->id;
      if ($id === null) {
        $id = $sessions === [] ? 0 : max(array_keys($sessions)) + 1;
        $create($id)->name = $argument;
      }
    }
    $current = $sessions[$id] ?? $create($id);
    return true;
  }

  /** Handle exit options, including forced shutdown of running PTYs. */
  public static function exit(array $command, array &$sessions, AppSession &$current, callable $quit, mixed &$output): bool {
    $argv = $command['sequence'][0]['pipeline'][0]['argv'] ?? [];
    if (($argv[0] ?? null) !== 'exit') {
      return false;
    }
    $force = in_array('-f', $argv, true);
    if (in_array('-a', $argv, true)) {
      foreach ($sessions as $session) {
        if ($session->hasRunningCommands() && !$force) {
          $output = "There are running commands. Use exit -f -a to stop them.\n";
          return true;
        }
      }
      if ($force) {
        foreach ($sessions as $session) {
          $session->terminateRunningCommands();
        }
      }
      $quit();
      $output = true;
      return true;
    }
    $target = null;
    if (in_array('-c', $argv, true)) {
      $target = $current;
    }
    $index = array_search('-s', $argv, true);
    if ($index !== false) {
      $target = self::find($argv[$index + 1] ?? '', $sessions);
      if ($target === null) {
        $output = "Session not found.\n";
        return true;
      }
    }
    if ($target === null) {
      $output = "exit -c | -s ID|name | -a; add -f to stop running commands.\n";
      return true;
    }
    $output = self::remove($target, $sessions, $current, $force);
    return true;
  }

  /** Find a session by numeric ID or user-assigned name. */
  private static function find(string $name, array $sessions): ?AppSession {
    if (ctype_digit($name)) {
      return $sessions[(int)$name] ?? null;
    }
    foreach ($sessions as $session) {
      if ($session->name === $name) {
        return $session;
      }
    }
    return null;
  }

  /** Remove a session only after its running command policy is satisfied. */
  private static function remove(AppSession $target, array &$sessions, AppSession &$current, bool $force): string|bool {
    if (count($sessions) === 1) {
      return "You cannot delete the last session.\n";
    }
    if ($target->hasRunningCommands() && !$force) {
      return "Session has running commands. Use -f to stop them.\n";
    }
    if ($force) {
      $target->terminateRunningCommands();
    }
    unset($sessions[$target->id]);
    if ($target === $current) {
      $current = $sessions[array_key_first($sessions)];
    }
    return self::list($sessions, $current);
  }

  /** Format the current and named sessions for command output. */
  private static function list(array $sessions, AppSession $current): string {
    $lines = [];
    foreach ($sessions as $id => $session) {
      $lines[] = ($session === $current ? '* ' : '  ') . $id . ' ' . ($session->name ?? '');
    }
    return implode("\n", $lines) . "\n";
  }

}
