<?php

namespace MADIR\Screen;

use MADIR\App\TerminalImages;

/** Parse Kitty graphics parameters from an ANSI APC sequence. */
final class Picture {

  private const KEYS = [
    'a' => 'action',
    'q' => 'quiet',
    'f' => 'format',
    't' => 'transmission',
    's' => 'width',
    'v' => 'height',
    'S' => 'dataSize',
    'O' => 'dataOffset',
    'i' => 'imageId',
    'I' => 'imageNumber',
    'p' => 'placementId',
    'o' => 'compression',
    'm' => 'moreData',
    'x' => 'sourceX',
    'y' => 'sourceY',
    'w' => 'sourceWidth',
    'h' => 'sourceHeight',
    'X' => 'cellXOffset',
    'Y' => 'cellYOffset',
    'c' => 'columns',
    'r' => 'rows',
    'C' => 'cursorMovement',
    'U' => 'virtualPlacement',
    'z' => 'zIndex',
    'P' => 'parentImageId',
    'Q' => 'parentPlacementId',
    'H' => 'horizontalOffset',
    'V' => 'verticalOffset',
    'd' => 'delete',
  ];

  public static function parseAnsi(string $sequence, ?TerminalImages $terminal): void {
    if ($terminal === null) {
      return;
    }
    $parts = explode(';', $sequence, 2);
    $command = [];
    foreach (explode(',', $parts[0]) as $field) {
      $pair = explode('=', $field, 2);
      if (count($pair) === 2 && $pair[0] !== '') {
        $command[self::KEYS[$pair[0]] ?? $pair[0]] = $pair[1];
      }
    }
    if (isset($parts[1])) {
      $command['data'] = $parts[1];
    }
    $terminal->handle($command);
  }

}
