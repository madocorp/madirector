<?php

namespace MADIR\App;

use SPTK\Core\{Color, Style};

/** Shared palette for app controls such as signal and tab tiles. */
final class SystemStyle {

  public static function from(Style $base): Style {
    $text = new Color(245, 246, 247);
    return $base->with([
      'Background' => new Color(70, 76, 84),
      'Foreground' => $text,
      'Selected' => $text,
      'Separator' => new Color(139, 147, 156),
      'Highlight' => new Color(190, 205, 216),
      'CursorBackground' => new Color(105, 115, 126),
    ]);
  }

}
