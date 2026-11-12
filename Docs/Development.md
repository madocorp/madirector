# Development

madirector uses SPTK for its window, screen navigation, tile layout, and
rendering. `App/Controller.php` owns modes, numbered screens, pane commands,
and multiplex state. `App/TerminalTile.php` paints each terminal and handles
scroll selection. `App/TerminalInput.php` translates keyboard events into PTY
bytes. `App/RunningShell.php`, `Pty/`, and `Screen/` own shell processes,
terminal output, and image state.

## Verification

Run these commands from the madirector checkout. GUI tests need a display;
`xvfb-run` provides one for unattended runs.

```sh
php Tests/ShellPty.php
xvfb-run -a php Tests/CloseConfirmation.php
xvfb-run -a php Tests/Splits.php
xvfb-run -a php Tests/NumberedScreens.php
xvfb-run -a php Tests/ClosePane.php
xvfb-run -a php Tests/ResizePane.php
xvfb-run -a php Tests/MovePane.php
xvfb-run -a php Tests/MultiplexInput.php
xvfb-run -a php Tests/ScrollClipboardRestart.php
find . -path ./SPTK -prune -o -name '*.php' -exec php -l {} +
```
