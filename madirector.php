<?php

define('SPTK\DEBUG', true);
define('APP_PATH', __FILE__);
define('APP_NAMESPACE', 'MADIR');

require_once 'SPTK/Autoload.php';

\MADIR\Pty\CommanderHandler::init();

new \SPTK\App(
  'Layout/madirector.xml',
  'Layout/style.xss',
  ['\MADIR\Screen\Controller', 'init'],
  ['\MADIR\Pty\CommanderHandler', 'getResults'],
  false,
  false
);
