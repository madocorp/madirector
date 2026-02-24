<?php

namespace MADIR\Pty;

class Message {

  const HDR_LEN = 14;
  const COMMAND = 1;
  const RETURN = 2;
  const OUTPUT = 3;
  const INPUT = 4;

  public static function send(int $fd, array $data): void {
    [$type, $return, $pid, $cid, $payload] = self::encode($data);
    $len = strlen($payload);
    $header = pack('CCNNN', $type, $return, $len, $pid, $cid);
    Libc::queueWrite($fd, $header . $payload);
  }

  public static function receive(int $fd): ?array {
    $hdr = Libc::peek($fd, self::HDR_LEN);
    if ($hdr === '') {
      return null;
    }
    $u = unpack('Ctype/Creturn/Nlen/Npid/Ncid', $hdr);
    if (!$u) {
      throw new \Exception("Header unpack failed");
    }
    $type = $u['type'];
    $return = $u['return'];
    $len = $u['len'];
    $pid = $u['pid'];
    $cid = $u['cid'];
    $need = self::HDR_LEN + $len;
    if (Libc::inLength($fd) < $need) {
      return null;
    }
    Libc::consume($fd, self::HDR_LEN);
    $payload = ($len > 0) ? Libc::take($fd, $len) : '';
    return self::decode($type, $return, $pid, $cid, $payload);
  }

  private static function encode(array $data): array {
    $return = $data['return'] ?? 0;
    $pid = $data['pid'] ?? 0;
    $cid = $data['cid'] ?? 0;
    if (isset($data['command'])) {
      return [self::COMMAND, $return, $pid, $cid, (string)$data['command']];
    }
    if (isset($data['return'])) {
      return [self::RETURN, $return, $pid, $cid, ''];
    }
    if (isset($data['output'])) {
      return [self::OUTPUT, $return, $pid, $cid, (string)$data['output']];
    }
    if (isset($data['input'])) {
      return [self::INPUT, $return, $pid, $cid, (string)$data['input']];
    }
    throw new \InvalidArgumentException('Unknown message');
  }

  private static function decode(int $type, int $return, int $pid, int $cid, string $payload): array {
    switch ($type) {
      case self::COMMAND:
        return ['type' => $type, 'pid' => $pid, 'cid' => $cid, 'command' => $payload];
      case self::RETURN:
        return ['type' => $type, 'pid' => $pid, 'cid' => $cid, 'return' => $return];
      case self::OUTPUT:
        return ['type' => $type, 'pid' => $pid, 'cid' => $cid, 'output' => $payload];
      case self::INPUT:
        return ['type' => $type, 'pid' => $pid, 'cid' => $cid, 'input' => $payload];
      default:
        throw new \Exception("Unknown message type=$type");
    }
  }

}
