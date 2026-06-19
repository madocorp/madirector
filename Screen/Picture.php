<?php

namespace MADIR\Screen;

class Picture {

  public static $pictures = [];
  private static $nextId = 1;

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

  public static function parseAnsii($sequence, $terminal) {
    $command = [];
    $parts = explode(';', $sequence, 2);
    if ($parts[0] !== '') {
      foreach (explode(',', $parts[0]) as $field) {
        $pair = explode('=', $field, 2);
        if (count($pair) === 2 && $pair[0] !== '') {
          $name = self::KEYS[$pair[0]] ?? $pair[0];
          $command[$name] = $pair[1];
        }
      }
    }
    if (count($parts) === 2) {
      $command['data'] = $parts[1];
    }
    switch ($command['action'] ?? 't') {
      case 'T':
        $command = self::transmit($command);
        self::place($command, $terminal);
        break;
      case 't':
        self::transmit($command);
        break;
      case 'p':
        self::place($command, $terminal);
        break;
      case 'd':
        self::delete($command);
        break;
      case 'q':
        break;
    }
  }

  private static function transmit($command) {
    $id = self::$nextId++;
    $command['imageId'] = $id;
    $command['images'] = [];
    self::$pictures[$id] = $command;
    return $command;
  }

  private static function place($command, $terminal) {
    $id = self::getId($command);
    if ($id === null) {
      return;
    }
    $command = array_merge(self::$pictures[$id], $command);
    $image = new \SPTK\Elements\Image($terminal);
    if (($command['transmission'] ?? 'd') === 'f') {
      $image->setValue(base64_decode($command['data']));
    } else if (($command['transmission'] ?? 'd') === 'd') {
      $image->setBase64($command['data'] ?? '');
    }
    [$row, $column] = $terminal->getCursorPosition();
    [$documentRow, $documentColumn] = $terminal->getCursorDocumentPosition();
    $cellXOffset = (int)($command['cellXOffset'] ?? 0);
    $cellYOffset = (int)($command['cellYOffset'] ?? 0);
    $x = $column * $terminal->getLetterWidth() + $cellXOffset;
    $y = $row * $terminal->getLetterHeight() + $cellYOffset;
    $style = $image->getStyle();
    $style->set('position', 'absolute');
    $style->set('x', "{$x}px");
    $style->set('y', "{$y}px");
    $terminal->registerInlineImage($image, $documentRow, $documentColumn, $cellXOffset, $cellYOffset);
    self::$pictures[$id]['images'][] = $image;
  }

  private static function delete($command) {
    $id = self::getId($command);
    if ($id === null) {
      return;
    }
    $picture = self::$pictures[$id];
    foreach ($picture['images'] as $image) {
      $image->remove();
    }
    unset(self::$pictures[$id]);
  }

  private static function getId($command) {
    if (isset($command['imageId'])) {
      $id = $command['imageId'];
      return isset(self::$pictures[$id]) ? $id : null;
    }
    if (isset($command['imageNumber'])) {
      $pictures = array_reverse(self::$pictures, true);
      foreach ($pictures as $id => $picture) {
        if (($picture['imageNumber'] ?? null ) === $command['imageNumber']) {
          return $id;
        }
      }
    }
    return null;
  }

}
