# MaDirector SPTK Migration

MaDirector is being migrated from old SPTK to the current SPTK toolkit over multiple sessions. Preserve old code until each feature is intentionally ported.

## Completed / In Progress

- [x] Create current-SPTK bootstrap in `madirector.php`.
- [x] Add bottom one-line command input.
- [x] Run one command on Enter.
- [x] Render command output in one terminal box using `ScreenBuffer`.
- [x] Keep existing PTY/process handling behind `CommanderHandler`.
- [x] Hide the command input while a command runs and focus the running terminal.
- [x] Forward current-SPTK key/text events to the running PTY for interactive commands.
- [x] Stack finished terminal boxes from the bottom while space remains.

## Next Migration Targets

- [ ] Restore command history and current session command line behavior.
- [ ] Restore full `CommandParser` shell syntax, aliases, variables, and internal commands.
- [ ] Support multiple terminal boxes and grouped parallel commands.
- [ ] Expand terminal input/grab mode beyond the active running command.
- [ ] Restore scroll mode, search, selection, copy, and zoom.
- [ ] Restore kill/signal panel.
- [ ] Restore completion providers.
- [ ] Restore sessions and session navigation.
- [ ] Restore inline terminal images.

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
