# Verification and testing

## Recorded automated results
On November 5, 2026, the recovered HEAD was copied to a local checkout and tested against a new isolated MariaDB database created from the public clean schema. The schema imported with foreign key checks enabled: 38 tables and zero initial rows. The clean export contains no test account or transaction data.

The repeated backend/calculation suites passed **255 checks**: 84 regression/calculation, 55 previous-feature, 38 reminder, 61 Update2 and 17 additional edge checks. These include test-account setup checks; the count is not 255 independent security vulnerabilities assessed. Account fixtures were synthetic and remain outside Git. Safe JSON result summaries are in `docs/testing`.

On November 4, the same application source passed 68 responsive page checks at widths 320, 390, 768 and 1366, plus 9 expanded-form checks at widths 320, 390 and 1366. The browser runs recorded zero JavaScript exceptions. These earlier width checks are retained with their provenance; their viewport heights do not replace the assignment's exact dimension checks. Existing and Update2 browser interaction reports cover logout, forms, Money Lab, overnight time, schedule creation and password visibility.

On November 5, an additional local browser run passed **51 page checks at the exact course viewports**: 1366x768, 768x1024 and 375x667. No JavaScript exceptions were recorded. See `testing/exact-viewports.json`. The student must still verify the actual deployed site and accessibility manually.

## Reproducible local checks
Use PHP 8.2+ from the repository root:
```sh
php tests/validation_test.php
php tests/reliability_test.php
php tests/loan_math_test.php
php tools/check.php
```
`tools/check.php` runs PHP syntax checks and these pure validation/calculation tests. It does not connect to a database. Optionally use `node --check` on changed JavaScript files. There is no application bundling step.

The historical HTTP integration harness used a private isolated test service and is not a production migration runner. The public JSON summaries record outcomes; rerun the manual cases below against a dedicated test installation. Never seed test accounts, change dates, or run destructive cleanup against the user's production database.

## Manual cases and expected outcomes
The table below is a manual execution checklist. Expected behavior is not a claim that the student has completed every deployed-host test. Record actual result, date, environment and pass/fail when executing it.

| Case | Expected outcome |
| --- | --- |
| Register with valid data | Account/default categories created; private recovery code shown once. |
| Invalid credentials / repeated attempts | Safe error; relevant authentication throttling activates. |
| Change password / recover password | Replacement credentials work; old recovery code and other credential versions become invalid. |
| Logout, then revisit protected route | Session ends; access redirects to sign-in. |
| Create, edit and delete a transaction | Only owned rows change; totals reflect the change. |
| Cross-account record IDs / downloads | No other user's data is revealed or modified. |
| Invalid CSRF / replay a form | Request rejected without duplicate mutation. |
| Valid / invalid receipt uploads | Accepted JPG/PNG up to configured limit; invalid content rejected. |
| Direct receipt URL / authenticated receipt | Direct URL denied by host; owner route succeeds; foreign owner denied. |
| Budget overlap and overspend | Overlapping same-category ranges rejected; correct totals and configured alerts. |
| Savings withdrawal exceeds available | Rejected; no unintended negative saving balance. |
| Allocation above 100 percent | Rejected; allocations do not move money. |
| Money Lab matching label / another label / no label | Matching amounts separated; unclassified records shown; baseline date range visible. |
| Incomplete experiment day | Missing records are not treated as confirmed success. |
| Overnight work across dates/months | Hours exclude break; session groups under starting date. |
| Zero earnings or untimed work | Loss retained; no division by zero; untimed net excluded from hourly comparisons. |
| Existing work-payment link | No duplicate cash transaction is created by linking. |
| Purchase scenario | Assumptions and shortfall shown; saving estimate creates no cash movement. |
| Loan: 10000 across 3 installments | Principal 3333.33, 3333.33, 3333.34; entered charges separate. |
| Partial installment / overpayment / void | Remaining due accurate; overpayment rejected; void restores due amount. |
| Principal paid but fees/interest remain | Charges stay scheduled and eligible for configured alerts. |
| Reminder snooze / disabled / overdue repeat | Delivery follows preferences; no same-period duplicate; in-app limitations clear. |
| Report date ranges and exports | PDF/XLSX/CSV match the selected owned records; no claim of Excel fidelity without opening the file. |
| Currency filtering and swap | Swap clears stale search; conversion is reference-only. |
| Remote API unavailable | Useful feedback/fallback; no invented exchange rates. |
| Exact responsive sizes | 1366x768, 768x1024 and 375x667: no unintended page overflow or inaccessible actions. |
| Keyboard and readability | Logical focus order, visible focus, labels, dialog Cancel/Escape and sufficient readable contrast. |

## Limits of evidence
Local automated results do not establish comprehensive security, production availability, screen-reader compliance or improved financial behavior. No formal participant usability study, independent penetration test or deployed-host certification is claimed. Complete the live and accessibility checks before submission.

## Update3 verification - 2026-11-05

Password eyes and outlined actions passed light/dark desktop, tablet and phone checks, including keyboard focus and touch release. Five work-cost choices passed create/edit, totals, ownership and legacy preservation checks. See [results](testing/update3-results.json). No schema migration is required.
