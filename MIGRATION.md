# MaDirector SPTK Migration

The active `madirector.php` entry point now uses the current SPTK app, screen, layout, and widget APIs.
The older UI classes remain parked for later feature work. The command parser, command state, PTY
commander, ANSI parser, and screen buffer remain the execution path.

## Active current-SPTK UI

- Parallel commands share 2-column, 3-column, or 4-column terminal grids in a vertical overflow
  stack with a cwd-labeled prompt. PageUp/PageDown move by group, and arrow movement reveals
  clipped tiles at the appropriate edge.
- Command prompt, working-directory line, command history, and interactive PTY input.
- Existing shell parsing, aliases, variables, pipelines, redirections, parallel commands, and internal
  command handling through `CommandSession`.
- Named and numbered sessions, listing, clearing, deleting, and exit options.
- Layout navigation, terminal input and scroll modes, Ctrl+PageUp/PageDown tile selection,
  and zoom with input or scroll mode.
- Scrollback selection and clipboard copy, plus the inline signal tile for one terminal or a group.
- Command completion through the retained providers, shown in a tile above the prompt.
- Normal-mode session switching, first/last selection, and confirmed removal of finished tiles.
- Terminal search with an in-tile pattern field and previous/next match navigation.

## Remaining UI work

- Inline terminal images.

The old `Shell`, `TerminalStack`, `TerminalBox`, `TerminalView`, and `Screen/` classes
are intentionally retained while these controls are migrated. The current UI now
reuses the retained completion providers and routing logic.

## Do Not Clean Up Yet

These areas may look unused during early migration but are intentionally retained:

- `Command/Command.php`
- `Command/Session.php`
- `Command/InternalCommands.php`
- `Completion/`
- `Layout/*.xml`
- `Layout/style.xss`
- `Screen/Controller.php`
- `Screen/Terminal.php`
- `Screen/Picture.php`
- old key translation helpers

Remove or rewrite them only as part of a deliberate migration step.
