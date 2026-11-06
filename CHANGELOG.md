# Changelog

## November 6, 2026 - Maintainability refactor

- Share login/registration layout, password controls, form-token markup and display helpers.
- Consolidate financial-intelligence SQL and schema checks using the existing prepared-query helpers, and share the currency lookup with Reports.
- Remove unused legacy styles/scripts, unused helpers and an unused work-cost argument.
- Expand compact PHP controllers and format active JavaScript consistently; document repeatable formatting.
- Keep routes, financial rules, security checks and schema compatible with Update3.

## November 5, 2026 - Update3

- Center password visibility controls inside consistently sized fields across authentication and Settings.
- Use outlined blue actions at rest and blue fill on pointer hover, keyboard focus or press, with stable dimensions and touch-aware hover behavior.
- Offer five broad work-cost categories, retain saved food/deduction classifications without reassigning data, and clarify the role of descriptions and amounts.
- No database migration or automatic cash posting is introduced.

## Recovered on November 5, 2026

These entries summarize saved implementation versions imported into Git today. They describe functionality, not historical commit dates. See `docs/RECOVERY_HISTORY.md` for provenance and limitations.

### Update2
- Consistent primary-page banners; calmer support pages and unified export interactions.
- Money Lab baseline/day transaction review, category-specific label suggestions and neutral helper text.
- Static locked currency, clearer recovery guidance and password visibility controls.
- Explicit manual/timed work entry before costs, preserving overnight start-date grouping.
- Debts & Loans installment schedules, partial payments, manually entered charges, corrections and per-loan alerts.

### Login refinement
- Defined authentication form cards, stronger branding illustration and requested eye-icon states.

### Refined interface and reminders
- Cleaner shared copy, consistent summary cards, Purchase Map spacing and logout confirmation.
- Reminder preferences, personal schedules, snoozing and in-app polling.

### General financial update
- Multiple work sources, overnight shifts, work-cost transaction links and explicit spending labels.
- Manual debt register and linked savings challenge progress.

### Financial Intelligence v2 and preceding fixes
- Reviewed baselines/day confirmations, conditional purchase scenarios and revised earnings calculations.
- Savings allocation layout, category CSRF compatibility and minimalist branded PDF reports.
- Authentication/recovery, validation/history safeguards, structured work costs and export refinements.
- Original-design mobile navigation width fix and original uploaded application snapshot.
