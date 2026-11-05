# Development workflow

This is an individual course project. Use Git to track actual changes as they happen.

1. Clone the existing repository; do not initialize a replacement history or force-push over `main`.
2. Configure Git with your own verified identity. Create a branch for a focused change, e.g. `feature/budget-filter` or `fix/form-validation`.
3. Keep `includes/config.php`, receipts, credentials and data exports untracked. Check `git status --short` and the staged diff before committing.
4. Make a focused change, run PHP/JavaScript checks and relevant functional tests, then write an accurate commit message explaining the change.
5. Review the diff in GitHub and merge the branch when ready. Commit related migrations alongside the code that needs them.
6. Deploy separately after backing up and test the live behavior. A Git push does not automatically deploy InfinityFree.

Example commands (run inside your own clone):
```sh
git status --short
git switch -c fix/descriptive-change-name
git diff
git add path/to/changed-file.php
git diff --cached
git commit -m "Fix budget filter date validation"
git push -u origin fix/descriptive-change-name
```

Prefer precise messages such as `Add reviewed loan installment payments` to `update`, `final2` or `test`. Do not create empty commits or alter timestamps to imply work was committed earlier. The recovered history is explicitly documented in `docs/RECOVERY_HISTORY.md`.

There is no Composer or Node build step for the application itself. PHP scripts and static JS/CSS are deployed directly; optional Node is used for local syntax checks. The project has no declared open-source license; obtain the owner's permission before redistribution.
