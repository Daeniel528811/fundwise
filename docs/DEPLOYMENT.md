# Deployment guide

## Current target
InfinityFree PHP/MySQL hosting; root `htdocs` deployment. Public entry point: https://fundwise-ph.free.je/auth/login.php. Hosting availability and the exact deployed version must be checked by the student; local screenshots are not proof of live operation.

## Procedure
1. Back up current hosted files and export the private database.
2. Create/select the MySQL database using the host's dashboard. Use the actual hostname supplied there, not localhost unless the host explicitly says so.
3. For a new site, import `database/database.sql`; for an existing site, follow `DATABASE.md` and apply only missing migrations.
4. Upload application directories (`api`, `assets`, `auth`, `budgets`, `categories`, `css`, `currency`, `debts`, `includes`, `insights`, `js`, `notifications`, `profile`, `reports`, `savings`, `transactions`, `uploads`) and `index.php` into `htdocs`. Keep the folder structure. Folder merge failures can be handled by opening each destination folder and replacing its matching files individually.
5. Copy `includes/config.example.php` to a private `includes/config.php`. Set `BASE_URL=''` and enter the hosting connection values privately.
6. Preserve receipt access rules and file contents. Ensure PHP can write receipt files; test that direct-file HTTP access is blocked and the authenticated receipt route checks ownership.
7. Exclude `.git`, configuration backups, SQL scripts, documentation, tests and real data exports from the served web root. A clone is a development copy, not a folder to expose wholesale.
8. Check HTTPS, refresh caches, and test registration, sign-in, record creation/edit/deletion, reports, currency requests, theme persistence, reminders and phone navigation.

## Troubleshooting
| Symptom | Check |
| --- | --- |
| Database connection error / 2002 | Hostname, database service availability, connection values, and execution on the hosting server. Never publish the password for debugging. |
| Missing table after upload | Confirm the required additive migration was imported into the correct existing database. |
| Old UI | Verify matching CSS/JS/PHP files were replaced; hard refresh or use a private window. |
| Folder move failed | Inspect the actual error; copy matching files within existing folders rather than assuming the manager merged them. |
| Invalid form token / expired form | Reload the page and use the fresh form. Check shared session and token handling; do not disable CSRF. |
| Missing API rates/icons/fonts | Inspect browser Network/Console and internet/CDN availability; distinguish local fallbacks from actual remote data. |
| Reminder did not arrive with website closed | Current notifications are in-app, not background phone/email delivery. |

## Rollback
Restore backed-up application files and retain database backups. The latest three-table migration is additive; do not drop tables or erase repayment records casually. Existing loan allocation history must be preserved when correcting payments. Git records source history, not hosting deployment state or database backups.
