# MaDirector

MaDirector is a terminal emulator with an integrated command shell and session
manager. It runs interactive applications and supports pipelines, redirections,
and multiple concurrent sessions.

## Requirements

- Linux, for its PTY and libc interfaces, and a graphical desktop session.
- PHP 8.2 or newer on the command line, with FFI, PCNTL, POSIX, and mbstring
  enabled. GD is needed for some inline image formats.
- [SPTK](https://github.com/madocorp/SPTK), with SDL3 and SDL3_ttf available
  as described by its installation instructions.

Check PHP with:

```sh
php --version
php -m | grep -Ei '^(FFI|pcntl|posix|mbstring|gd)$'
```

Install missing PHP extensions and SDL libraries with your system's package
manager. Package names vary by system.

## Installation

MaDirector is installed manually; it does not use Composer. The example below
installs both projects for one Linux user:

```sh
mkdir -p "$HOME/.local/share" "$HOME/.local/bin"
git clone https://github.com/madocorp/SPTK.git "$HOME/.local/share/SPTK"
git clone https://github.com/madocorp/madirector.git \
  "$HOME/.local/share/madirector"
ln -sfn "$HOME/.local/share/SPTK" \
  "$HOME/.local/share/madirector/SPTK"
ln -s "$HOME/.local/share/madirector/madirector.php" \
  "$HOME/.local/bin/madirector"
```

The application loads SPTK from a directory or symlink named `SPTK` beside
`madirector.php`. The `ln -sfn` command creates that link for the paths above.
If you install either project elsewhere, change those paths accordingly.
Ensure `~/.local/bin` is in your `PATH`, or run the PHP script by its full path.

The main script is executable in the repository. If a copied installation
loses that permission, restore it with `chmod +x madirector.php` inside the
installation directory.

## Running

```sh
madirector
```

Type a command and press Enter. The prompt supports shell commands, command
history, completion, and MaDirector's internal commands. For the full command
and key reference, enter `help`, `help about`, `help key`, or `help command`.

### Keyboard shortcuts

#### Prompt and navigation

- **Up/Down** recalls prompt history. **Tab** completes a command; when
  multiple matches appear above the prompt, use **Up/Down** to choose one,
  **Left/Right** to change categories, **Enter** to insert it, or **Tab/Esc**
  to close the completion tile.
- **PageUp/PageDown** moves between terminal groups. Arrow keys move among
  terminals within a group. In normal mode, **Ctrl+PageUp/PageDown** moves
  between groups, **Ctrl+Left/Right** changes sessions, and **Home/End** selects
  the first terminal or the prompt.
- Selecting the prompt activates it. After leaving it with **Esc**, press
  **Enter** to edit it again.

#### Terminal modes

- On a selected terminal, **Enter**, **F12**, or **I** enters input mode. A
  finished terminal enters scroll mode instead. **Shift+Enter**, **Shift+F12**,
  or **S** enters scroll mode directly.
- In input mode, **F12** or double **Shift** returns to normal mode, and
  **Shift+F12** enters scroll mode. Every other key goes to the terminal;
  **Ctrl+V** pastes clipboard text into it.
- In scroll mode, arrow keys move the text cursor, **Home/End** moves along a
  line, **PageUp/PageDown** moves by page, and **Ctrl+PageUp/PageDown** jumps to
  the start or end of output. Hold **Shift** while moving to select text;
  **Ctrl+A** selects all, and **Ctrl+C** or **Ctrl+Insert** copies the selection.
  The cursor and selection use a yellow background.
- In scroll mode, **S** or **Esc** returns to normal mode. **Enter** returns to
  input mode for a running command, or normal mode for a finished one. **F12**
  or double **Shift** returns to input mode for a running command.
- **Z** or **F11** zooms into input mode; **Shift+Z** or **Shift+F11** zooms into
  scroll mode. Leaving zoom for normal mode restores the terminal layout.

#### Terminal actions

- In normal mode, **G** sends input to every running terminal in the selected
  group. **F12** leaves shared input mode.
- **Delete** opens the signal tile for a selected running terminal;
  **Shift+Delete** targets its group. Use **Up/Down** to choose a signal,
  **Left/Right** to change categories, **Enter** to send it, or **Esc** to close
  the tile.
- **F** in normal or scroll mode searches the selected terminal's output. The
  search field appears in its title. **Enter** shows the first match;
  **P/N** moves to the previous or next match in scroll mode. **Esc** cancels
  search.
- **X** on a finished terminal shows `Delete terminal? [Y/N]` in its title.
  **Y** or **Enter** confirms; **N** or **Esc** cancels.

### Terminal layout and images

New command groups appear above the prompt. A normal command uses one tile;
parallel commands joined with `&` share a grid. Tiles grow as output arrives,
and groups can be partially visible when the stack exceeds the window.

Kitty inline images appear in terminal output and move with scrollback. To
check image support, start `bash` in MaDirector and run:

```sh
timg -pk -g64x24 "$HOME/.local/share/madirector/SPTK/Demo/Assets/test.jpg"
```

The `-pk` option selects Kitty graphics. Change the image path if you installed
MaDirector elsewhere. The bundled test image should appear wider than tall.
