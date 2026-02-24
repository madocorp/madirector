<?php

namespace MADIR\Screen;

use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;

class InputTranslator {

  public static function translate(int $sym, int $mod = 0, bool $appCursor, bool $appKeypad): ?string {
    // Ctrl modifier mask depends on your SDL FFI binding
    $ctrl = ($mod & KeyModifier::CTRL) !== 0;
    $alt  = ($mod & KeyModifier::ALT)  !== 0;
    // ---- CTRL COMBINATIONS ----
    if ($ctrl) {
      // Ctrl + A–Z → ASCII 1–26
      if ($sym >= KeyCode::A && $sym <= KeyCode::Z) {
        $char = chr($sym - KeyCode::A + ord('a'));
        $code = chr(ord($char) & 0x1f);
        return $code;
      }
      switch ($sym) {
        case KeyCode::SPACE: return "\x00";
        case KeyCode::LEFT:  return "\e[1;5D";
        case KeyCode::RIGHT: return "\e[1;5C";
        case KeyCode::UP:    return "\e[1;5A";
        case KeyCode::DOWN:  return "\e[1;5B";
      }
    }
    // ---- BASIC KEYS ----
    switch ($sym) {
      case KeyCode::RETURN:    return "\r";
      case KeyCode::BACKSPACE: return "\x7f";
      case KeyCode::TAB:       return "\t";
      case KeyCode::ESCAPE:    return "\e";
      // arrows
      case KeyCode::UP:    return $appCursor ? "\eOA" : "\e[A";
      case KeyCode::DOWN:  return $appCursor ? "\eOB" : "\e[B";
      case KeyCode::RIGHT: return $appCursor ? "\eOC" : "\e[C";
      case KeyCode::LEFT:  return $appCursor ? "\eOD" : "\e[D";
      // navigation
      case KeyCode::HOME:     return "\e[H";
      case KeyCode::END:      return "\e[F";
      case KeyCode::DELETE:   return "\e[3~";
      case KeyCode::PAGEUP:   return "\e[5~";
      case KeyCode::PAGEDOWN: return "\e[6~";
      // function keys
      case KeyCode::F1:  return "\eOP";
      case KeyCode::F2:  return "\eOQ";
      case KeyCode::F3:  return "\eOR";
      case KeyCode::F4:  return "\eOS";
      case KeyCode::F5:  return "\e[15~";
      case KeyCode::F6:  return "\e[17~";
      case KeyCode::F7:  return "\e[18~";
      case KeyCode::F8:  return "\e[19~";
      case KeyCode::F9:  return "\e[20~";
      case KeyCode::F10: return "\e[21~";
      case KeyCode::F11: return "\e[23~";
      case KeyCode::F12: return "\e[24~";
      // keypad keys
      case KeyCode::KP_PERIOD:   return $appKeypad ? "\eOn" : '.';
      case KeyCode::KP_ENTER:    return $appKeypad ? "\eOM" : '\r';
      case KeyCode::KP_PLUS:     return $appKeypad ? "\eOk" : '+';
      case KeyCode::KP_MINUS:    return $appKeypad ? "\eOm" : '-';
      case KeyCode::KP_MULTIPLY: return $appKeypad ? "\eOj" : '*';
      case KeyCode::KP_DIVIDE:   return $appKeypad ? "\eOo" : '/';
      case KeyCode::KP_0: return $appKeypad ? "\eOp" : 0;
      case KeyCode::KP_1: return $appKeypad ? "\eOq" : 1;
      case KeyCode::KP_2: return $appKeypad ? "\eOr" : 2;
      case KeyCode::KP_3: return $appKeypad ? "\eOs" : 3;
      case KeyCode::KP_4: return $appKeypad ? "\eOt" : 4;
      case KeyCode::KP_5: return $appKeypad ? "\eOu" : 5;
      case KeyCode::KP_6: return $appKeypad ? "\eOv" : 6;
      case KeyCode::KP_7: return $appKeypad ? "\eOw" : 7;
      case KeyCode::KP_8: return $appKeypad ? "\eOx" : 8;
      case KeyCode::KP_9: return $appKeypad ? "\eOy" : 9;
    }
    // ---- ALT HANDLING ----
    // Alt modifies by prefixing ESC
    if ($alt && $sym >= KeyCode::A && $sym <= KeyCode::Z) {
      $char = chr($sym - KeyCode::A + ord('a'));
      return "\e" . $char;
    }
    return null;
  }

}
