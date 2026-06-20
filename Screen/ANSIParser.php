<?php

namespace MADIR\Screen;

class ANSIParser {

  const GROUND = 0;
  const ESCAPE = 1;
  const CSI = 2;
  const OSC = 3;
  const CHARSET = 4;
  const APC = 5;
  const DCS = 6;

  const ASCII = 0;
  const DEC = 1;

  public $screen;
  public $state = self::GROUND;
  public $buffer = '';
  public $utf8Buffer = '';
  public $seqLen = 0;
  public $charset = self::ASCII;

  public $colors = [0x000000, 0xaa0000, 0x00aa00, 0xaaaa00, 0x0000aa, 0xaa00aa, 0x00aaaa, 0xaaaaaa];
  public $brightColors = [0x555555, 0xff5555, 0x55ff55, 0xffff55, 0x5555ff, 0xff55ff, 0x55ffff, 0xffffff];
  public $decMap = [
    '`' => '◆', 'a' => '▒', 'b' => '␉', 'c' => '␌',
    'd' => '␍', 'e' => '␊', 'f' => '°', 'g' => '±',
    'h' => '␤', 'i' => '␋', 'j' => '┘', 'k' => '┐',
    'l' => '┌', 'm' => '└', 'n' => '┼', 'o' => '⎺',
    'p' => '⎻', 'q' => '─', 'r' => '⎼', 's' => '⎽',
    't' => '├', 'u' => '┤', 'v' => '┴', 'w' => '┬',
    'x' => '│', 'y' => '≤', 'z' => '≥', '{' => 'π',
    '|' => '≠', '}' => '£', '~' => '·'
  ];

  public function __construct($screenBuffer) {
    $this->screen = $screenBuffer;
  }

  public function parse($str) {
    $parseUnits = $this->parseUTF8($str);
    // DEBUG:ansi $pc = false;
    foreach ($parseUnits as $pu) {
      // DEBUG:ansi $c = false;
      switch ($this->state) {
        case self::GROUND:
          if (ord($pu) === 0x90) { // DCS
            $this->buffer = '';
            $this->state = self::DCS;
          } elseif (ord($pu) === 0x9f) { // APC
            $this->buffer = '';
            $this->state = self::APC;
          } elseif (ord($pu) >= 0x80 && ord($pu) <= 0x9f) {
            // Ignore unhandled C1 controls in ground state.
          } elseif ($pu === "\e") { // ESC
            $this->state = self::ESCAPE;
            // DEBUG:ansi echo "\nESC ";
          } elseif ($this->isPrintable($pu)) {
            if (
              $this->charset === self::DEC &&
              strlen($pu) === 1 &&
              ord($pu) > 0x20 &&
              ord($pu) < 0x7F &&
              isset($this->decMap[$pu])
            ) {
              $pu = $this->decMap[$pu];
            }
            $this->screen->putChar($pu);
            // DEBUG:ansi if (!$pc) {
            // DEBUG:ansi   echo "\n";
            // DEBUG:ansi }
            // DEBUG:ansi if ($pu === ' ') {
            // DEBUG:ansi   echo '·';
            // DEBUG:ansi } else {
            // DEBUG:ansi   echo $pu;
            // DEBUG:ansi }
            // DEBUG:ansi $c = true;
          } else {
            $this->handleControl($pu);
            // DEBUG:ansi echo "\n", "0x", dechex(ord($pu));
          }
          break;
        case self::ESCAPE:
          $this->buffer = '';
          if ($pu === '[') {
            $this->state = self::CSI;
          } elseif ($pu === ']') {
            $this->state = self::OSC;
          } elseif ($pu === 'P') {
            $this->state = self::DCS;
          } elseif ($pu === '(') {
            $this->state = self::CHARSET;
          } elseif ($pu === '>') {
            // DEBUG:ansi echo "> applicationKeyPad OFF";
            $this->screen->applicationKeyPad(false);
            $this->state = self::GROUND;
          } elseif ($pu === '=') {
            // DEBUG:ansi echo "= napplicationKeyPad ON";
            $this->screen->applicationKeypad(true);
            $this->state = self::GROUND;
          } elseif ($pu === '7') {
            // DEBUG:ansi echo "7 saveCursor";
            $this->screen->saveCursor(true);
            $this->state = self::GROUND;
          } elseif ($pu === '8') {
            // DEBUG:ansi echo "8 restorCursor";
            $this->screen->restoreCursor(true);
            $this->state = self::GROUND;
          } elseif ($pu === 'D') {
            $this->screen->linefeed(false);
            $this->state = self::GROUND;
          } elseif ($pu === 'M') {
            $this->screen->reverseIndex();
            $this->state = self::GROUND;
          } elseif ($pu === 'E') {
            $this->screen->linefeed();
            $this->state = self::GROUND;
          } elseif ($pu === '_') {
            $this->state = self::APC;
          } else {
            // DEBUG:ansi echo "UKNOWN ESCAPE SEQUENCE {$pu}\n";
            $this->state = self::GROUND;
          }
          break;
        case self::CSI:
          $this->buffer .= $pu;
          if ($this->isFinalByte($pu)) {
            // DEBUG:ansi echo "{$this->buffer} CSI ";
            $this->executeCSI();
            $this->state = self::GROUND;
            $this->buffer = '';
          }
          break;
        case self::CHARSET:
          $this->buffer .= $pu;
          if ($this->buffer == '0') {
            $this->charset = self::DEC;
            // DEBUG:ansi echo "(0 CHARSET: DEC";
          } else if ($this->buffer == 'B') {
            $this->charset = self::ASCII;
            // DEBUG:ansi echo "(B CHARSET: ASCII";
          }
          $this->state = self::GROUND;
          $this->buffer = '';
          break;
        case self::OSC:
          if (ord($pu) === 0x07 || ord($pu) === 0x9c) { // BEL or ST
            // DEBUG:ansi echo "{$this->buffer} ", "0x", dechex(ord($pu)), " OSC";
            $this->state = self::GROUND;
            $this->buffer = '';
          } else if (ord($pu) === 0x5c && substr($this->buffer, -1) === "\e") { // ST
            // DEBUG:ansi echo "{$this->buffer} ", "0x", dechex(ord($pu)), " OSC";
            $this->state = self::GROUND;
            $this->buffer = '';
          } else {
            $this->buffer .= $pu;
          }
          break;
        case self::APC:
          if (ord($pu) === 0x5c && substr($this->buffer, -1) === "\e") { // ST
            // DEBUG:ansi echo "{$this->buffer} APC ";
            $this->executeAPC();
            $this->state = self::GROUND;
            $this->buffer = '';
          } else if (ord($pu) === 0x9c) { // ST
            // DEBUG:ansi echo "{$this->buffer} APC ";
            $this->executeAPC();
            $this->state = self::GROUND;
            $this->buffer = '';
          } else{
            $this->buffer .= $pu;
          }
          break;
        case self::DCS:
          if (ord($pu) === 0x5c && substr($this->buffer, -1) === "\e") { // ST
            // DEBUG:ansi echo "{$this->buffer} DCS ";
            $this->executeDCS(substr($this->buffer, 0, -1));
            $this->state = self::GROUND;
            $this->buffer = '';
          } else if (ord($pu) === 0x9c) { // ST
            // DEBUG:ansi echo "{$this->buffer} DCS ";
            $this->executeDCS($this->buffer);
            $this->state = self::GROUND;
            $this->buffer = '';
          } else {
            $this->buffer .= $pu;
          }
          break;
      }
      // DEBUG:ansi $pc = $c;
    }
  }

  public function parseUTF8($str) {
    $out = [];
    $this->utf8Buffer .= $str;
    $i = 0;
    $len = strlen($this->utf8Buffer);
    while ($i < $len) {
      $byte = ord($this->utf8Buffer[$i]);
      if ($byte <= 0x7F) {
        $out[] = $this->utf8Buffer[$i];
        $i++;
        continue;
      }
      if ($byte >= 0x80 && $byte <= 0x9F) {
        $out[] = $this->utf8Buffer[$i];
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
        if ((ord($this->utf8Buffer[$i + $j]) & 0xC0) !== 0x80) {
          $valid = false;
          break;
        }
      }
      if (!$valid) {
        $out[] = "�";
        $i++;
        continue;
      }
      $out[] = substr($this->utf8Buffer, $i, $this->seqLen);
      $i += $this->seqLen;
      $this->seqLen = 0;
    }
    $this->utf8Buffer = substr($this->utf8Buffer, $i);
    return $out;
  }

  public function executeCSI() {
    $final = substr($this->buffer, -1);
    $params = explode(';', substr($this->buffer, 0, -1));
    $private = false;
    foreach ($params as $i => $param) {
      if ($param === '') {
        $params[$i] = null;
      } else if (ctype_digit($param)) {
        $params[$i] = (int)$param;
      } else if (preg_match('/^[?>][0-9]*$/', $param)) {
        $private = true;
        continue;
      } else {
        if ($final != 'h' && $final != 'l') {
          // DEBUG:ansi echo "SKIP";
          return;
        }
      }
    }
    if ($private && !in_array($final, ['c', 'h', 'l', 'q'], true)) {
      // DEBUG:ansi echo "SKIP";
      return;
    }
    switch ($final) {
      case 'm':
        foreach ($params as $i => $param) {
          if (is_null($param)) {
            $param = 0;
          }
          if (!is_int($param)) {
            continue;
          }
          if ($param == 0) {
            $this->screen->setForeground($this->colors[7]);
            $this->screen->setBackground($this->colors[0]);
            $this->screen->setBold(false);
            $this->screen->setReverse(false);
          }
          if ($param == 1) {
            $this->screen->setBold(true);
          }
          if ($param == 7) {
            $this->screen->setReverse(true);
          }
          if ($param == 27) {
            $this->screen->setReverse(false);
          }
          if ($param >= 30 && $param <= 37) {
            if ($this->screen->isBold()) {
              $this->screen->setForeground($this->brightColors[$param - 30]);
            } else {
              $this->screen->setForeground($this->colors[$param - 30]);
            }
          }
          if ($param == 39) {
            $this->screen->setForeground($this->colors[7]);
          }
          if ($param >= 40 && $param <= 47) {
            $this->screen->setBackground($this->colors[$param - 40]);
          }
          if ($param == 49) {
            $this->screen->setBackground($this->colors[0]);
          }
          if ($param >= 90 && $param <= 97) {
            $this->screen->setForeground($this->brightColors[$param - 90]);
          }
          if ($param >= 100 && $param <= 107) {
            $this->screen->setBackground($this->brightColors[$param - 100]);
          }
          if (($param == 38 || $param == 48) && $params[$i + 1] == 2) {
            $r = (int)$params[$i + 2] ?? 0;
            $g = (int)$params[$i + 3] ?? 0;
            $b = (int)$params[$i + 4] ?? 0;
            if ($param == 38) {
              $this->screen->setForeground($r << 8 + $g << 16 + $b);
            }
            if ($param == 48) {
              $this->screen->setBackground($r << 8 + $g << 16 + $b);
            }
          }
        }
        break;
      case 'A':
        $this->screen->cursorUp($params[0] ?? 1);
        break;
      case 'B':
        $this->screen->cursorDown($params[0] ?? 1);
        break;
      case 'C':
        $this->screen->cursorRight($params[0] ?? 1);
        break;
      case 'D':
        $this->screen->cursorLeft($params[0] ?? 1);
        break;
      case 'E':
        $this->screen->cursorDown($params[0] ?? 1);
        $this->screen->cursorPos(false, 1);
        break;
      case 'F':
        $this->screen->cursorUp($params[0] ?? 1);
        $this->screen->cursorPos(false, 1);
        break;
      case 'G':
        $this->screen->cursorPos(false, $params[0] ?? 1);
        break;
      case 'd':
        $this->screen->cursorPos($params[0] ?? 1, false);
        break;
      case 'H':
      case 'f':
        $this->screen->cursorPos($params[0] ?? 1, $params[1] ?? 1);
        break;
      case 'b':
        $this->screen->repeatChar($params[0] ?? 1);
        break;
      case 'n':
        if (($params[0] ?? null) === 6) {
          [$row, $col] = $this->screen->getCursorPosition();
          $terminal = $this->screen->getTerminal();
          if ($terminal !== null) {
            $terminal->respond("\e[" . ($row + 1) . ";" . ($col + 1) . "R");
          }
        }
        break;
      case 'q':
        if (substr($this->buffer, 0, 1) === '>') {
          $terminal = $this->screen->getTerminal();
          if ($terminal !== null) {
            $terminal->respond("\eP>|madirector(1)\e\\");
          }
        }
        break;
      case 't':
        $terminal = $this->screen->getTerminal();
        if ($terminal !== null) {
          $rows = $this->screen->getRowCount();
          $cols = $this->screen->getColCount() + 1;
          $cellHeight = $terminal->getLetterHeight();
          $cellWidth = $terminal->getLetterWidth();
          if (($params[0] ?? null) === 14) {
            $terminal->respond("\e[4;" . ($rows * $cellHeight) . ";" . ($cols * $cellWidth) . "t");
          } elseif (($params[0] ?? null) === 16) {
            $terminal->respond("\e[6;{$cellHeight};{$cellWidth}t");
          } elseif (($params[0] ?? null) === 18) {
            $terminal->respond("\e[8;{$rows};{$cols}t");
          }
        }
        break;
      case 'c':
        $terminal = $this->screen->getTerminal();
        if ($terminal !== null) {
          if (substr($this->buffer, 0, 1) === '>') {
            $terminal->respond("\e[>99;1;0c");
          } else {
            $terminal->respond("\e[?62;1c");
          }
        }
        break;
      case 'J':
        $this->screen->eraseDisplay($params[0] ?? 0);
        break;
      case 'K':
        $this->screen->eraseLine($params[0] ?? 0);
        break;
      case 'L':
        $this->screen->insertLine($params[0] ?? 1);
        break;
      case 'M':
        $this->screen->deleteLine($params[0] ?? 1);
        break;
      case '@':
        $this->screen->insertChars($params[0] ?? 1);
        break;
      case 'P':
        $this->screen->deleteChars($params[0] ?? 1);
        break;
      case 'X':
        $this->screen->eraseChars($params[0] ?? 1);
        break;
      case 'S':
        $this->screen->scrollUp($params[0] ?? 1);
        break;
      case 'T':
        $this->screen->scrollDown($params[0] ?? 1);
        break;
      case 'r':
        $this->screen->scrollRegion($params[0] ?? 0, $params[1] ?? 0);
        break;
      case 's':
        $this->screen->saveCursor();
        break;
      case 'u':
        $this->screen->restoreCursor();
        break;
      case 'h':
        if ($params[0] == '?1') {
          $this->screen->applicationCursor(true);
        }
        if ($params[0] == '?25') {
          $this->screen->cursor(true);
        }
        if ($params[0] == '?47' || $params[0] == '?1047' || $params[0] == '?1049') {
          $this->screen->setCurrentBuffer(1);
        }
        break;
      case 'l':
        if ($params[0] == '?1') {
          $this->screen->applicationCursor(false);
        }
        if ($params[0] == '?25') {
          $this->screen->cursor(false);
        }
        if ($params[0] == '?47' || $params[0] == '?1047' || $params[0] == '?1049') {
          $this->screen->setCurrentBuffer(0);
        }
        break;
    }
  }

  public function executeAPC() {
    $sub = substr($this->buffer, 0, 1);
    if ($sub === 'G') {
      $sequence = substr($this->buffer, 1);
      if (substr($sequence, -1) === "\e") {
        $sequence = substr($sequence, 0, -1);
      }
      Picture::parseAnsii($sequence, $this->screen->getTerminal());
    }
  }

  public function executeDCS($sequence) {
    if (substr($sequence, 0, 2) === '+q') {
      $terminal = $this->screen->getTerminal();
      if ($terminal === null) {
        return;
      }
      $pairs = [];
      foreach (explode(';', substr($sequence, 2)) as $nameHex) {
        $name = $this->hexDecode($nameHex);
        $value = $this->termcapValue($name);
        if ($value === null) {
          continue;
        }
        $pairs[] = $nameHex . '=' . $this->hexEncode($value);
      }
      if (!empty($pairs)) {
        $terminal->respond("\eP1+r" . implode(';', $pairs) . "\e\\");
      } else {
        $terminal->respond("\eP0+r\e\\");
      }
    }
  }

  private function termcapValue($name) {
    switch ($name) {
      case 'TN':
        return 'madirector';
      case 'RGB':
        return '1';
      case 'hpa':
        return "\e[%i%p1%dG";
    }
    return null;
  }

  private function hexDecode($hex) {
    $out = '';
    $len = strlen($hex);
    for ($i = 0; $i + 1 < $len; $i += 2) {
      $out .= chr(hexdec(substr($hex, $i, 2)));
    }
    return $out;
  }

  private function hexEncode($value) {
    return bin2hex($value);
  }

  public function handleControl($pu) {
    $code = ord($pu);
    switch ($code) {
      case 0x0a: // LF
        $this->screen->lineFeed();
        break;
      case 0x0d: // CR
        $this->screen->carriageReturn();
        break;
      case 0x09: // TAN
        $this->screen->tab();
        break;
      case 0x08: // BS
        $this->screen->backspace();
        break;
      case 0x07: // BEL
        break;
      default:
        echo "UNNKNOWN CONTROL: 0x", dechex(ord($pu)), "\n";
    }
  }

  public function isPrintable($pu) {
    $code = mb_ord($pu, 'UTF-8');
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

  public function isFinalByte($pu) {
    $ord = ord($pu);
    if ($ord >= 0x40 && $ord <= 0x7e) {
      return true;
    }
    return false;
  }

  public function getCharset() {
    return $this->charset;
  }

  public function setCharset($charset) {
    $this->charset = $charset;
  }

}
