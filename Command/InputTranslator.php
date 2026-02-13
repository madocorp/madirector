<?php

use \SPTK\KeyCode;
use \SPTK\KeyModifier;

class InputTranslator {

  public function fromTextInput(string $text): string  {
    // SDL already gives UTF-8 text here — send directly
    return $text;
  }

  public function fromKeyDown(int $sym, int $mod = 0): ?string {
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
      case KeyCode::UP:    return "\e[A";
      case KeyCode::DOWN:  return "\e[B";
      case KeyCode::RIGHT: return "\e[C";
      case KeyCode::LEFT:  return "\e[D";
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
