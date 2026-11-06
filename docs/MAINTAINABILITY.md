# Maintaining Fundwise

The November 6, 2026 refactor keeps the existing PHP/MySQL architecture and UI. It does not introduce a framework or production dependency.

## Shared code

- `includes/view.php`: escaping (`fw_h`), currency amounts (`fw_format_money`), hidden form tokens (`fw_form_tokens`), and password inputs (`fw_password_field`). Password controls keep their existing IDs, validation attributes and JavaScript hooks. Only supported input attributes are emitted.
- `includes/auth_page_start.php` and `auth_page_end.php`: the common login/registration shell. Each page supplies its copy and retains its own validation and form.
- `includes/reliability.php`: the existing prepared-query function `fw_query` remains the shared database path. Do not build SQL from request values; pass bound arguments.
- `includes/general.php`: the existing cached `fw_has_table` replaces a duplicate schema lookup. Work-time validation names the parsed start/end values and keeps the same overnight/date rules.
- `includes/currency_display.php`: `fw_user_currency_code` returns the validated currency code; `fw_user_currency` retains the existing prefix with a trailing space. Neither converts amounts.
- `insights/common.php`: retains only feature-specific session, flash-message and month helpers.

The old `fi_query`, `fi_table_exists`, `fi_h`, `fi_cash` and `fi_currency` calls use the shared helpers directly. Uncalled `fi_exec` and `fi_db_notice` were removed. `css/style.css`, `css/sidebar.css` and the superseded `js/money-lab.js` had no active PHP/CSS/JS references and were removed. The loaded Money Lab script remains `js/money-lab-update2.js`.

## Working on a change

1. Read the affected controller and view; keep ownership, CSRF and duplicate-request checks in place.
2. Reuse a helper only when the rules match. Keep page-specific financial rules local and explicit.
3. Run `php tools/check.php`; run integration and browser checks when behavior or rendering may be affected.
4. Review the diff, commit the change, then upload matching files to InfinityFree. GitHub does not deploy automatically.

## Formatting

`.editorconfig` records UTF-8, LF and indentation. `.php-cs-fixer.dist.php` records the non-risky PHP style rules. Using a separately installed PHP CS Fixer 3.95.28, run:

```text
php /path/to/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --dry-run
php /path/to/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php
```

For the JavaScript files, the verified formatter is Prettier 3.6.2:

```text
npx --yes --package=prettier@3.6.2 prettier "js/*.js" --debug-check
npx --yes --package=prettier@3.6.2 prettier "js/*.js" --write
```

These are optional development tools, not required on InfinityFree. The PHP formatter excludes private `includes/config.php`. Do not reformat SQL, UI copy or styles as a substitute for understanding a change. Preserve UTF-8 when editing files containing peso signs, arrows or accented text.

## Compatibility and verification

No database migration is needed. The deployment package preserves the private database configuration and user uploads. Keep a backup of the preceding application files. Upload shared includes before pages that depend on them, and do not test the live site until the upload is complete.

The result record in `testing/refactor-results.json` summarizes the completed local checks. Those checks are not a production security certification.
