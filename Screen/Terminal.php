<?php

namespace MADIR\Screen;

use \SPTK\Element;
use \SPTK\Font;
use \SPTK\Texture;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\SDL;
use \SPTK\SDLWrapper\TTF;

class Terminal extends Element {

  const GLYPH_MAP_SIZE = 64;

  private static $fgColor = false;
  private static $bgColor = false;
  private static $sdlRect = false;
  private static $sdlRectAddr = false;
  private static $sdlFRect1 = false;
  private static $sdlFRect1Addr = false;
  private static $sdlFRect2 = false;
  private static $sdlFRect2Addr = false;
  private static $glyphCache = [];
  private static $nextGlyph = 0;
  private static $atlas = false;

  protected $buffer;
  protected $font;
  protected $letterWidth;
  protected $linHeight;
  protected $inputCallback;
  protected $inputGrab = false;

  public function init() {
    $this->acceptInput = true;
    $this->addEvent('KeyPress', [$this, 'keyPressHandler']);
    $this->addEvent('TextInput', [$this, 'textInputHandler']);
    $ttf = TTF::$instance->ttf;
    if (self::$fgColor === false) {
      self::$fgColor = $ttf->new("SDL_Color");
    }
    if (self::$bgColor === false) {
      self::$bgColor = $ttf->new("SDL_Color");
    }
    $sdl = SDL::$instance->sdl;
    if (self::$sdlRect === false) {
      self::$sdlRect = $sdl->new('SDL_Rect');
      self::$sdlRectAddr = \FFI::addr(self::$sdlRect);
      self::$sdlFRect1 = $sdl->new('SDL_FRect');
      self::$sdlFRect1Addr = \FFI::addr(self::$sdlFRect1);
      self::$sdlFRect2 = $sdl->new('SDL_FRect');
      self::$sdlFRect2Addr = \FFI::addr(self::$sdlFRect2);
    }
    $fontSize = $this->style->get('fontSize');
    $fontName = $this->style->get('font');
    $this->font = new Font($fontName, $fontSize);
    $this->letterWidth = $this->font->letterWidth;
    $this->lineHeight = $this->font->height;
    if (self::$atlas === false) {
      $aw = ($this->letterWidth + 2) * self::GLYPH_MAP_SIZE;
      $ah = ($this->lineHeight + 2) * self::GLYPH_MAP_SIZE;
      self::$atlas = $sdl->SDL_CreateTexture(
        $this->renderer,
        SDL::SDL_PIXELFORMAT_RGBA8888,
        SDL::SDL_TEXTUREACCESS_STATIC,
        $aw,
        $ah
      );
      $zeroPixels = \FFI::new("uint8_t[" . ($aw * $ah * 4) . "]"); // FFI::new zero-initializes memory
      $sdl->SDL_UpdateTexture(self::$atlas, null, $zeroPixels, $aw * 4);
      $sdl->SDL_SetTextureBlendMode(self::$atlas, SDL::SDL_BLENDMODE_BLEND);
      $sdl->SDL_SetTextureScaleMode(self::$atlas, SDL::SDL_SCALE_MODE_NEAREST);
    }
  }

  public function setBuffer($buffer) {
    $this->buffer = $buffer;
  }

  public function setInputCallback($callback) {
    $this->inputCallback = $callback;
  }

  public function grabInput() {
    $this->inputGrab = true;
  }

  public function releaseInput() {
    $this->inputGrab = false;
  }

  protected function calculateHeights() {
    $rows = $this->buffer->countLines();
    $h = $rows * $this->lineHeight;
    $this->geometry->height = $this->geometry->borderTop + $this->geometry->paddingTop + $h + $this->geometry->paddingBottom + $this->geometry->borderBottom;
    $this->geometry->setDerivedHeights();
    $this->geometry->setContentHeight($this->lineHeight, $this->geometry->borderTop + $this->geometry->paddingTop + $h);
  }

  protected function draw() {
    $sdl = SDL::$instance->sdl;
    $this->texture = new Texture($this->renderer, $this->geometry->width, $this->geometry->height, [0, 0, 0, 0xff]);
    $lines = $this->buffer->getLines();
    $cursor = $this->buffer->getCursor();
    $gw = $this->letterWidth;
    $gh = $this->lineHeight;
    foreach ($lines as $i => $row) {
      foreach ($row as $j => $cell) {
        $glyph = $cell[ScreenBuffer::GLYPH];
        if ($cursor !== false && $i == $cursor[0] && $j == $cursor[1]) {
          $fgcolor = $cell[ScreenBuffer::BG];
          $bgcolor = $cell[ScreenBuffer::FG];
        } else {
          $fgcolor = $cell[ScreenBuffer::FG];
          $bgcolor = $cell[ScreenBuffer::BG];
        }
        self::$sdlFRect2->x = (float)($j * $gw + $this->geometry->paddingLeft + $this->geometry->borderLeft);
        self::$sdlFRect2->y = (float)($i * $gh + $this->geometry->paddingTop + $this->geometry->borderTop);
        self::$sdlFRect2->w = (float)$gw;
        self::$sdlFRect2->h = (float)$gh;
        // BG
        $r = ($bgcolor >> 16) & 0xff;
        $g = ($bgcolor >> 8) & 0xff;
        $b = $bgcolor & 0xff;
        $a = 0xff;
        $sdl->SDL_SetRenderDrawColor($this->renderer, $r, $g, $b, $a);
        $sdl->SDL_RenderFillRect($this->renderer, self::$sdlFRect2Addr);
        // FG
        if ($glyph === ' ') {
          continue;
        }
        $r = ($fgcolor >> 16) & 0xff;
        $g = ($fgcolor >> 8) & 0xff;
        $b = $fgcolor & 0xff;
        $a = 0xff;
        $sdl->SDL_SetTextureColorMod(self::$atlas, $r, $g, $b);
        $sdl->SDL_SetTextureAlphaMod(self::$atlas, $a);
        if (!isset(self::$glyphCache[$glyph])) {
          $this->renderGlyph($glyph);
        }
        $glyphMap = self::$glyphCache[$glyph];
        self::$sdlFRect1->x = (float)$glyphMap[0];
        self::$sdlFRect1->y = (float)$glyphMap[1];
        self::$sdlFRect1->w = (float)$gw;
        self::$sdlFRect1->h = (float)$gh;
        $sdl->SDL_RenderTexture($this->renderer, self::$atlas, self::$sdlFRect1Addr, self::$sdlFRect2Addr);
      }
    }
  }

  protected function renderGlyph($glyph) {
    $ttf = TTF::$instance->ttf;
    $sdl = SDL::$instance->sdl;
    self::$fgColor->r = 0xff;
    self::$fgColor->g = 0xff;
    self::$fgColor->b = 0xff;
    self::$fgColor->a = 0xff;
    $surface = $ttf->TTF_RenderText_Blended($this->font->font, $glyph, strlen($glyph), self::$fgColor);
    $surface2 = \FFI::cast($sdl->type("SDL_Surface*"), $surface);
    $srcSurface = $sdl->SDL_ConvertSurface($surface2, SDL::SDL_PIXELFORMAT_RGBA8888);
    $index = self::$nextGlyph;
    self::$nextGlyph++;
    $gw = $this->letterWidth;
    $gh = $this->lineHeight;
    $y = (int)($index / self::GLYPH_MAP_SIZE);
    $x = $index % self::GLYPH_MAP_SIZE;
    $ox = 0;
    $oy = 0;
    if ($surface->w != $this->letterWidth || $surface->h != $this->lineHeight) {
      $glyphMetrics = $this->font->glyphMetrics($glyph);
      if ($surface->w != $this->letterWidth) {
        if ($glyphMetrics[0] < 0) {
          $ox = -$glyphMetrics[0];
        }
      }
      if ($surface->h != $this->lineHeight) {
        if ($glyphMetrics[3] > $this->font->ascent) {
          $oy = $glyphMetrics[3] - $this->font->ascent;
        }
      }
    }
    self::$sdlRect->x = 1 + $x * ($gw + 2) - $ox;
    self::$sdlRect->y = 1 + $y * ($gh + 2) - $oy;
    self::$sdlRect->w = $surface->w;
    self::$sdlRect->h = $surface->h;
    $sdl->SDL_UpdateTexture(self::$atlas, self::$sdlRectAddr, $srcSurface->pixels, $srcSurface->pitch);
    self::$glyphCache[$glyph] = [1 + $x * ($gw + 2), 1 + $y * ($gh + 2)];
    $ttf->SDL_DestroySurface($surface);
    $sdl->SDL_DestroySurface($surface2);
    $sdl->SDL_DestroySurface($srcSurface);
    // DEBUG:6 echo "New glyph on the atlas: {$glyph} [{$x}, {$y}]\n";
  }

  protected function render() {
    if ($this->display === false) {
      return false;
    }
    if ($this->texture === false) {
      return false;
    }
    new \SPTK\Border($this->texture, $this->geometry, $this->ancestor->geometry, $this->style);
    if ($this->style->get('scrollable')) {
      new Scrollbar($this->texture, $this->scrollX, $this->scrollY, $this->geometry->contentWidth, $this->geometry->contentHeight, $this->geometry, $this->style);
    }
    return $this->texture;
  }

  public function keyPressHandler($element, $event) {
    $keycombo = KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    if ($keycombo === KeyCode::F12) {
      return false;
    }
    if ($this->inputGrab) {
      $stream = InputTranslator::translate(
        $event['key'],
        $event['mod'],
        $this->buffer->getApplicationCursorState(),
        $this->buffer->getApplicationKeypadState()
      );
      if ($stream !== null) {
        // DEBUG:8 $a = str_split($stream);
        // DEBUG:8 echo "INPUT: ";
        // DEBUG:8 foreach ($a as $c) {
        // DEBUG:8   echo '0x', dechex(ord($c)), ' ';
        // DEBUG:8 }
        // DEBUG:8 echo "\n";
        call_user_func($this->inputCallback, $stream);
      }
      return true;
    }
    switch ($keycombo) {
      /* SPACE */
      case Action::SELECT_ITEM:
        return true;
      /* MOVE and SELECT*/
      /* COPY */
      case Action::COPY:
        Clipboard::set($this->cursor->getSelection());
        $this->cursor->resetSelection();
        break;
      case Action::PASTE:
        $paste = Clipboard::get();
        if ($paste !== false) {
          call_user_func($this->inputCallback, $paster);
        }
        break;
      default:
        return false;
    }
    return true;
  }

  public function textInputHandler($element, $event) {
    if (!$this->inputGrab) {
      return false;
    }
    call_user_func($this->inputCallback, $event['text']);
    return true;
  }

}

