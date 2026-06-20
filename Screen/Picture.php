<?php

namespace MADIR\Screen;

class Picture {

  public static $pictures = [];
  private static $nextId = 1;
  private static $nextPlacementId = 1;
  private static $currentTransmissionId = null;

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
        if ($command !== null) {
          self::place($command, $terminal);
        }
        break;
      case 't':
        $command = self::transmit($command);
        if ($command !== null && !empty($command['pendingPlace'])) {
          self::place($command, $terminal);
        }
        break;
      case 'p':
        self::place($command, $terminal);
        break;
      case 'd':
        self::delete($command);
        break;
      case 'q':
        self::query($command, $terminal);
        break;
    }
  }

  private static function query($command, $terminal) {
    $id = $command['imageId'] ?? '0';
    if ($terminal === null) {
      return;
    }
    if (!in_array(($command['transmission'] ?? 'd'), ['d', 'f'], true)) {
      $terminal->respond("\e_Gi={$id};EINVAL:unsupported transmission\e\\");
      return;
    }
    if (!in_array((string)($command['format'] ?? '32'), ['24', '32', '100'], true)) {
      $terminal->respond("\e_Gi={$id};EINVAL:unsupported format\e\\");
      return;
    }
    if (isset($command['compression']) && $command['compression'] !== 'z') {
      $terminal->respond("\e_Gi={$id};EINVAL:unsupported compression\e\\");
      return;
    }
    if (isset($command['virtualPlacement']) && (int)$command['virtualPlacement'] !== 0) {
      $terminal->respond("\e_Gi={$id};EINVAL:virtual placements unsupported\e\\");
      return;
    }
    $terminal->respond("\e_Gi={$id};OK\e\\");
  }

  private static function transmit($command) {
    $id = self::assignImageId($command);
    self::$currentTransmissionId = $id;
    if (!isset(self::$pictures[$id])) {
      self::$pictures[$id] = [
        'imageId' => $id,
        'imageNumber' => $command['imageNumber'] ?? null,
        'placements' => [],
        'chunks' => [],
        'complete' => false
      ];
    }
    $picture = &self::$pictures[$id];
    if (isset($command['imageNumber'])) {
      $picture['imageNumber'] = $command['imageNumber'];
    }
    if (($command['action'] ?? 't') === 'T') {
      $picture['pendingPlace'] = true;
    }
    $picture = array_merge($picture, $command);
    $picture['chunks'][] = $command['data'] ?? '';
    if ((int)($command['moreData'] ?? 0) === 1) {
      $picture['complete'] = false;
      return null;
    }
    $picture['data'] = $picture['chunks'];
    $picture['chunks'] = [];
    $picture['complete'] = true;
    self::$currentTransmissionId = null;
    return $picture;
  }

  private static function place($command, $terminal) {
    $id = self::getId($command);
    if ($id === null || empty(self::$pictures[$id]['complete'])) {
      return;
    }
    $command = array_merge(self::$pictures[$id], $command);
    $placementId = $command['placementId'] ?? self::$nextPlacementId++;
    self::deletePlacement($id, $placementId);
    $image = new \SPTK\Elements\Image($terminal);
    $style = $image->getStyle();
    $style->set('position', 'absolute');
    $style->set('backgroundColor', '#00000000');
    if (isset($command['sourceX']) || isset($command['sourceY']) || isset($command['sourceWidth']) || isset($command['sourceHeight'])) {
      $image->setSourceRect(
        (int)($command['sourceX'] ?? 0),
        (int)($command['sourceY'] ?? 0),
        isset($command['sourceWidth']) ? (int)$command['sourceWidth'] : null,
        isset($command['sourceHeight']) ? (int)$command['sourceHeight'] : null
      );
    }
    try {
      if (($command['transmission'] ?? 'd') === 'f') {
        $path = self::decodePayload($command, false);
        if ($path === false) {
          $image->remove();
          return;
        }
        $image->setValue($path);
      } else if (($command['transmission'] ?? 'd') === 'd') {
        $data = self::decodePayload($command, true);
        if ($data === false) {
          $image->remove();
          return;
        }
        if (($command['format'] ?? '32') === '24' || ($command['format'] ?? '32') === '32') {
          if (!isset($command['width']) || !isset($command['height'])) {
            $image->remove();
            return;
          }
          $image->setRawPixels($data, (int)$command['width'], (int)$command['height'], (int)$command['format'] / 8);
        } else {
          $image->setBytes($data);
        }
      }
    } catch (\Throwable $e) {
      $image->remove();
      return;
    }
    $placementColumns = (int)($command['columns'] ?? 0);
    $placementRows = (int)($command['rows'] ?? 0);
    if ($placementColumns <= 0 && $placementRows <= 0) {
      $placementColumns = max(1, (int)ceil($image->getSourceWidth() / $terminal->getLetterWidth()));
      $placementRows = max(1, (int)ceil($image->getSourceHeight() / $terminal->getLetterHeight()));
    } else if ($placementColumns > 0 && $placementRows <= 0) {
      $height = (int)ceil($placementColumns * $terminal->getLetterWidth() * $image->getSourceHeight() / $image->getSourceWidth());
      $placementRows = max(1, (int)ceil($height / $terminal->getLetterHeight()));
    } else if ($placementRows > 0 && $placementColumns <= 0) {
      $width = (int)ceil($placementRows * $terminal->getLetterHeight() * $image->getSourceWidth() / $image->getSourceHeight());
      $placementColumns = max(1, (int)ceil($width / $terminal->getLetterWidth()));
    }
    if ($placementColumns > 0) {
      $style->set('width', ($placementColumns * $terminal->getLetterWidth()) . 'px');
    }
    if ($placementRows > 0) {
      $style->set('height', ($placementRows * $terminal->getLetterHeight()) . 'px');
    }
    [$row, $column] = $terminal->getCursorPosition();
    [$documentRow, $documentColumn] = $terminal->getCursorDocumentPosition();
    $cellXOffset = (int)($command['cellXOffset'] ?? 0);
    $cellYOffset = (int)($command['cellYOffset'] ?? 0);
    $x = $column * $terminal->getLetterWidth() + $cellXOffset;
    $y = $row * $terminal->getLetterHeight() + $cellYOffset;
    $style->set('x', "{$x}px");
    $style->set('y', "{$y}px");
    $terminal->registerInlineImage($image, $documentRow, $documentColumn, $cellXOffset, $cellYOffset, (int)($command['zIndex'] ?? 0), $id, $placementId);
    self::$pictures[$id]['placements'][$placementId] = [
      'id' => $placementId,
      'image' => $image,
      'terminal' => $terminal
    ];
    self::$pictures[$id]['pendingPlace'] = false;
    if ((int)($command['cursorMovement'] ?? 0) !== 1) {
      $terminal->advanceCursor($placementRows, $placementColumns);
    }
  }

  private static function delete($command) {
    $mode = $command['delete'] ?? '';
    $id = self::getId($command);
    if ($id !== null && isset($command['placementId'])) {
      self::deletePlacement($id, $command['placementId']);
      return;
    }
    if ($id !== null) {
      self::deleteImage($id);
      return;
    }
    if (isset($command['placementId'])) {
      foreach (array_keys(self::$pictures) as $pictureId) {
        self::deletePlacement($pictureId, $command['placementId']);
      }
      return;
    }
    if ($mode === 'a') {
      foreach (array_keys(self::$pictures) as $pictureId) {
        self::deleteImage($pictureId);
      }
    }
  }

  private static function deleteImage($id) {
    if (!isset(self::$pictures[$id])) {
      return;
    }
    foreach (array_keys(self::$pictures[$id]['placements']) as $placementId) {
      self::deletePlacement($id, $placementId);
    }
    unset(self::$pictures[$id]);
  }

  private static function deletePlacement($id, $placementId) {
    if (!isset(self::$pictures[$id]['placements'][$placementId])) {
      return;
    }
    $placement = self::$pictures[$id]['placements'][$placementId];
    $placement['terminal']->unregisterInlineImage($placement['image']);
    $placement['image']->remove();
    unset(self::$pictures[$id]['placements'][$placementId]);
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

  private static function assignImageId($command) {
    if (isset($command['imageId'])) {
      $id = (int)$command['imageId'];
      self::$nextId = max(self::$nextId, $id + 1);
      return $id;
    }
    if (self::$currentTransmissionId !== null) {
      return self::$currentTransmissionId;
    }
    return self::$nextId++;
  }

  private static function decodePayload($command, $binary) {
    $data = $command['data'] ?? '';
    $chunks = is_array($data) ? $data : [$data];
    $decoded = '';
    foreach ($chunks as $chunk) {
      if ($chunk === '') {
        continue;
      }
      $decodedChunk = base64_decode($chunk, true);
      if ($decodedChunk === false) {
        return $binary ? false : implode('', $chunks);
      }
      $decoded .= $decodedChunk;
    }
    if (($command['compression'] ?? '') === 'z') {
      $inflated = @gzuncompress($decoded);
      if ($inflated === false) {
        $inflated = @gzdecode($decoded);
      }
      if ($inflated === false) {
        return false;
      }
      $decoded = $inflated;
    }
    return $decoded;
  }

}
