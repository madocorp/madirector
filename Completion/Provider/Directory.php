<?php

namespace MADIR\Completion\Provider;

class Directory implements \MADIR\Completion\Provider {

  protected $dir;
  protected $replaceDir;
  protected $prefix;

  public function getCandidates(array $argv, \MADIR\Command\Session $session): array {
    $candidates = [];
    $partialPath = end($argv);
    $this->parsePath($partialPath, $session);
    if (!is_dir($this->dir)) {
      return [];
    }
    $files = scandir($this->dir);
    foreach ($files as $file) {
      $path = realpath($this->dir . '/' . $file);
      if ($file === '.' || $file === '..') {
        continue;
      }
      if (!is_dir($path)) {
        continue;
      }
      if ($this->prefix === '' ||  strpos($file, $this->prefix) === 0) {
        $candidates[] = $this->replaceDir . '/' . $file . '/';
      }
    }
    sort($candidates);
    return $candidates;
  }

  protected function parsePath(string $partialPath, \MADIR\Command\Session $session): void {
    $parts = explode('/', $partialPath);
    $this->prefix = array_pop($parts);
    $this->replaceDir = rtrim(implode('/', $parts), '/');
    if (isset($parts[0])) {
      if ($parts[0] === '.') {
        $parts[0] = $session->cwd();
      }
      if ($parts[0] === '..') {
        $parts[0] = dirname($session->cwd());
      }
      if ($parts[0] === '~') {
        $parts[0] = \SPTK\Config::getHome();
      }
    }
    if ($parts === ['']) {
      $this->dir = '/';
    } else if (empty($parts)) {
      $this->replaceDir = '.';
      $this->dir = $session->cwd();
    } else {
      $this->dir = implode('/', $parts);
    }
  }

}
