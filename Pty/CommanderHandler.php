<?php

namespace MADIR\Pty;

class CommanderHandler {

  private static $commanderSocket;
  private static $commands = [];
  private static $commandId = 0;

  public static function init() {
    new Libc;
    $socket = Libc::socketpair();
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $pid = pcntl_fork();
    if ($pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($pid === 0) {
      Libc::close($socket[0]); // child closes parent end
      new Commander($socket[1]);
      exit(0);
    }
    Libc::close($socket[1]); // parent closes child end
    self::$commanderSocket = $socket[0];
    Libc::setNonBlocking(self::$commanderSocket);
    cli_set_process_title('MADIR');
  }

  public static function runCommand($command) {
    self::$commandId++;
    $commandId = self::$commandId;
    self::$commands[$commandId] = $command;
    Message::send(self::$commanderSocket, [
      'cid' => $commandId,
      'command' => $command->command
    ]);
    return $commandId;
  }

  public static function sendInput($cid, $input) {
    Message::send(self::$commanderSocket, [
      'cid' => $cid,
      'input' => $input
    ]);
  }

  public static function getResults() {
    while (true) {
      $response = Message::receive(self::$commanderSocket);
      if ($response === false) {
        break;
      }
      if (!isset($response['cid'])) {
        throw new \Exception("Received message is not a valid command result");
      }
      $commandId = $response['cid'];
      if (isset(self::$commands[$commandId])) {
        $command = self::$commands[$commandId];
        if (isset($response['returned'])) {
          $command->end($response['returned']);
          unset(self::$commands[$commandId]);
        } else {
          $command->output($response['output']);
        }
      }
    }
  }

}
