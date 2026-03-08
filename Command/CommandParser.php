<?php
// DEBUGLEVEL:4
namespace MADIR\Command;

class CommandParser {

  private $tokens;
  private $pos = 0;
  private $len;

  public function parse($commandString) {
    $tokens = \MADIR\Command\CommandTokenizer::start([$commandString], "\MADIR\Command\CommandTokenizer");
    $this->tokens = $tokens[0]['tokens'];
    // DEBUG:4 echo "Command tokens: {$commandString}\n";
    // DEBUG:4 foreach ($this->tokens as $token) {
    // DEBUG:4  echo "  " . str_pad("[{$token['type']}]", 25) . "\"{$token['value']}\"\n";
    // DEBUG:4 }
    $this->len = count($this->tokens);
    return $this->parallel();
  }

  private function parallel() {
    $parallel = [];
    while ($this->pos < $this->len) {
      $from = $this->pos;
      $sequence = $this->sequence();
      $str = '';
      for ($i = $from; $i < $this->pos && $i < $this->len; $i++) {
        if ($this->tokens[$i]['type'] === 'PARALLEL_SEPARATOR') {
          continue;
        }
        $str .= $this->tokens[$i]['value'];
      }
      $item = [
        'sequence' => $sequence,
        'commandString' => trim($str)
      ];
      $parallel[] = $item;
    }
    return $parallel;
  }

  private function sequence() {
    $sequence = [];
    while ($this->pos < $this->len) {
      if ($this->peekType() === 'WHITESPACE') {
        $this->pos++;
        continue;
      }
      if ($this->peekType() === 'PARALLEL_SEPARATOR') {
        $this->pos++;
        break;
      }
      $pipeline = $this->pipeline();
      $item = [
        'pipeline' => $pipeline,
        'op' => false
      ];
      if (in_array($this->peekType(), ['OR', 'AND', 'COMMAND_SEPARATOR'], true)) {
        $item['op'] = $this->peekValue();
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
      if ($this->peekType() === 'WHITESPACE') {
        $this->pos++;
        continue;
      }
      if (in_array($this->peekType(), ['COMMAND_SEPARATOR', 'PARALLEL_SEPARATOR', 'AND', 'OR'], true)) {
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
      if ($this->peekType() === 'WHITESPACE') {
        $this->pos++;
        continue;
      }
      if (in_array($this->peekType(), ['PIPE', 'COMMAND_SEPARATOR', 'PARALLEL_SEPARATOR', 'AND', 'OR'], true)) {
        break;
      }
      if (in_array($this->peekType(), ['REDIRECT', 'REDIRECT_APPEND', 'REDIRECT_INPUT', 'REDIRECT_STDERR', 'REDIRECT_STDERR_STDOUT'], true)) {
        $redirects[] = $this->redirect();
        continue;
      }
      if ($this->peekType() === 'QUOTED_ARGV') {
        $argv[] = $this->qargv();
        $this->pos++;
        continue;
      }
      $argv[] = $this->peekValue();
      $this->pos++;
    }
    return [
      'argv' => $argv,
      'redirects' => $redirects
    ];
  }

  private function qargv() {
    $qargv = '';
    while ($this->pos < $this->len) {
      $this->pos++;
      if ($this->peekType() === 'QUOTED_ARGV') {
        $this->pos++;
        break;
      }
      $qargv .= $this->peekValue();
    }
    return $qargv;
  }

  private function redirect() {
    $operator = $this->peekValue();
    $this->pos++;
    if ($operator === '2>&1') {
      return ['fd' => 2, 'type' => 'dup', 'target' => 1];
    }
    $fd = ($operator === '2>') ? 2 : (($operator === '<') ? 0 : 1);
    $type = $operator;
    while ($this->pos < $this->len) {
      if ($this->peekType() === 'ARGV') {
        $target = $this->peekValue();
        $this->pos++;
        break;
      }
      if ($this->peekType() === 'QUOTED_ARGV') {
        $target = $this->qargv();
        break;
      }
      $this->pos++;
    }
    return [
      'fd' => $fd,
      'type' => $type,
      'target' => $target,
    ];
  }

  private function peekType() {
    return $this->tokens[$this->pos]['type'] ?? null;
  }

  private function peekValue() {
    return $this->tokens[$this->pos]['value'] ?? null;
  }

}

