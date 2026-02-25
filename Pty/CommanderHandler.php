<?php

namespace MADIR\Pty;

class CommanderHandler {

  private static $commanderSocket;
  private static $commands = [];
  private static $commandId = 0;
  private static $pid;

  public static function init() {
    new Libc;
    $socket = Libc::socketpair();
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    self::$pid = pcntl_fork();
    if (self::$pid == -1) {
      throw new \Exception('Could not fork!');
    } else if (self::$pid === 0) {
      Libc::close($socket[0]); // child closes parent end
      new Commander($socket[1]);
      exit(0);
    }
    pcntl_signal(SIGCHLD, [CommanderHandler::class, 'childEnd']);
    Libc::close($socket[1]); // parent closes child end
    self::$commanderSocket = $socket[0];
    Libc::setNonBlocking(self::$commanderSocket);
    cli_set_process_title('MADIR');
  }

  public static function runCommand($command) {
    self::$commandId++;
    $commandId = self::$commandId;
    self::$commands[$commandId] = $command;
    // DEBUG:8 echo "MSGSND: main->commander [command]\n";
    Message::send(self::$commanderSocket, [
      'cid' => $commandId,
      'command' => $command->command
    ]);
    return $commandId;
  }

  public static function sendInput($cid, $input) {
    // DEBUG:8 echo "MSGSND: main->commander [input]\n";
    Message::send(self::$commanderSocket, [
      'cid' => $cid,
      'input' => $input
    ]);
  }

  public static function loop() {
    $events = IO::pollAndReceive(0, [self::$commanderSocket]);
    foreach ($events as $item) {
      $message = $item['msg'];
      if ($item['alive'] < 1) {
        echo "Commander socket has been closed A\n";
        exit(1);
      }
      $commandId = $message['cid'];
      if (isset(self::$commands[$commandId])) {
        $command = self::$commands[$commandId];
        if (isset($message['return'])) {
          // DEBUG:8 echo "MSGRCV: main [return]\n";
          $command->end($message['return']);
          unset(self::$commands[$commandId]);
        } else {
          // DEBUG:8 echo "MSGRCV: main [output]\n";
          $command->output($message['output']);
        }
      }
    }
  }

  public static function childEnd() {
    $pid = pcntl_waitpid(-1, $status);
    if ($pid === self::$pid) {
      echo "Commander exited: {$status}\n";
      exit(1);
    }
  }

}
