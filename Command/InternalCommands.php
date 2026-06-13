<?php

namespace MADIR\Command;

trait InternalCommands {

  private function help($command) {
    $argument = trim(substr($command, 4));
    switch ($argument) {
      case "":
        return "\e[1;37mhelp about\e[0m    Short description and Unlicense.\n"
          . "\e[1;37mhelp key\e[0m      Keyboard shortcuts.\n"
          . "\e[1;37mhelp command\e[0m  Shell syntax and internal commands.\n";
      case "about":
        $appDir = dirname(APP_PATH);
        $license = file_get_contents("{$appDir}/UNLICENSE");
        if ($license === false) {
          $license = "The UNLICENSE file could not be read.";
        }
        return "\e[1;37mMaDirector is a terminal emulator with an integrated command shell and\n"
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
        return "unset alias \"\e[1;37m{$name}\e[0m\"\n";
      }
      return "Alias \"\e[1;37m{$name}\e0m\" not found.\n";
    }
    self::$alias[$name] = $value;
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
