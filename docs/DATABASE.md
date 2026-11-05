# Database installation and upgrades

## Clean installation
Create an empty database with UTF-8 support and select it before importing `database/database.sql`. This structure-only file contains the final 38 tables in foreign-key dependency order, no private data, and no `CREATE DATABASE`, `USE`, `DROP`, `INSERT`, user password hashes or account rows. Keep foreign key checks enabled. Registration creates an account and its default categories through the application.

Do not import the clean schema into an existing populated installation. It is deliberately a fresh-install script, not a reset or migration script.

## Existing site: apply only missing migrations
1. `database/optional_savings_allocations.sql` if the allocation table is absent.
2. `database/financial_intelligence.sql` if the work/experiment tables are absent.
3. `database/20261102_upgrade.sql` for reliability/security/history support.
4. `database/20261102_financial_intelligence_v2.sql` for reviewed comparisons, scenarios and work details.
5. `database/20261103_general_update.sql` for time ranges, labels, links and debt tracking.
6. `database/20261104_interface_reminders.sql` for reminder preferences and personal reminders.
7. `database/20261104_update2.sql` for loan installments, allocations and per-loan reminder preferences.

Back up files and export the private database first. Review migration comments against the actual existing schema. Do not import old data dumps to upgrade. After a fresh `database.sql` install, the dated migrations are already represented and do not need replaying.

## Modeling boundaries
The schema includes 10 physical foreign-key constraints in the verified export. Many extension tables use application-enforced ownership and references rather than additional database foreign keys. The logical ERD is not a claim that every relationship has a physical constraint. See `DATA_DICTIONARY.md` for actual column keys and `SYSTEM_DESIGN.md` for relationships.

Use `DECIMAL` amounts and cent-based validation for relevant calculations. PHP and SQL sessions use Philippine time (+08:00); timed work is grouped by start date. Ledger currency is stored on the account and locked once financial records exist; currency conversion does not modify rows.

The public repository must contain only clean schemas and migrations. Private data exports belong in secure backups, never public Git history.
