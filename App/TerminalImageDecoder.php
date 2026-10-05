<?php

namespace MADIR\App;

use SPTK\Core\RasterImage;
use SPTK\Rendering\ImagePixels;

/** Decode Kitty image payloads into immutable rasters used by terminal placements. */
final class TerminalImageDecoder {

  /** Decode a completed transmission, or reject unsupported and invalid image data. */
  public static function decode(array $command): ?RasterImage {
    $chunks = $command['chunks'] ?? [];
    $encoded = implode('', $chunks);
    if (strlen($encoded) > 90 * 1024 * 1024) {
      return null;
    }
    $data = '';
    foreach ($chunks as $chunk) {
      $decoded = base64_decode($chunk, true);
      if ($decoded === false) {
        $data = false;
        break;
      }
      $data .= $decoded;
    }
    if ($data === false) {
      // Accept a stream split in the middle of a base64 quartet, too.
      $data = base64_decode($encoded, true);
    }
    $transmission = $command['transmission'] ?? 'd';
    if ($data === false && $transmission === 'f') {
      $data = $encoded;
    }
    if ($data === false) {
      return null;
    }
    if (($command['compression'] ?? '') === 'z') {
      $data = @gzuncompress($data) ?: @gzdecode($data);
      if ($data === false) {
        return null;
      }
    }
    try {
      if ($transmission === 'f') {
        return new RasterImage($data);
      }
      if ($transmission !== 'd') {
        return null;
      }
      $format = (int)($command['format'] ?? 32);
      if ($format === 24 || $format === 32) {
        return self::raw($data, (int)($command['width'] ?? 0), (int)($command['height'] ?? 0), intdiv($format, 8));
      }
      if ($format === 100 && str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
        [$width, $height, $pixels] = ImagePixels::fromPNG($data);
        return new RasterImage($width, $height, $pixels);
      }
      if ($format === 100 && extension_loaded('gd')) {
        $gd = @imagecreatefromstring($data);
        if ($gd !== false) {
          try {
            return new RasterImage($gd);
          } finally {
            imagedestroy($gd);
          }
        }
      }
    } catch (\Throwable $error) {
      return null;
    }
    return null;
  }

  /** Crop one placement without changing the stored source image. */
  public static function crop(RasterImage $image, array $command): ?RasterImage {
    $x = max(0, (int)($command['sourceX'] ?? 0));
    $y = max(0, (int)($command['sourceY'] ?? 0));
    $width = min((int)($command['sourceWidth'] ?? $image->width - $x), $image->width - $x);
    $height = min((int)($command['sourceHeight'] ?? $image->height - $y), $image->height - $y);
    if ($width < 1 || $height < 1) {
      return null;
    }
    if ($x === 0 && $y === 0 && $width === $image->width && $height === $image->height) {
      return $image;
    }
    $pixels = '';
    for ($row = 0; $row < $height; $row++) {
      $pixels .= substr($image->pixels, (($y + $row) * $image->width + $x) * 4, $width * 4);
    }
    return new RasterImage($width, $height, $pixels);
  }

  /** Convert raw RGB or RGBA bytes to SPTK's native packed pixel format. */
  private static function raw(string $data, int $width, int $height, int $channels): ?RasterImage {
    if ($width < 1 || $height < 1 || $width * $height > 16 * 1024 * 1024 || strlen($data) < $width * $height * $channels) {
      return null;
    }
    $pixels = '';
    for ($y = 0; $y < $height; $y++) {
      $row = [];
      for ($x = 0; $x < $width; $x++) {
        $offset = ($y * $width + $x) * $channels;
        $row[] = (ord($data[$offset]) << 24) | (ord($data[$offset + 1]) << 16)
          | (ord($data[$offset + 2]) << 8) | ($channels === 4 ? ord($data[$offset + 3]) : 255);
      }
      $pixels .= pack('L*', ...$row);
    }
    return new RasterImage($width, $height, $pixels);
  }

}
