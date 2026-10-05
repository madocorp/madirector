# MaDirector

MaDirector is a terminal emulator with an integrated command shell and session
manager. It supports interactive applications, pipelines, redirections, and
multiple concurrent sessions, helping you keep related tasks together and
easily switch between them.

## Requirements

### Linux

MaDirector currently uses Linux PTY and libc interfaces.

### PHP (CLI)

PHP 8.2 or newer must be installed and available in your `PATH`. The `FFI`, `pcntl`, and
`posix` extensions must be enabled. Use your system's package manager to
install them.

Check with:

```sh
php --version
php -m | grep -E '^(FFI|pcntl|posix)$'
```

### SPTK

[SPTK](https://github.com/madocorp/sptk), the SDL3-based PHP Toolkit, is
required. Use the current tile-based SPTK checkout rather than an ancestor version.

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
commands and provides its own shell syntax, internal commands, command history,
and session management.

In the current SPTK interface, use Up/Down while editing the prompt for history.
Press Tab for completion. One match is inserted at the caret; multiple matches
appear in a tile above the CLI. Use Up/Down to choose a candidate, Left/Right to
change categories, Enter to insert it, or Tab/Esc to close the tile.
Press PageUp from the CLI to select the group above it. PageUp/PageDown move
between command groups; arrow keys move among terminals within a group and cross
to neighboring groups at an edge. The working directory appears as the prompt's label.
In normal mode, Ctrl+Left/Right switches between existing sessions, and Home/End
selects the first terminal or the CLI. Press X on a finished terminal to request
removal; the right side of its title shows `Delete terminal? [Y/N]`. Y or Enter
confirms; N or Esc cancels.
A tile that is already fully visible does
not move the stack; a clipped tile scrolls to the edge reached by the arrow key.
Moving to the prompt activates it automatically. The prompt also becomes active
when the last running command finishes. After pressing Esc while the prompt
stays selected, press Enter to edit it again. Ctrl+PageUp/PageDown moves between
groups in normal mode. On a selected terminal, Enter, F12, or I enters input mode. Only running
commands accept input; these keys switch a completed command to scroll mode.
Press G in normal mode to send input to every running terminal in the selected
group. All group titles highlight while shared input is active. F12 leaves
shared input mode; ordinary input mode sends keys only to the selected terminal.
In input mode, only F12, Shift+F12, or double Shift changes mode; other keys go
to the terminal.
Ctrl+V pastes clipboard text into the active terminal.
In normal mode, Delete opens a signal tile for the selected running terminal;
Shift+Delete targets its group. Four columns show signal categories, with a
shared description below. Use Up/Down to choose a signal, Left/Right to change
categories, Enter to send it and close the tile, or Esc to close without sending.
Shift+Enter, Shift+F12, or S enters scroll mode. Scroll mode uses a text cursor:
arrow keys move it, Home/End move along a line, and PageUp/PageDown move by page.
Hold Shift while moving to select text. Ctrl+A selects all, and Ctrl+C or
Ctrl+Insert copies the selection. Ctrl+PageUp/PageDown moves to the start/end
of the output. The cursor and selection have a yellow background.
Esc or S returns to normal mode. Enter
returns to input mode when the command is running, or normal mode
when it has finished. F12 or double Shift returns to input mode when the command
is running. In input mode, F12 or double Shift returns to normal mode, while
Shift+F12 enters scroll mode. Z or F11 zooms into input mode, and Shift+Z or
Shift+F11 zooms into scroll mode. Leaving zoom for normal mode restores the
terminal layout. Zoom uses a black background.
Press F on a selected terminal in normal or scroll mode to enter scroll mode and search its output.
The search field appears on the right side of the title while output stays visible. Enter shows the first
match in scroll mode; P/N moves to the previous/next match. Without an active
search, P/N does nothing. Esc cancels search.
New terminal groups appear directly above the prompt, with unused space above
the oldest group. A normal command uses one tile, capped at two thirds of the
screen height. Parallel commands joined with `&` share a grid: up to four tiles
use two columns, up to nine use three, and larger groups use four. Cell height
is sized so a full 2×2, 3×3, or 4×4 grid fits above the prompt. Groups can be
partially visible when the stack exceeds the window. A terminal starts with one empty visible
output row and reveals more rows as lines arrive, up to the space available
above the prompt. The PTY keeps its full row count; alternate-screen programs
show the full terminal height.
Inline terminal images are still being migrated.

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
