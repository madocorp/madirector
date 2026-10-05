<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADIR');

require APP_DIR . '/SPTK/App.php';

use MADIR\App\RunningCommand;
use MADIR\App\TerminalImages;
use MADIR\Screen\{Picture, ScreenBuffer};

function checkImage(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$command = new RunningCommand(['commandString' => 'image', 'sequence' => []], '/tmp', []);
$raw = "\xff\x00\x00\xff\x00\xff\x00\x80";
$encoded = base64_encode($raw);
$command->output("\e_Ga=T,i=42,f=32,s=2,v=1,c=2,r=1,m=1;" . substr($encoded, 0, 5) . "\e\\");
checkImage($command->images()->placements() === [], 'unfinished image must stay hidden');
$command->output("\e_Gm=0;" . substr($encoded, 5) . "\e\\");
$placements = $command->images()->placements();
checkImage(count($placements) === 1, 'chunked image should auto-place once');
checkImage($placements[0]['raster']->width === 2 && $placements[0]['raster']->height === 1, 'raw image dimensions');
checkImage($placements[0]['raster']->pixels === pack('L*', 0xff0000ff, 0x00ff0080), 'raw pixel colors and alpha');
checkImage($command->buffer()->getCursorPosition() === [1, 2], 'placement advances the cursor');

$paddedChunks = new RunningCommand(['commandString' => 'padded image', 'sequence' => []], '/tmp', []);
$paddedChunks->output("\e_Ga=T,f=32,s=1,v=1,m=1;" . base64_encode("\xff") . "\e\\");
$paddedChunks->output("\e_Gm=0;" . base64_encode("\x00\x00\xff") . "\e\\");
checkImage(count($paddedChunks->images()->placements()) === 1, 'separately padded Kitty chunks decode');
checkImage($paddedChunks->images()->placements()[0]['raster']->pixels === pack('L', 0xff0000ff), 'padded chunks preserve pixel bytes');

$command->output("\e_Ga=p,i=42,p=7,x=1,y=0,w=1,h=1,c=1,r=3,C=1\e\\");
$placements = $command->images()->placements();
checkImage(count($placements) === 2, 'explicit placement reuses the image');
checkImage($placements[1]['raster']->pixels === pack('L', 0x00ff0080), 'source rectangle crops the image');
checkImage($command->outputRows() >= 4, 'stationary image expands the visible terminal');
$command->output("\e_Ga=d,i=42,p=7\e\\");
checkImage(count($command->images()->placements()) === 1, 'placement deletion preserves the source image');

$other = new RunningCommand(['commandString' => 'other', 'sequence' => []], '/tmp', []);
checkImage($other->images()->placements() === [], 'image state stays with its command');
$command->output("\e_Ga=d,d=a\e\\");
checkImage($command->images()->placements() === [], 'delete all clears the terminal images');

$buffer = new ScreenBuffer(4, 8);
$responses = [];
$images = new TerminalImages($buffer, static function(string $stream) use (&$responses): void {
  $responses[] = $stream;
});
Picture::parseAnsi('a=q,i=5,f=32', $images);
checkImage($responses === ["\e_Gi=5;OK\e\\"], 'graphics capability query response');

$gd = imagecreatetruecolor(1, 1);
imagesetpixel($gd, 0, 0, imagecolorallocate($gd, 12, 34, 56));
ob_start();
imagepng($gd);
$png = ob_get_clean();
imagedestroy($gd);
$other->output("\e_Ga=T,f=100;" . base64_encode($png) . "\e\\");
checkImage(count($other->images()->placements()) === 1, 'PNG image should decode and place');
$other->output("\e_Ga=T,f=32,s=2,v=1,o=z;" . base64_encode(gzcompress($raw)) . "\e\\");
checkImage(count($other->images()->placements()) === 2, 'compressed raw image should decode');
$path = tempnam(sys_get_temp_dir(), 'madirector-image-');
file_put_contents($path, $png);
try {
  $other->output("\e_Ga=T,t=f,f=100;" . base64_encode($path) . "\e\\");
  checkImage(count($other->images()->placements()) === 3, 'file-backed image should decode');
} finally {
  unlink($path);
}

echo "Terminal image checks passed\n";
