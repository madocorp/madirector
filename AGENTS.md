# Repository Guidelines

## Architecture

madirector uses the `SPTK/` toolkit.
The entry point defines `APP_DIR` and `APP_NAMESPACE` before requiring
`SPTK/App.php`; SPTK loads `MADIR\` classes from the matching directory paths.

The app is in `App/`. It runs one interactive shell per terminal pane through
`Pty/ShellProcess.php`. `App/Controller.php` manages modes, screens, splits,
and multiplex input. `App/TerminalTile.php` paints the terminal and handles
scroll selection. `Screen/` parses and stores terminal output.

## Development Commands

- `php -l madirector.php` checks the entry point.
- `find . -path ./.git -prune -o -path ./SPTK -prune -o -name '*.php' -exec php -l {} +` lints madirector PHP files while skipping the SPTK symlink.
- `php madirector.php` runs the app and opens an SDL window.
- `php Tests/ShellPty.php` checks the shell PTY. Run the GUI tests in `Tests/`
  with `xvfb-run -a php Tests/<name>.php` on a headless machine.
- From `../SPTK`, run the relevant standalone script in `Tests/` when touching
  shared toolkit behavior.

## Coding Style

Use PHP namespaces that mirror directories under `MADIR\...`. Follow existing style: two-space indentation, same-line braces, short arrays, and typed signatures where practical.

Prefer thin adapters around the existing terminal and process code over
rewriting ANSI parsing, PTY handling, or process management.
