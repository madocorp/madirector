<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\App\{RunningCommand, TerminalTile};
use SPTK\Core\Color;
use SPTK\Layout\{LayoutLeaf, Tile, WindowGeometry};
use SPTK\Rendering\{Font, Grid, GridRenderer, GridWriter, PixelRenderer};
use SPTK\SDLWrapper\{SDL, TTF};

function pixel(SDL $sdl, \FFI\CData $renderer, int $x, int $y): int {
  $surface = $sdl->ffi->SDL_RenderReadPixels($renderer, null);
  $rgba = $sdl->ffi->SDL_ConvertSurface($surface, SDL::SDL_PIXELFORMAT_RGBA8888);
  try {
    return \FFI::cast('uint32_t*', $rgba->pixels)[$y * intdiv($rgba->pitch, 4) + $x];
  } finally {
    $sdl->ffi->SDL_DestroySurface($rgba);
    $sdl->ffi->SDL_DestroySurface($surface);
  }
}

function checkPixel(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
checkPixel($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL initialization');
$ttf = new TTF();
checkPixel($ttf->ffi->TTF_Init(), 'TTF initialization');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(SPTK\App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(SPTK\App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(SPTK\App::class, 'font'))->setValue($app, $font);
$window = $sdl->ffi->SDL_CreateWindow('Terminal image test', 320, 160, SDL::SDL_WINDOW_HIDDEN);
$native = $sdl->ffi->SDL_CreateRenderer($window, null);
try {
  $pixels = new PixelRenderer($native);
  $gridRenderer = new GridRenderer($native, $pixels);
  $command = new RunningCommand(['commandString' => 'image', 'sequence' => []], '/tmp', []);
  $command->output("X\r\e_Ga=T,f=32,s=1,v=1,c=1,r=1,C=1;" . base64_encode("\xff\x00\x00\xff") . "\e\\");
  $tile = new TerminalTile($command);
  $cw = $font->cellWidth();
  $ch = $font->cellHeight();
  $grid = new Grid(10, 4);
  $tile->paint(new GridWriter($grid, new Tile(0, 0, 10, 4)));
  $pixels->fill(new Tile(0, 0, 10 * $cw, 4 * $ch), new Color(0, 0, 0));
  $gridRenderer->drawTile($native, $grid, new Tile(0, 0, 10, 4));
  $leaf = new LayoutLeaf('Terminal', '1*', 'auto', $tile);
  $leaf->setGrid(new Tile(0, 0, 10, 4));
  $geometry = new WindowGeometry($cw, $ch, 320, 160, 0, 0);
  $leaf->setViewport(new Tile(0, 2, 10, 2));
  $leaf->measureArea(new Tile(0, 0, 10, 4), $geometry);
  $leaf->paintPixels($pixels, $geometry, true);
  checkPixel(pixel($sdl, $native, intdiv($cw, 2), $ch + intdiv($ch, 2)) !== 0xff0000ff, 'overflow clip excludes hidden image');
  $leaf->setViewport(new Tile(0, 1, 10, 1));
  $leaf->measureArea(new Tile(0, 0, 10, 4), $geometry);
  $leaf->paintPixels($pixels, $geometry, true);
  checkPixel(pixel($sdl, $native, intdiv($cw, 2), $ch + intdiv($ch, 2)) === 0xff0000ff, 'inline image paints below the title');
  $gridRenderer->drawGlyphs($native, $grid, new Tile(0, 0, 10, 4));
  $glyphVisible = false;
  for ($y = $ch; $y < 2 * $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
      if (pixel($sdl, $native, $x, $y) !== 0xff0000ff) {
        $glyphVisible = true;
        break 2;
      }
    }
  }
  checkPixel($glyphVisible, 'terminal glyph appears above the image');

  $wideCommand = new RunningCommand(['commandString' => 'wide image', 'sequence' => []], '/tmp', []);
  $wideCommand->output("\e_Ga=T,f=32,s=4,v=3,c=8,r=3,C=1;" . base64_encode(str_repeat("\xff\x00\x00\xff", 12)) . "\e\\");
  $wideTile = new TerminalTile($wideCommand);
  $wideGrid = new Grid(10, 4);
  $wideTile->paint(new GridWriter($wideGrid, new Tile(0, 0, 10, 4)));
  $pixels->fill(new Tile(0, 0, 10 * $cw, 5 * $ch), new Color(0, 0, 0));
  $wideLeaf = new LayoutLeaf('Wide terminal', '1*', 'auto', $wideTile);
  $wideLeaf->setGrid(new Tile(0, 0, 10, 4));
  $wideLeaf->measureArea(new Tile(0, 0, 10, 4), $geometry);
  $wideLeaf->paintPixels($pixels, $geometry, true);
  checkPixel(pixel($sdl, $native, 8 * $cw - 1, 2 * $ch) === 0xff0000ff, 'image reaches requested cell width');
  checkPixel(pixel($sdl, $native, 8 * $cw + 1, 2 * $ch) !== 0xff0000ff, 'image does not exceed requested cell width');
  checkPixel(pixel($sdl, $native, 4 * $cw, 4 * $ch - 1) === 0xff0000ff, 'image reaches requested cell height');
  checkPixel(pixel($sdl, $native, 4 * $cw, 4 * $ch + 1) !== 0xff0000ff, 'image does not exceed requested cell height');
  $gridRenderer->close();
  $pixels->close();
} finally {
  $sdl->ffi->SDL_DestroyRenderer($native);
  $sdl->ffi->SDL_DestroyWindow($window);
  $font->close();
  $ttf->close();
  $sdl->close();
}

echo "Terminal image rendering checks passed\n";
