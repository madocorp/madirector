<?php

define('SPTK\DEBUG', true);
define('APP_PATH', __FILE__);

require_once 'Pty/Message.php';
require_once 'Pty/CommanderHandler.php';
require_once 'Pty/Commander.php';
\MADIR\Pty\CommanderHandler::init();

require_once 'SPTK/App.php';
require_once 'Screen/Controller.php';
require_once 'Command/Session.php';
require_once 'Command/Command.php';
require_once 'Command/ScreenBuffer.php';
require_once 'Command/ANSIParser.php';

new \SPTK\App(
  'Layout/madirector.xml',
  'Layout/style.xss',
  ['\MADIR\Screen\Controller', 'init'],
  ['\MADIR\Pty\CommanderHandler', 'getResults'],
  false,
  false
);
