# madirector

madirector is a terminal emulator with tiled panes and numbered screens. Each
pane runs a normal interactive shell in a PTY. Shell history, completion,
commands, and job control come from that shell; madirector manages where the
terminals appear and which one receives input. Programs such as `screen` can
run inside a pane.

The GUI stays open when a shell exits. Closing the window asks for
confirmation, with a status row that appears only for the question.

## Requirements

- Linux, for its PTY and libc interfaces, and a graphical desktop session.
- PHP 8.2 or newer on the command line, with FFI, PCNTL, POSIX, mbstring, and
  XMLReader enabled. GD is optional for some inline image formats.
- [SPTK](https://github.com/madocorp/SPTK) and the SDL3 and SDL3_ttf libraries
  required by SPTK. Follow SPTK's installation instructions for those
  libraries.

Check PHP with:

```sh
php --version
php -m | grep -Ei '^(FFI|pcntl|posix|mbstring|xmlreader|gd)$'
```

Install missing PHP extensions and SDL libraries with your system's package
manager. Package names vary by system.

## Installation

madirector is installed manually and does not use Composer. The example below
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
`madirector.php`. If you install either project elsewhere, change the paths
accordingly. Ensure `~/.local/bin` is in your `PATH`, or run the PHP script by
its full path. The entry point is executable; if a copied installation loses
that permission, restore it with `chmod +x madirector.php`.

## Running

Run the command installed above, or use `php madirector.php` from the checkout:

```sh
madirector
```

The first terminal starts `$SHELL` interactively, falling back to `/bin/sh`.
Each split and newly opened screen starts another shell. The child terminal
uses `TERM=xterm-256color`.

### Keyboard shortcuts

madirector has three input modes:

- **Terminal mode:** Keys go to the active terminal. Press and release Shift
  twice within 300 ms to enter command mode. Ctrl+V pastes into the terminal.
- **Command mode:** Arrow keys select tiles. Return activates the selected
  terminal. The commands below act on the selected terminal or its screen.
- **Scroll mode:** Browse output with a yellow cursor, select text, and copy it.
  Enter from command mode with **B**. Esc or double Shift returns to command
  mode; Return resumes terminal input if the shell is running.

#### Panes and screens in command mode

| Key | Action |
| --- | --- |
| **H** | Split left and right; the new terminal opens on the right. |
| **J** | Split top and bottom; the new terminal opens below. |
| **C** | Copy the text selected during the last scroll session. |
| **X** | Ask to close the selected terminal and end its shell. |
| **0–9** | Open or switch to a numbered screen; 0 is the first screen. |
| **Shift+arrow** | Swap the selected terminal with the terminal in that direction. |
| **Shift+0–9** | Move the selected terminal to that screen if it is empty. |
| **Z** | Fill the screen with the selected terminal and activate it; return to command mode and press Z again to restore the layout. |
| **M** | Send input to every running terminal on this screen and activate the selected one; return to command mode and press M again to turn it off. |
| **T / S** | Make the selected pane taller / shorter. |
| **W / N** | Make the selected pane wider / narrower. |
| **Keypad + / − / × / ÷** | The same four resizing actions. |
| **B** | Enter scroll mode for the selected terminal, including an exited one. |
| **V** | Paste the clipboard into the selected terminal and activate it. |
| **R** | Start a new shell in an exited terminal's pane. |

Splits open a new active terminal, with an SPTK separator between panes.
The close question appears in the temporary status row: Y or Return confirms,
and N or Esc cancels.
Resizing moves the right or lower edge when possible, or the opposite edge for
the last pane. Zoom and multiplex settings belong to each numbered screen;
`[multiplex]` appears in the window title while shared input is on. Moving a
terminal preserves its running programs. Closing the last terminal on a
screen returns to the previously used open screen. If no screen has a terminal,
press a digit to start one again.

#### Scroll mode and clipboard

- **Arrows** move the yellow cursor. **Home/End** move along a line, and
  **PageUp/PageDown** move by a page. **Ctrl+PageUp/PageDown** jump to the start
  or end of output.
- Hold **Shift** while moving to select text. **Ctrl+A** selects all;
  **C**, **Ctrl+C**, or **Ctrl+Insert** copies the selection to the clipboard.
- **Esc** or double Shift returns to command mode. **Return** resumes terminal
  input when the shell is running.

The selection stays available to command-mode **C** after leaving scroll mode.

To paste while typing, release Shift twice and press **V**. The terminal then
receives keyboard input again. If multiplex is on, the paste reaches every
running terminal on that screen.

### Terminal output and images

Terminal output supports scrollback and Kitty inline images. Images move with
scrollback. For example, if `timg` is installed, you can display SPTK's sample
image with Kitty graphics:

```sh
timg -pk -g64x24 "$HOME/.local/share/madirector/SPTK/Demo/Assets/test.jpg"
```

GD is needed to decode JPEG images. Change the path if you installed SPTK
elsewhere.

## Development

See [Development](Docs/Development.md) for the code map and verification
commands.
