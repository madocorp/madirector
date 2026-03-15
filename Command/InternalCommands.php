<?php

namespace MADIR\Command;

trait InternalCommands {

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
    $cmd->refreshCommandLine();
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
    $this->vars[$name] = $value;
    return "\e[1;37m\${$name}\e[0m = \"{$value}\"\n";
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
