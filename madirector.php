#!/usr/bin/env php
<?php

define('APP_DIR', __DIR__);

require_once APP_DIR . '/SPTK/App.php';

use MADIR\Pty\CommanderHandler;

/** Load MaDirector's own parser, process, and UI classes. */
function loadMadirector(string $class): void {
  if (!str_starts_with($class, 'MADIR\\')) {
    return;
  }
  require_once APP_DIR . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
}

spl_autoload_register('loadMadirector');

CommanderHandler::init();

new \SPTK\App();
