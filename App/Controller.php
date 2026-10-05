<?php

namespace MADIR\App;

use MADIR\Pty\CommanderHandler;
use SPTK\Events\EventContext;
use SPTK\SDLWrapper\SDL;

/** Route current-SPTK lifecycle and shortcut events into MaDirector's session UI. */
final class Controller {

  private static ?Director $director = null;

  /** Start the session UI after the SDL window has been created. */
  public static function init(EventContext $event): void {
    $window = \SPTK\App::eventLoop()->windows()[0];
    self::$director = new Director($window, $window->screen('main'));
  }

  /** Poll the retained PTY commander on the SPTK event loop thread. */
  public static function tick(EventContext $event): void {
    CommanderHandler::loop();
    self::$director?->poll();
  }

  /** Submit the active prompt on Return. */
  public static function submit(EventContext $event): bool {
    return self::$director?->submit() ?? false;
  }

  /** Route mode shortcuts through the current terminal state. */
  public static function keyDown(EventContext $event): bool {
    return self::$director?->keyDown($event->input) ?? false;
  }

  /** Forward text to every running command in the focused group. */
  public static function textInput(EventContext $event): bool {
    return self::$director?->textInput($event->input) ?? false;
  }

  /** Show an older command line in the prompt. */
  public static function historyUp(EventContext $event): bool {
    return self::$director?->history(1) ?? false;
  }

  /** Show a newer command line in the prompt. */
  public static function historyDown(EventContext $event): bool {
    return self::$director?->history(-1) ?? false;
  }

  /** Select the previous terminal tile. */
  public static function previousTerminal(EventContext $event): bool {
    return self::$director?->selectTerminal(-1) ?? false;
  }

  /** Select the next terminal tile or return to the prompt. */
  public static function nextTerminal(EventContext $event): bool {
    return self::$director?->selectTerminal(1) ?? false;
  }

  /** Toggle PTY input mode with F12. */
  public static function toggleInput(EventContext $event): bool {
    return self::$director?->toggleInput() ?? false;
  }

  /** Handle the old double-Shift terminal mode gesture. */
  public static function keyUp(EventContext $event): bool {
    $key = (int)$event->input->key->key;
    if (in_array($key, [SDL::KEY_LSHIFT, SDL::KEY_RSHIFT], true)) {
      return self::$director?->shiftReleased() ?? false;
    }
    self::$director?->resetShiftGesture();
    return false;
  }

  /** Enter the selected terminal's scrollback. */
  public static function scroll(EventContext $event): bool {
    return self::$director?->startScroll() ?? false;
  }

  /** Zoom the selected terminal tile with Z. */
  public static function zoom(EventContext $event): bool {
    return self::$director?->zoom() ?? false;
  }

  /** Signal commands still running when the SDL application closes. */
  public static function close(EventContext $event): void {
    self::$director?->close();
    self::$director = null;
  }

}
