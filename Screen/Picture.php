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
    $command['imageNumber'] = $id;
    $command['images'] = [];
    self::$pictures[$id] = $command;
    return $command;
  }

  private static function place($command, $terminal) {
    $id = self::getId($command);
    $image = new \SPTK\Elements\Image($terminal);
    if ($command['transmission'] === 'f') {
      $image->setValue(base64_decode($command['data']));
    } else if ($command['transmission'] === 'd') {
      $image->setBase64($command['data'] ?? '');
    }
    self::$pictures[$id]['images'][] = $image;
  }

  private static function delete($command) {
    $id = self::getId($command);
    $picture = self::$pictures[$id];
    foreach ($picture['images'] as $image) {
      $image->remove();
    }
    unset(self::$pictures[$id]);
  }

  private static function getId($command) {
    if (isset($command['imageNumber'])) {
      return $command['imageNumber'];
    }
    if (isset($command['imageId'])) {
      foreach (self::$pictures as $id => $picture) {
        if ($picture['imageId'] === $command['imageId']) {
          return $id;
        }
      }
    }
    return null;
  }

}
