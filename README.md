# MaDirector

MaDirector is a terminal emulator with an integrated command shell and session
manager. It supports interactive applications, pipelines, redirections, and
multiple concurrent sessions, helping you keep related tasks together and
easily switch between them.

## Requirements

### Linux

MaDirector currently uses Linux PTY and libc interfaces.

### PHP (CLI)

PHP 8 must be installed and available in your `PATH`. The `FFI`, `pcntl`, and
`posix` extensions must be enabled. Use your system's package manager to
install them.

Check with:

```sh
php --version
php -m | grep -E '^(FFI|pcntl|posix)$'
```

### SPTK

[SPTK](https://github.com/madocorp/sptk), the SDL-based PHP Toolkit, is
required.

Follow the installation instructions in that project.

You will need the **SPTK directory path** during installation.

## Installation

This is a **manual installation** (no packages).

### 1. Download the Source Code

Clone the repository from GitHub:

```
git clone https://github.com/madocorp/madirector.git
cd madirector
```

### 2. Choose Installation Location

You can put it anywhere, but here are some common locations:

```
~/.local/share/madirector (linux, user-level)
/opt/madirector (linux, system-wide)
```

The chosen one will be referred to as `INSTALL_DIR` below.

Use `sudo` for system-wide installations.

### 3. Move Files to the Installation Directory

Make sure you are still in the repository folder.

```
mkdir -p INSTALL_DIR
mv * INSTALL_DIR
```

### 4. Configure SPTK Symlink

The application expects SPTK to be available via a symlink from its directory.

```
cd INSTALL_DIR
ln -s /path/to/sptk SPTK
```

Replace /path/to/sptk with the actual SPTK installation directory.

### 5. Make the Main Script Executable

```
cd INSTALL_DIR
chmod +x madirector.php
```

### 6. Create a Symlink in bin

This allows running the program like a normal command.

BIN_DIR is usually one of these:

```
~/.local/bin
~/bin
/usr/local/bin
```

```
ln -s INSTALL_DIR/madirector.php BIN_DIR/madirector
```

Ensure that BIN_DIR is in your PATH.

### 7. Running the program

Run from anywhere by typing its name.

```
madirector
```

## Usage

Type a command and press `Enter`. MaDirector can run ordinary executable
commands and provides its own shell syntax, internal commands, completion,
command history, and session management.

Use the built-in help for the current command and keyboard reference:

```text
help
help about
help key
help command
```

## License

MaDirector is released into the public domain under the
[Unlicense](UNLICENSE).

