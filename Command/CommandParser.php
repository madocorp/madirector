<?php

namespace MADIR\Command;

class CommandParser {

  private $tokens;
  private $pos;
  private $len;

  public function parse($command) {
    $this->tokenize($command);
    return $this->sequence();
  }

  private function tokenize($str) {
    $tokens = [];
    $chars = preg_split('//u', $str, null, PREG_SPLIT_NO_EMPTY);
    $isWord = true;
    $token = '';
    foreach ($chars as $i => $char) {
      if ($isWord) {
        if (ctype_space($char)) {
          if (!empty($token)) {
            $tokens[] = $token;
            $token = '';
          }
        } else if (in_array($char, ['&', '|', ';', '<', '>'])) {
          if (!empty($token)) {
            $tokens[] = $token;
            $token = '';
          }
          $isWord = false;
          $token = $char;
        } else {
          $token .= $char;
        }
      } else {
        if (in_array($char, ['&', '|', ';', '<', '>', '1', '2'])) {
          $token =  $token . $char;
        } else {
          $tokens[] = $token;
          $token = '';
          $isWord = true;
          if (!ctype_space($char)) {
            $token = $char;
          }
        }
      }
    }
    if (!empty($token)) {
      $tokens[] = $token;
    }
    $this->tokens = $tokens;
    $this->pos = 0;
    $this->len = count($tokens);
  }

  private function sequence() {
    $sequence = [];
    while ($this->pos < $this->len) {
      $pipeline = $this->pipeline();
      $item = [
        'pipeline' => $pipeline,
        'op' => false
      ];
      if (in_array($this->peek(), [';', '&&', '||'], true)) {
        $item['op'] = $this->peek();
        $this->pos++;
      }
      $sequence[] = $item;
    }
    return $sequence;
  }

  private function pipeline() {
    $commands = [];
    $commands[] = $this->command();
    while ($this->pos < $this->len) {
      if ($this->peek() !== '|') {
        break;
      }
      $this->pos++;
      $commands[] = $this->command();
    }
    return $commands;
  }

  private function command() {
    $argv = [];
    $redirects = [];
    while ($this->pos < $this->len) {
      if (in_array($this->peek(), ['|', ';', '&&', '||', '&'], true)) {
        break;
      }
      if (in_array($this->peek(), ['>', '>>', '<', '2>', '2>&1'], true)) {
        $redirects[] = $this->redirect();
        continue;
      }
      $argv[] = $this->peek();
      $this->pos++;
    }
    return [
      'argv' => $argv,
      'redirects' => $redirects
    ];
  }

  private function redirect() {
    $operator = $this->peek();
    $this->pos++;
    if ($operator === '2>&1') {
      return ['fd' => 2, 'type' => 'dup', 'target' => 1];
    }
    $fd = ($operator === '2>') ? 2 : (($operator === '<') ? 0 : 1);
    $type = $operator;
    $target = $this->peek();
    return [
      'fd' => $fd,
      'type' => $type,
      'target' => $target,
    ];
  }

  private function peek() {
    return $this->tokens[$this->pos] ?? null;
  }

}

