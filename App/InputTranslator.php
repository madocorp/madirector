<?php

namespace MADIR\App;

use SPTK\Core\InputAction;
use SPTK\Core\InputEvent;

/** Converts current SPTK input events to terminal byte streams. */
class InputTranslator {

  public static function translate(InputEvent $event, bool $appCursor = false, bool $appKeypad = false): ?string {
    if ($event->type === 'text') {
      return $event->text;
    }
    if ($event->type !== 'key') {
      return null;
    }
    $ctrl = !empty($event->modifiers['ctrl']);
    $alt = !empty($event->modifiers['alt']);
    $key = InputAction::normalizedKey($event->key);
    if ($ctrl && mb_strlen($key) === 1) {
      $char = strtolower($key);
      if ($char >= 'a' && $char <= 'z') {
        return chr(ord($char) & 0x1f);
      }
    }
    $sequence = match ($key) {
      'Enter' => "\r",
      'Backspace' => "\x7f",
      'Tab' => "\t",
      'Escape' => "\e",
      'Up' => $appCursor ? "\eOA" : "\e[A",
      'Down' => $appCursor ? "\eOB" : "\e[B",
      'Right' => $appCursor ? "\eOC" : "\e[C",
      'Left' => $appCursor ? "\eOD" : "\e[D",
      'Home' => "\e[H",
      'End' => "\e[F",
      'Insert' => "\e[2~",
      'Delete' => "\e[3~",
      'PageUp' => "\e[5~",
      'PageDown' => "\e[6~",
      'F1' => "\eOP",
      'F2' => "\eOQ",
      'F3' => "\eOR",
      'F4' => "\eOS",
      'F5' => "\e[15~",
      'F6' => "\e[17~",
      'F7' => "\e[18~",
      'F8' => "\e[19~",
      'F9' => "\e[20~",
      'F10' => "\e[21~",
      'F11' => "\e[23~",
      'F12' => "\e[24~",
      default => null,
    };
    if ($sequence === null && $ctrl && $key === 'Space') {
      $sequence = "\x00";
    }
    if ($sequence === null) {
      return null;
    }
    return $alt ? "\e" . $sequence : $sequence;
  }

}
