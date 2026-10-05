<?php

namespace MADIR\App;

use SPTK\Core\Clipboard;
use SPTK\Events\KeyNormalizer;
use SPTK\SDLWrapper\SDL;

/** Translate current SPTK keyboard and text events into PTY input bytes. */
final class TerminalInput {

  /** Convert one SDL input event using the terminal's cursor and keypad modes. */
  public static function translate(mixed $event, bool $appCursor = false, bool $appKeypad = false): ?string {
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT) {
      return \FFI::string($event->text->text);
    }
    if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
      return null;
    }
    $mod = (int)$event->key->mod;
    $key = KeyNormalizer::normalize((int)$event->key->key, $mod);
    $ctrl = ($mod & SDL::MOD_CTRL) !== 0;
    $alt = ($mod & SDL::MOD_ALT) !== 0;
    if ($ctrl && !$alt && $key === ord('v')) {
      return Clipboard::get();
    }
    if ($ctrl && $key >= ord('a') && $key <= ord('z')) {
      return ($alt ? "\e" : '') . chr($key & 0x1f);
    }
    $sequence = match ($key) {
      SDL::KEY_RETURN => "\r",
      SDL::KEY_BACKSPACE => "\x7f",
      SDL::KEY_TAB => "\t",
      SDL::KEY_ESCAPE => "\e",
      SDL::KEY_UP => $appCursor ? "\eOA" : "\e[A",
      SDL::KEY_DOWN => $appCursor ? "\eOB" : "\e[B",
      SDL::KEY_RIGHT => $appCursor ? "\eOC" : "\e[C",
      SDL::KEY_LEFT => $appCursor ? "\eOD" : "\e[D",
      SDL::KEY_HOME => "\e[H",
      SDL::KEY_END => "\e[F",
      SDL::KEY_DELETE => "\e[3~",
      SDL::KEY_PAGEUP => "\e[5~",
      SDL::KEY_PAGEDOWN => "\e[6~",
      SDL::KEY_F1 => "\eOP",
      SDL::KEY_F2 => "\eOQ",
      SDL::KEY_F3 => "\eOR",
      SDL::KEY_F4 => "\eOS",
      SDL::KEY_F5 => "\e[15~",
      SDL::KEY_F6 => "\e[17~",
      SDL::KEY_F7 => "\e[18~",
      SDL::KEY_F8 => "\e[19~",
      SDL::KEY_F9 => "\e[20~",
      SDL::KEY_F10 => "\e[21~",
      SDL::KEY_F11 => "\e[23~",
      SDL::KEY_F12 => "\e[24~",
      default => null,
    };
    if ($sequence === null && $ctrl && $key === SDL::KEY_SPACE) {
      $sequence = "\x00";
    }
    return $sequence === null ? null : ($alt ? "\e" : '') . $sequence;
  }

}
