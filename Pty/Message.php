<?php

namespace MADIR\Pty;

class Message {

  const COMMAND = 1;
  const RETURNED = 2;
  const OUTPUT = 3;

  public static function send($socket, $data) {
    $returned = $data['returned'] ?? 0;
    $pid = $data['pid'] ?? 0;
    $cid = $data['cid'] ?? 0;
    if (isset($data['command'])) {
      $type = self::COMMAND;
      $streamData = $data['command'];
    } else if (isset($data['returned'])) {
      $type = self::RETURNED;
      $streamData = '';
    } else if (isset($data['output'])) {
      $type = self::OUTPUT;
      $streamData = $data['output'];
    } else {
      throw new \Exception('Unknown message on the line');
    }
    $len = strlen($streamData);
    $res = fwrite($socket, pack('CCnNN', $type, $returned, $len, $pid, $cid));
    if ($res === false) {
      throw new \Exception('Socket write error');
    }
    if ($len > 0) {
      $res = fwrite($socket, $streamData);
    }
    if ($res === false) {
      throw new \Exception('Socket write error');
    }
  }

  public static function receive($socket) {
    $header = fread($socket, 12);
    if ($header === false || $header === '') {
      return false;
    }
    $data = unpack('Ctype/Creturned/nlength/Npid/Ncid', $header);
    $len = $data['length'];
    $streamData = '';
    while (strlen($streamData) < $len) {
      $chunk = fread($socket, $len - strlen($streamData));
      if ($chunk === false || $chunk === '') {
        return false;
      }
      $streamData .= $chunk;
    }
    switch ($data['type']) {
      case self::COMMAND:
        unset($data['returned']);
        $data['command'] = $streamData;
        break;
      case self::RETURNED:
        break;
      case self::OUTPUT:
        unset($data['returned']);
        $data['output'] = $streamData;
        break;
      default:
        throw new \Exception('Unknown message on the line');
    }
    return $data;
  }

}
