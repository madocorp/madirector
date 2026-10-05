<?php

namespace MADIR\App;

use MADIR\Screen\ScreenBuffer;
use SPTK\Core\RasterImage;

/** Keep Kitty image transmissions and placements with their owning terminal buffer. */
final class TerminalImages {

  private array $images = [];
  private int $nextImageId = 1;
  private int $nextPlacementId = 1;
  private ?int $transmissionId = null;

  /** Bind image placement to one screen buffer and its PTY response channel. */
  public function __construct(private ScreenBuffer $buffer, private $respond) {
  }

  /** Handle one parsed Kitty graphics command. */
  public function handle(array $command): void {
    switch ($command['action'] ?? 't') {
      case 'T':
      case 't':
        $this->transmit($command);
        break;
      case 'p':
        $this->place($command);
        break;
      case 'd':
        $this->delete($command);
        break;
      case 'q':
        $this->query($command);
        break;
    }
  }

  /** Report current placements in painter order. */
  public function placements(): array {
    $placements = [];
    foreach ($this->images as $image) {
      foreach ($image['placements'] as $placement) {
        $placements[] = $placement;
      }
    }
    usort($placements, static fn(array $a, array $b): int => $a['zIndex'] <=> $b['zIndex']);
    return $placements;
  }

  /** Check for placements without sorting the image collection. */
  public function hasPlacements(): bool {
    foreach ($this->images as $image) {
      if ($image['placements'] !== []) {
        return true;
      }
    }
    return false;
  }

  /** Answer the same capability query as the former terminal implementation. */
  public function respond(string $stream): void {
    ($this->respond)($stream);
  }

  public function getLetterWidth(): int {
    return \SPTK\App::fontOrNull()?->cellWidth() ?? 8;
  }

  public function getLetterHeight(): int {
    return \SPTK\App::fontOrNull()?->cellHeight() ?? 16;
  }

  /** Accumulate chunks and decode only after the final transmission. */
  private function transmit(array $command): void {
    $id = $this->transmissionId ?? $this->assignImageId($command);
    if (isset($command['imageId'])) {
      $id = $this->assignImageId($command);
    }
    $image = $this->images[$id] ?? ['command' => [], 'chunks' => [], 'raster' => null, 'placements' => []];
    if ($image['chunks'] === []) {
      $image['command'] = $command;
      $image['placements'] = [];
    } else {
      $image['command'] = array_merge($image['command'], $command);
    }
    $image['chunks'][] = $command['data'] ?? '';
    $image['command']['imageId'] = $id;
    $image['command']['pendingPlace'] = ($image['command']['pendingPlace'] ?? false) || (($command['action'] ?? '') === 'T');
    $this->images[$id] = $image;
    if ((int)($command['moreData'] ?? 0) === 1) {
      $this->transmissionId = $id;
      return;
    }
    $this->transmissionId = null;
    $image['command']['chunks'] = $image['chunks'];
    $image['raster'] = TerminalImageDecoder::decode($image['command']);
    $image['chunks'] = [];
    $this->images[$id] = $image;
    if ($image['raster'] !== null && $image['command']['pendingPlace']) {
      $this->place($image['command']);
    }
  }

  /** Place a decoded image at the buffer cursor in document coordinates. */
  private function place(array $command): void {
    $id = $this->findImageId($command);
    if ($id === null) {
      return;
    }
    $source = $this->images[$id]['raster'];
    if (!$source instanceof RasterImage) {
      return;
    }
    $command = array_merge($this->images[$id]['command'], $command);
    $raster = TerminalImageDecoder::crop($source, $command);
    if ($raster === null) {
      return;
    }
    if (isset($command['placementId'])) {
      $placementId = (int)$command['placementId'];
      $this->nextPlacementId = max($this->nextPlacementId, $placementId + 1);
    } else {
      $placementId = $this->nextPlacementId++;
    }
    $cellWidth = $this->getLetterWidth();
    $cellHeight = $this->getLetterHeight();
    $columns = (int)($command['columns'] ?? 0);
    $rows = (int)($command['rows'] ?? 0);
    if ($columns < 1 && $rows < 1) {
      $columns = max(1, (int)ceil($raster->width / $cellWidth));
      $rows = max(1, (int)ceil($raster->height / $cellHeight));
    } else if ($columns < 1) {
      $columns = max(1, (int)ceil($rows * $cellHeight * $raster->width / $raster->height / $cellWidth));
    } else if ($rows < 1) {
      $rows = max(1, (int)ceil($columns * $cellWidth * $raster->height / $raster->width / $cellHeight));
    }
    [$row, $column] = $this->buffer->getCursorDocumentPosition();
    $this->images[$id]['placements'][$placementId] = [
      'id' => $placementId,
      'imageId' => $id,
      'raster' => $raster,
      'row' => $row,
      'column' => $column,
      'cellXOffset' => (int)($command['cellXOffset'] ?? 0),
      'cellYOffset' => (int)($command['cellYOffset'] ?? 0),
      'columns' => $columns,
      'rows' => $rows,
      'alternate' => $this->buffer->isAltScreenActive(),
      'zIndex' => (int)($command['zIndex'] ?? 0),
    ];
    $this->images[$id]['command']['pendingPlace'] = false;
    if ((int)($command['cursorMovement'] ?? 0) !== 1) {
      $this->buffer->advanceCursor($rows, $columns);
    }
  }

  /** Remove image data or selected placements by Kitty identifier. */
  private function delete(array $command): void {
    $id = $this->findImageId($command);
    if ($id !== null && isset($command['placementId'])) {
      unset($this->images[$id]['placements'][(int)$command['placementId']]);
    } else if ($id !== null) {
      unset($this->images[$id]);
    } else if (isset($command['placementId'])) {
      foreach ($this->images as &$image) {
        unset($image['placements'][(int)$command['placementId']]);
      }
      unset($image);
    } else if (($command['delete'] ?? '') === 'a') {
      $this->images = [];
    }
  }

  /** Find an existing image by explicit ID or most recent image number. */
  private function findImageId(array $command): ?int {
    if (isset($command['imageId'])) {
      $id = (int)$command['imageId'];
      return isset($this->images[$id]) ? $id : null;
    }
    if (isset($command['imageNumber'])) {
      foreach (array_reverse($this->images, true) as $id => $image) {
        if (($image['command']['imageNumber'] ?? null) === $command['imageNumber']) {
          return $id;
        }
      }
    }
    return null;
  }

  /** Allocate IDs locally so separate terminal processes cannot share image state. */
  private function assignImageId(array $command): int {
    if (isset($command['imageId'])) {
      $id = (int)$command['imageId'];
      $this->nextImageId = max($this->nextImageId, $id + 1);
      return $id;
    }
    return $this->nextImageId++;
  }

  /** Respond to a graphics capability query without creating an image. */
  private function query(array $command): void {
    $id = $command['imageId'] ?? '0';
    $error = null;
    if (!in_array(($command['transmission'] ?? 'd'), ['d', 'f'], true)) {
      $error = 'unsupported transmission';
    } else if (!in_array((string)($command['format'] ?? '32'), ['24', '32', '100'], true)) {
      $error = 'unsupported format';
    } else if (isset($command['compression']) && $command['compression'] !== 'z') {
      $error = 'unsupported compression';
    } else if (isset($command['virtualPlacement']) && (int)$command['virtualPlacement'] !== 0) {
      $error = 'virtual placements unsupported';
    }
    $this->respond("\e_Gi={$id};" . ($error === null ? 'OK' : "EINVAL:{$error}") . "\e\\");
  }

}
