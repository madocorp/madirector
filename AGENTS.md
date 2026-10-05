# Repository Guidelines

## Architecture

MaDirector uses the `SPTK/` toolkit.
The entry point defines `APP_DIR` and `APP_NAMESPACE` before requiring
`SPTK/App.php`; SPTK loads `MADIR\` classes from the matching directory paths.

The app is in `App/`. It uses the command parser and internal
commands, completion providers, PTY commander, ANSI parser, and screen buffer.

## Development Commands

- `php -l madirector.php` checks the entry point.
- `find . -path ./.git -prune -o -path ./SPTK -prune -o -name '*.php' -exec php -l {} +` lints MaDirector PHP files while skipping the SPTK symlink.
- `php madirector.php` runs the app and opens an SDL window.
- From `../SPTK`, run the relevant standalone script in `Tests/` when touching
  shared toolkit behavior.

## Coding Style

Use PHP namespaces that mirror directories under `MADIR\...`. Follow existing style: two-space indentation, same-line braces, short arrays, and typed signatures where practical.

Prefer thin adapters around the existing terminal and process code over
rewriting ANSI parsing, PTY handling, process management, or command parsing.
