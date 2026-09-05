#!/usr/bin/env php
<?php

define('SPTK\DEBUG', false);
define('APP_PATH', __FILE__);
define('APP_NAMESPACE', 'MADIR');

require_once 'SPTK/Autoload.php';

use MADIR\App\Shell;
use MADIR\Pty\CommanderHandler;
use SPTK\Runtime\SdlApp;
use SPTK\Runtime\SdlWindowOptions;

CommanderHandler::init();

$app = new SdlApp('MADIR');
$fontSize = 16;
$shell = new Shell($fontSize, fn() => $app->quit());
CommanderHandler::setPeriodicRefreshCallback(fn() => $shell->poll());
$app->addTimer(20, fn() => CommanderHandler::loop());
$app->addWindow($shell->build(), new SdlWindowOptions(
  title: 'MaDirector',
  columns: 128,
  rows: 40,
  fontSize: $fontSize
));
$app->run();
