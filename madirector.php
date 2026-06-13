#!/usr/bin/env php
<?php

define('SPTK\DEBUG', true);
define('APP_PATH', __FILE__);
define('APP_NAMESPACE', 'MADIR');

require_once 'SPTK/Autoload.php';

\MADIR\Pty\CommanderHandler::init();

new \SPTK\App(
  'Layout/madirector.xml',
  'Layout/style.xss',
  null,
  ['\MADIR\Pty\CommanderHandler', 'loop'],
  null,
  null
);
