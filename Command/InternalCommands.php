<?php

namespace MADIR\Command;

trait InternalCommands {

  private static $openLoaded = false;
  private static $openRelations = [];

  private function help($command) {
    $argument = trim(substr($command, 4));
    $appDir = dirname(APP_PATH);
    switch ($argument) {
      case "":
        return "\e[1;37mhelp about\e[0m    Short description and Unlicense.\n"
          . "\e[1;37mhelp key\e[0m      Keyboard shortcuts.\n"
          . "\e[1;37mhelp command\e[0m  Shell syntax and internal commands.\n";
      case "about":
        $license = file_get_contents("{$appDir}/UNLICENSE");
        if ($license === false) {
          $license = "The UNLICENSE file could not be read.";
        }
        return "\e_Ga=T,t=f,i=42;" . base64_encode("{$appDir}/Layout/madir.png") . "\e\\"
          . "\n\e[1;37mMaDirector is a terminal emulator with an integrated command shell and\n"
          . "session manager. It supports interactive applications, pipelines,\n"
          . "redirections, and multiple concurrent sessions, helping you keep related\n"
          . "tasks together and easily sitch between them.\e[0m\n\n" . rtrim($license) . "\n";
      case "key":
        return "\e[1;37mKeyboard shortcuts\e[0m\n"
          . "Enter                   Run a command or enter input mode.\n"
          . "Escape                  Leave scroll mode.\n"
          . "Up/Down                 Browse history or commands.\n"
          . "Left/Right              Move within a command group.\n"
          . "Home/End                Select the first or last command.\n"
          . "Page Up/Page Down       Select the previous or next command.\n"
          . "Ctrl+Left/Ctrl+Right    Switch sessions.\n"
          . "Tab                     Complete a command or argument.\n"
          . "Delete                  Stop or remove a command.\n"
          . "Ctrl+C/Ctrl+V/Ctrl+A    Copy, paste, or copy command and output.\n"
          . "I                       Enter input mode.\n"
          . "S or Backspace          Enter or leave scroll mode.\n"
          . "Z                       Toggle zoom.\n"
          . "M                       Send input to all commands in the group.\n"
          . "Double Shift            Toggle input and normal mode.\n"
          . "F/B/N                   Search/previous/next in scroll mode.\n";
      case "command":
        return "\e[1;37mShell syntax\e[0m\n"
          . "cmd1 | cmd2             Pipe commands.\n"
          . "cmd1 && cmd2            Continue after success.\n"
          . "cmd1 || cmd2            Continue after failure.\n"
          . "cmd1 ; cmd2             Run in sequence.\n"
          . "cmd1 & cmd2             Run in parallel.\n"
          . "< > >> 2>              Redirect input or output.\n"
          . "\"text\"                  Quote one argument.\n"
          . "\$name                   Expand a variable.\n\n"
          . "\e[1;37mInternal commands\e[0m\n"
          . "cd       Change or print the working directory.\n"
          . "set      Manage environment variables.\n"
          . "alias    Manage command aliases.\n"
          . "session  Manage sessions. Alias: s.\n"
          . "open     Open files with configured applications.\n"
          . "help     Show MaDirector help.\n"
          . "exit     Close sessions or quit MaDirector.\n"
          . "\$name=x  Set or unset a shell variable.\n"
          . "ID       Switch to a numeric session ID.\n"
          . "Run an internal command without arguments for detailed usage.\n";
      default:
        return "Unknown help topic \"{$argument}\".\nUse \e[1;37mhelp\e[0m to list topics.\n";
    }
  }

  private function changeDir($command) {
    switch ($command) {
      case 'cd':
        $help = "";
        $help .= "\e[1;37m\"cd\" is an internal command used to change the working directory.\e[0m\n";
        $help .= "This command produces no output unless an error occurs or the current directory is requested.\n";
        $help .= "  \e[1;37mcd       \e[0mShow this help message.\n";
        $help .= "  \e[1;37mcd .     \e[0mPrint the current working directory (PWD).\n";
        $help .= "  \e[1;37mcd ..    \e[0mGo up one directory level.\n";
        $help .= "  \e[1;37mcd /     \e[0mGo to the root directory.\n";
        $help .= "  \e[1;37mcd ~     \e[0mGo to the home directory.\n";
        $help .= "  \e[1;37mcd -     \e[0mGo to the previous working directory. (OLDPWD)\n";
        $help .= "  \e[1;37mcd path  \e[0mGo to the specified path; relative elements will be resolved.\n";
        return $help;
      case 'cd .':
        return $this->cwd;
      case 'cd ..':
        $this->pwd = $this->cwd;
        $this->cwd = dirname($this->cwd);
        break;
      case 'cd /':
        $this->pwd = $this->cwd;
        $this->cwd = '/';
        break;
      case 'cd ~':
        $this->pwd = $this->cwd;
        $this->cwd = \SPTK\Config::getHome();
        break;
      case 'cd -':
        list($this->cwd, $this->pwd) = [$this->pwd, $this->cwd];
        break;
      default:
        $path = substr($command, 2);
        $path = trim($path);
        if (substr($path, 0, 2) === './') {
          $path = $this->cwd . substr($path, 1);
        } else if (substr($path, 0, 2) === '~/') {
          $home = \SPTK\Config::getHome();
          $path = $home . substr($path, 1);
        } else if (substr($path, 0, 1) !== '/') {
          $path = rtrim($this->cwd, '/') . '/' . $path;
        }
        $rpath = realpath($path);
        if ($rpath === false) {
          return "{$path}\nNo such file or directory!\n";
        } else {
          $this->pwd = $this->cwd;
          $this->cwd = $rpath;
        }
        break;
    }
    $cmd = end($this->commands);
    $this->setGit();
    $cmd[0]->refreshCommandLine();
    return true;
  }

  private function set($command) {
    if ($command === 'set') {
      $help = "";
      $help .= "\e[1;37m\"set\" is an internal command used to manage environment variables.\e[0m\n";
      $help .= "Variable names and values may contain any character except \"\\0\" and \"=\".\n";
      $help .= "Use quotes (\"\") to preserve leading or trailing whitespace in names and values.\n";
      $help .= "Use the \e[1;37m\"env\"\e[0m command to list all variables.\n";
      $help .= "  \e[1;37mset          \e[0mShow this help message.\n";
      $help .= "  \e[1;37mset X=value  \e[0mAssign \"value\" to variable \"X\".\n";
      $help .= "  \e[1;37mset X        \e[0mShow the value of variable \"X\".\n";
      $help .= "  \e[1;37mset X=       \e[0mUnset variable \"X\".\n";
      return $help;
    }
    $set = trim(substr($command, 3));
    $a = explode('=', $set, 2);
    $name = $a[0];
    $name = $this->resolveQuotation($name);
    if ($name === false) {
      return "Syntax error!\n";
    }
    if (isset($a[1]) && $a[1] !== '') {
      $value = $a[1];
      $value = $this->resolveQuotation($value);
      if ($value === false) {
        return "Syntax error!\n";
      }
    } else {
      if (isset($this->env[$name])) {
        unset($this->env[$name]);
        return "unset \e[1;37mENV[\"{$name}\"]\e[0m\n";
      }
      return "Variable \"{$name}\" not found.\n";
    }
    $warning = '';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
      $warning .= "\e[0;33mWARNING: strange variable name!\e[0m\n";
    }
    $this->env[$name] = $value;
    if (isset($this->vars[$name])) {
      unset($this->vars[$name]);
    }
    return "{$warning}\e[1;37mENV[\"{$name}\"] =\e[0m \"{$value}\"\n";
  }

  private function alias($command) {
    self::getAliasList();
    if ($command === 'alias') {
      $help = "";
      $help .= "\e[1;37m\"alias\" is an internal command used to manage command aliases.\e[0m\n";
      $help .= "Aliases must start with a letter and may contain letters, numbers underscores and dashes.\n";
      $help .= "Use quotes (\"\") to preserve leading or trailing whitespace in values.\n";
      $help .= "  \e[1;37malias             \e[0mShow this help message.\n";
      $help .= "  \e[1;37malias -l          \e[0mList all aliases.\n";
      $help .= "  \e[1;37malias name=value  \e[0mDefine or update an alias.\n";
      $help .= "  \e[1;37malias name=       \e[0mUnset alias \"name\".\n";
      return $help;
    }
    if ($command === 'alias -l') {
      $res = '';
      foreach (self::$alias as $key => $value) {
        $res .= "\e[1;37m{$key} =\e[0m \"{$value}\"\n";
      }
      return $res;
    }
    $alias = trim(substr($command, 5));
    $a = explode('=', $alias, 2);
    $name = $a[0];
    $name = $this->resolveQuotation($name);
    if ($name === false) {
      return "Syntax error!\n";
    }
    if (!preg_match("/^[A-Za-z][A-Za-z0-9_-]*/", $name)) {
      return "Syntax error!\n";
    }
    if (isset($a[1]) && $a[1] !== '') {
      $value = $a[1];
      $value = $this->resolveQuotation($value);
      if ($value === false) {
        return "Syntax error!\n";
      }
    } else {
      if (isset(self::$alias[$name])) {
        unset(self::$alias[$name]);
        if (!self::saveAlias()) {
          return "Could not save alias file.\n";
        }
        return "unset alias \"\e[1;37m{$name}\e[0m\"\n";
      }
      return "Alias \"\e[1;37m{$name}\e0m\" not found.\n";
    }
    self::$alias[$name] = $value;
    if (!self::saveAlias()) {
      return "Could not save alias file.\n";
    }
    return "Alias defined.\n\e[1;37m{$name} =\e[0m \"{$value}\"\n";
  }

  private function session($command) {
    if ($command === 'session') {
      $help = "";
      $help .= "\e[1;37m\"session\" is an internal command used to manage sessions.\e[0m\n";
      $help .= "Each session has an ID and may have a name.\n";
      $help .= "If a session does not exist, it will be created.\n";
      $help .= "The short form of this command is \e[1;37m\"s\"\e[0m. You can also switch sessions by typing the session ID without using the command.\n";
      $help .= "  \e[1;37msession          \e[0mShow this help message.\n";
      $help .= "  \e[1;37msession -l       \e[0mList all sessions.\n";
      $help .= "  \e[1;37msession -n name  \e[0mSet a name for the current session.\n";
      $help .= "  \e[1;37msession ID|name  \e[0mSwitch to the specified session.\n";
      $help .= "  \e[1;37msession -d ID    \e[0mDelete the specified session.\n";
      $help .= "  \e[1;37msession -c       \e[0mClear the session screen (remove all completed commands).\n";
      return $help;
    }
    if ($command === 'session -l') {
      return Session::getSessionListText();
    } else if (strpos($command, 'session -d') === 0) {
      $id = trim(substr($command, 10));
      if ($id === '') {
        return "Specify a sessionId!\n";
      } else {
        $id = (int)$id;
      }
      if (Session::delete($id)) {
        return Session::getSessionListText();
      } else {
        return "You can't delete the last session.\n";
      }
    } else if (strpos($command, 'session -n') === 0) {
      $name = trim(substr($command, 10));
      Session::getCurrent()->setName($name);
      return true;
    } else if (strpos($command, 'session -c') === 0) {
      Session::getCurrent()->clear();
      return true;
    } else {
      $id = trim(substr($command, 7));
      if (!ctype_digit($id)) {
        Session::selectSessionByName($id);
      } else {
        Session::selectSession($id, false);
      }
      return true;
    }
  }

  private function variable($command) {
    $a = explode('=', $command);
    $name = substr($a[0], 1);
    $value = $a[1];
    if ($a[1] === '') {
      unset($this->vars[$name]);
      return "unset variable \e[1;37m\${$name}\e[0m\n";
    } else {
      $value = $this->resolveQuotation($value);
      if ($value === false) {
        return "Syntax error!\n";
      }
    }
    if (isset($this->env[$name])) {
      $this->env[$name] = $value;
      return "\e[1;37mENV[\"{$name}\"] =\e[0m \"{$value}\"\n";
    } else {
      $this->vars[$name] = $value;
      return "\e[1;37m\${$name}\e[0m = \"{$value}\"\n";
    }
  }

  private static function getOpenFilePath(): string {
    return \SPTK\Config::getFilePath('open.json');
  }

  private static function loadOpenRelations(): void {
    if (self::$openLoaded) {
      return;
    }
    self::$openLoaded = true;
    $relations = \SPTK\Config::load(self::getOpenFilePath());
    foreach ($relations as $name => $relation) {
      if (!is_array($relation)) {
        continue;
      }
      if (!is_string($name) || !is_string($relation['regexp'] ?? null) || !is_string($relation['app'] ?? null)) {
        continue;
      }
      self::$openRelations[$name] = [
        'regexp' => $relation['regexp'],
        'app' => $relation['app'],
        'terminal' => !empty($relation['terminal'])
      ];
    }
  }

  private static function saveOpenRelations(): bool {
    self::loadOpenRelations();
    return \SPTK\Config::save(self::getOpenFilePath(), self::$openRelations);
  }

  private function openCommand(array $command) {
    $argv = $command['sequence'][0]['pipeline'][0]['argv'] ?? ['open'];
    if (($argv[0] ?? null) !== 'open') {
      return "Syntax error!\n";
    }
    if (count($argv) === 1) {
      return $this->openHelp();
    }
    if (count($argv) === 2 && $argv[1] === '-l') {
      return $this->openList();
    }
    if (($argv[1] ?? null) === '-d') {
      return $this->openDelete($argv);
    }
    if (in_array('-c', $argv, true) || in_array('-a', $argv, true) || in_array('-n', $argv, true)) {
      return $this->openSaveRelation($argv);
    }
    return $this->openFiles(array_slice($argv, 1));
  }

  private function openHelp(): string {
    $help = "";
    $help .= "\e[1;37m\"open\" opens files with configured applications.\e[0m\n";
    $help .= "Relations are checked in order; the first matching relation is used.\n";
    $help .= "  \e[1;37mopen                 \e[0mShow this help message.\n";
    $help .= "  \e[1;37mopen -l              \e[0mList relations.\n";
    $help .= "  \e[1;37mopen FILE...         \e[0mOpen files. Shell-style globs such as *.txt are expanded.\n";
    $help .= "  \e[1;37mopen -n N -c R -a A  \e[0mCreate or replace a relation.\n";
    $help .= "  \e[1;37mopen -t -n N -c R -a A\e[0mMark the application as a terminal command.\n";
    $help .= "  \e[1;37mopen -d N            \e[0mDelete a relation.\n";
    $help .= "Placeholders: \e[1;37m%f\e[0m expands to all files; \e[1;37m%F\e[0m repeats the containing argument per file.\n";
    return $help;
  }

  private function openList(): string {
    self::loadOpenRelations();
    if (empty(self::$openRelations)) {
      return "No open relations defined.\n";
    }
    $list = '';
    foreach (self::$openRelations as $name => $relation) {
      $type = $relation['terminal'] ? 'terminal' : 'window';
      $list .= "\e[1;37m{$name}\e[0m [{$type}] {$relation['regexp']} -> {$relation['app']}\n";
    }
    return $list;
  }

  private function openDelete(array $argv): string {
    self::loadOpenRelations();
    $name = $argv[2] ?? '';
    if ($name === '') {
      return "Specify a relation name.\n";
    }
    if (!isset(self::$openRelations[$name])) {
      return "Relation \"{$name}\" not found.\n";
    }
    unset(self::$openRelations[$name]);
    if (!self::saveOpenRelations()) {
      return "Could not save open relations.\n";
    }
    return "Deleted open relation \"\e[1;37m{$name}\e[0m\".\n";
  }

  private function openSaveRelation(array $argv): string {
    self::loadOpenRelations();
    $relation = [
      'name' => null,
      'regexp' => null,
      'app' => null,
      'terminal' => false
    ];
    for ($i = 1; $i < count($argv); $i++) {
      switch ($argv[$i]) {
        case '-t':
          $relation['terminal'] = true;
          break;
        case '-n':
        case '-c':
        case '-a':
          $option = $argv[$i];
          $i++;
          if (!isset($argv[$i])) {
            return "Missing value for {$option}.\n";
          }
          if ($option === '-n') {
            $relation['name'] = $argv[$i];
          } else if ($option === '-c') {
            $relation['regexp'] = $argv[$i];
          } else {
            $relation['app'] = $argv[$i];
          }
          break;
        default:
          return "Unknown option \"{$argv[$i]}\".\n";
      }
    }
    if (!is_string($relation['name']) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $relation['name'])) {
      return "Invalid relation name.\n";
    }
    if (!is_string($relation['regexp']) || $this->openRegexpIsValid($relation['regexp']) === false) {
      return "Invalid regexp.\n";
    }
    if (!is_string($relation['app']) || trim($relation['app']) === '') {
      return "Specify an application.\n";
    }
    $name = $relation['name'];
    self::$openRelations[$name] = [
      'regexp' => $relation['regexp'],
      'app' => $relation['app'],
      'terminal' => $relation['terminal']
    ];
    if (!self::saveOpenRelations()) {
      return "Could not save open relations.\n";
    }
    $type = $relation['terminal'] ? 'terminal' : 'window';
    return "Open relation defined.\n\e[1;37m{$name}\e[0m [{$type}] {$relation['regexp']} -> {$relation['app']}\n";
  }

  private function openRegexpIsValid(string $regexp): bool {
    set_error_handler(function() {});
    $result = preg_match($regexp, '');
    restore_error_handler();
    return $result !== false;
  }

  private function openFiles(array $patterns) {
    self::loadOpenRelations();
    if (empty(self::$openRelations)) {
      return "No open relations defined.\n";
    }
    $paths = [];
    $errors = [];
    foreach ($patterns as $pattern) {
      $matches = $this->openResolvePattern($pattern);
      if (empty($matches)) {
        $errors[] = "No matches for {$pattern}.";
        continue;
      }
      foreach ($matches as $path) {
        if (is_file($path)) {
          $paths[$path] = $path;
        } else {
          $errors[] = "{$path}: not a file.";
        }
      }
    }
    $groups = [];
    foreach ($paths as $path) {
      $matched = false;
      foreach (self::$openRelations as $name => $relation) {
        if (preg_match($relation['regexp'], $path)) {
          $groups[$name]['relation'] = $relation;
          $groups[$name]['files'][] = $path;
          $matched = true;
          break;
        }
      }
      if (!$matched) {
        $errors[] = "{$path}: no matching open relation.";
      }
    }
    $terminalCommands = [];
    foreach ($groups as $group) {
      $relation = $group['relation'];
      if ($relation['terminal']) {
        $terminalCommands[] = $this->openExpandTemplate($relation['app'], $group['files'], [$this, 'openQuoteCommandArg']);
      } else {
        $cmd = $this->openExpandTemplate($relation['app'], $group['files'], 'escapeshellarg');
        $this->openDetached($cmd, $errors);
      }
    }
    if (!empty($terminalCommands)) {
      $this->runCommandStrings($terminalCommands);
    }
    if (!empty($errors)) {
      return implode("\n", $errors) . "\n";
    }
    return true;
  }

  private function openResolvePattern(string $pattern): array {
    $path = $this->openExpandPath($pattern);
    if ($this->openHasGlob($path)) {
      $matches = glob($path, GLOB_MARK) ?: [];
      $files = [];
      foreach ($matches as $match) {
        $real = realpath($match);
        if ($real !== false) {
          $files[] = $real;
        }
      }
      sort($files);
      return $files;
    }
    $real = realpath($path);
    return $real === false ? [] : [$real];
  }

  private function openExpandPath(string $path): string {
    if (substr($path, 0, 2) === '~/') {
      return \SPTK\Config::getHome() . substr($path, 1);
    }
    if (substr($path, 0, 1) !== '/') {
      return rtrim($this->cwd, '/') . '/' . $path;
    }
    return $path;
  }

  private function openHasGlob(string $path): bool {
    return strpbrk($path, '*?[') !== false;
  }

  private function openExpandTemplate(string $template, array $files, callable $quote): string {
    $quoted = array_map($quote, $files);
    $args = preg_split('/\s+/', trim($template));
    $expanded = [];
    $hasPlaceholder = strpos($template, '%f') !== false || strpos($template, '%F') !== false;
    foreach ($args as $arg) {
      if ($arg === '') {
        continue;
      }
      if (strpos($arg, '%F') !== false) {
        foreach ($quoted as $file) {
          $expanded[] = str_replace('%F', $file, $arg);
        }
      } else if (strpos($arg, '%f') !== false) {
        if ($arg === '%f') {
          foreach ($quoted as $file) {
            $expanded[] = $file;
          }
        } else {
          $expanded[] = str_replace('%f', implode(' ', $quoted), $arg);
        }
      } else {
        $expanded[] = $arg;
      }
    }
    if (!$hasPlaceholder) {
      foreach ($quoted as $file) {
        $expanded[] = $file;
      }
    }
    return implode(' ', $expanded);
  }

  private function openQuoteCommandArg(string $arg): string {
    return '"' . str_replace(["\\", "\""], ["\\\\", "\\\""], $arg) . '"';
  }

  private function openDetached(string $command, array &$errors): void {
    $shellCommand = 'cd ' . escapeshellarg($this->cwd) . ' && ' . $command . ' >/dev/null 2>&1 &';
    exec('sh -c ' . escapeshellarg($shellCommand), $output, $result);
    if ($result !== 0) {
      $errors[] = "Failed to open with command: {$command}";
    }
  }

  private function exitCommand(array $command) {
    $commandString = trim($command['commandString']);
    $argv = $command['sequence'][0]['pipeline'][0]['argv'] ?? ['exit'];
    if (($argv[0] ?? null) !== 'exit') {
      return "Syntax error!\n";
    }
    $force = false;
    $quiet = false;
    $target = false;
    for ($i = 1; $i < count($argv); $i++) {
      switch ($argv[$i]) {
        case '-a':
          \SPTK\App::$instance->quit();
          return true;
        case '-c':
          $target = $this;
          break;
        case '-f':
          $force = true;
          break;
        case '-s':
          $i++;
          $sessionName = $argv[$i] ?? '';
          if ($sessionName === '') {
            return "Specify a session.\n";
          }
          if (ctype_digit($sessionName)) {
            $target = Session::getById((int)$sessionName);
          } else {
            $target = Session::getByName($sessionName);
          }
          if ($target === false) {
            return "Session not found.\n";
          }
          break;
        default:
          return "Unknown option \"{$argv[$i]}\".\n";
      }
    }
    if ($target === false) {
      $help = "";
      $help .= "\e[1;37m\"exit\" closes a session or quits the application.\e[0m\n";
      $help .= "  \e[1;37mexit             \e[0mShow this help message.\n";
      $help .= "  \e[1;37mexit -c          \e[0mClose the current session if nothing is running.\n";
      $help .= "  \e[1;37mexit -s ID|name  \e[0mCloas a specific session if nothing is running in it.\n";
      $help .= "  \e[1;37mexit -f          \e[0mKill running commands in the target session, then close it.\n";
      $help .= "  \e[1;37mexit -a          \e[0mQuit MaDirector.\n";
      return $help;
    }
    if ($target->hasRunningCommands()) {
      if ($force) {
        $target->terminateRunningCommands();
      } else {
        return "Session has running commands.\nUse \e[1;37-f\e[0m to kill them.\n";
      }
    }
    Session::delete($target->id());
    return true;
  }

  private function resolveQuotation($name) {
    if (substr($name, 0, 1) === '"') {
      $name = substr($name, 1);
      if (substr($name, -1) === '"') {
        $name = substr($name, 0, -1);
      } else {
        return false;
      }
    }
    return $name;
  }

}
