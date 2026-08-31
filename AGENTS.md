# Repository Guidelines

## Migration Context

MaDirector is being migrated from old SPTK to the current `SPTK/` toolkit in staged slices. The old SPTK reference lives at `../../oldmad/oldSPTK` from this repository directory.

Do not reintroduce `SPTK2` names. Current app code should use `SPTK\...`.

## Important Guardrail

Do not delete old MaDirector code just because it is unused by the current first migration slice. Large parts of `Screen/`, `Command/`, `Completion/`, `Layout/`, and old controller code are intentionally parked for later migration work.

The first current-SPTK slice only proves command input, PTY execution, ANSI parsing, and terminal grid rendering. Features such as sessions, command history, completion, search, kill panels, scroll/grab/zoom modes, multiplexing, and inline images are later migration targets.

## Development Commands

- `php -l madirector.php` checks the entry point.
- `find . -path ./.git -prune -o -path ./SPTK -prune -o -name '*.php' -exec php -l {} +` lints MaDirector PHP files while skipping the SPTK symlink.
- `php madirector.php` runs the app and opens an SDL window.
- From `../SPTK`, run `php Tests/run.php` when touching shared toolkit behavior.

## Coding Style

Use PHP namespaces that mirror directories under `MADIR\...`. Follow existing style: two-space indentation, same-line braces, short arrays, and typed signatures where practical.

For migration work, prefer adding thin adapters around existing terminal/process code over rewriting ANSI parsing, PTY handling, process management, or command parsing.
