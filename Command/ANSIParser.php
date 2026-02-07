<?php

namespace MADIR\Command;

class ANSIParser {

  const GROUND = 0;
  const ESCAPE = 1;
  const CSI = 2;
  const OSC = 3;

  public $screen;
  public $state = self::GROUND;
  public $buffer = '';
  public $seqLen = 0;

  public function __construct($screenBuffer) {
    $this->screen = $screenBuffer;
  }

  public function parse($str) {
    $codepoints = $this->decodeUTF8($str);
    foreach ($codepoints as $cp) {
      switch ($this->state) {
        case self::GROUND:
          if ($cp === "\e") { // ESC
            $this->state = self::ESCAPE;
          } elseif ($this->isPrintable($cp)) {
            $this->screen->putChar($cp);
          } else {
            $this->handleControl($cp);
          }
          break;
        case self::ESCAPE:
          if ($cp === '[') {
            $this->state = self::CSI;
            $this->buffer = '';
          } elseif ($cp === ']') {
            $this->state = self::OSC;
            $this->buffer = '';
          } else {
            $this->state = self::GROUND;
          }
          break;
        case self::CSI:
          $this->buffer .= $cp;
          if ($this->isFinalByte($cp)) {
            $this->executeCSI();
            $this->state = self::GROUND;
            $this->buffer = '';
          }
          break;
        case self::OSC:
          if ($cp === 0x07) { // BEL
            $this->state = self::GROUND;
          } else {
            $this->buffer .= $cp;
          }
          break;
      }
    }
  }

  public function decodeUTF8($str) {
    $out = [];
    $this->buffer .= $str;
    $i = 0;
    $len = strlen($this->buffer);
    while ($i < $len) {
      $byte = ord($this->buffer[$i]);
      if ($byte <= 0x7F) {
        $out[] = $this->buffer[$i];
        $i++;
        continue;
      }
      if (($byte & 0xE0) === 0xC0) {
        $this->seqLen = 2;
      } elseif (($byte & 0xF0) === 0xE0) {
        $this->seqLen = 3;
      } elseif (($byte & 0xF8) === 0xF0) {
        $this->seqLen = 4;
      } else {
        $out[] = "�";
        $i++;
        continue;
      }
      if ($i + $this->seqLen > $len) {
        break;
      }
      $valid = true;
      for ($j = 1; $j < $this->seqLen; $j++) {
        if ((ord($this->buffer[$i + $j]) & 0xC0) !== 0x80) {
          $valid = false;
          break;
        }
      }
      if (!$valid) {
        $out[] = "�";
        $i++;
        continue;
      }
      $out[] = substr($this->buffer, $i, $this->seqLen);
      $i += $this->seqLen;
      $this->seqLen = 0;
    }
    $this->buffer = substr($this->buffer, $i);
    return $out;
  }

  public function executeCSI() {
echo "CSI {$this->buffer}\n";
  }

  public function handleControl($cp) {
    switch ($cp) {
      case "\n":
        $this->screen->lineFeed();
        break;
      case "\r":
        $this->screen->carriageReturn();
        break;
      case "\t":
        $this->screen->tab();
        break;
      case "\b":
        $this->screen->backspace();
        break;
      case 0x07: // BEL
        break;
    }
  }

  public function isPrintable($cp) {
    $code = mb_ord($cp, 'UTF-8');
    // C0 + DEL
    if ($code <= 0x1F || $code === 0x7F) {
      return false;
    }
    // C1 controls
    if ($code >= 0x80 && $code <= 0x9F) {
      return false;
    }
    return true;
  }

  public function isFinalByte($cp) {
    $ord = ord($cp);
    if ($ord >= 0x40 && $ord <= 0x7e) {
      return true;
    }
    return false;
  }

}
