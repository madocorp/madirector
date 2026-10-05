#!/usr/bin/env php
<?php

define('APP_DIR', __DIR__);
define('APP_NAMESPACE', 'MADIR');

require_once APP_DIR . '/SPTK/App.php';

use MADIR\Pty\CommanderHandler;

CommanderHandler::init();

new \SPTK\App();
