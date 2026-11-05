# Fundwise project proposal

**A Web-Based Income, Expense, and Savings Planning System**

Developer: Daeniel Abujen (GitHub: Daeniel528811)  
Course: Web Systems Technology - Individual Final Project  
Document reconstructed: November 5, 2026 (Asia/Manila)

This document replaces an empty repository placeholder. It summarizes the current implementation; it is not evidence of an earlier submitted or instructor-approved proposal. The student must confirm the approved title/scope and add the required student ID, section and instructor details before academic submission.

## Background and problem
People may keep income, expenses, savings and repayment notes in separate places. This makes it harder to review spending against a budget, distinguish gross work earnings from earnings after costs, or see upcoming obligations. Fundwise brings these manual records and everyday planning tasks into a single browser-based system.

## Target users
Individuals managing personal income, expenses and saving priorities, including people with more than one income source. The current system has one regular-user account type. It is not targeted specifically at traders and does not require an administrator role to deliver its main workflows.

## Main objective
Develop, deploy and document a responsive PHP/MySQL personal finance system that supports accurate recording and review of user-entered financial information and conditional everyday planning.

## Specific objectives
1. Support secure password handling, sessions, account recovery codes and user-owned access to records.
2. Provide CRUD for income/expense categories, transactions, budgets and saving records.
3. Present reports and visualizations based on the user's selected dates and records.
4. Support reviewed habit comparisons, purchase scenarios, work earnings and manual debt repayment schedules.
5. Demonstrate Fetch/API communication, JavaScript interactivity, validated receipt uploads, theme persistence and in-app reminders.
6. Use Git/GitHub, test relevant flows, deploy to a PHP/MySQL host and document installation and limitations.

## Main features
- Income/expense recording, categories, search, filters and private receipts.
- Spending budgets, savings goals, saving challenges and allocation plans.
- Money Lab, Purchase Map and Real Earnings Lens.
- Manual debt/loan tracking, installments and configurable in-app reminders.
- PDF/XLSX/CSV reports, reference currency conversion, light/dark theme and responsive navigation.

## Technology stack and database plan
HTML5, CSS3, Bootstrap, Bootstrap Icons, JavaScript ES6+, Chart.js, Fetch API, PHP 8.2+, MySQL/MariaDB and Git/GitHub. InfinityFree provides the current PHP/MySQL deployment. Core entities include users, categories, transactions, budgets, saving goals and contributions; support tables record work costs, experiments, scenarios, debt payments, installments, reminders and history. The clean schema contains 38 tables. Logical relationships and physical constraints are documented separately.

## Scope and limitations
The system uses manual records and does not synchronize bank accounts, calculate net worth, value investments or provide investment accounting. Savings and debts are separate registers and do not automatically create cash transactions. Loan interest and fees are entered amounts, not a lender amortization calculation. Money Lab comparisons depend on labels, complete records and user confirmations; they do not prove causation. Purchase Map is a conditional estimate. Reminders work inside the app; email/SMS/push delivery and email verification are unavailable. Lost password plus lost recovery code cannot be recovered through the current self-service flow.

## Expected output and evaluation
A working publicly accessible application, complete repository, clean SQL schema, diagrams, documentation and a student-led presentation/demonstration. Evaluation should combine calculation and functional checks, ownership/security cases, responsive tests at required dimensions, deployment checks and user task evaluation. Current automated results establish checked behavior; they do not demonstrate improved financial outcomes or replace instructor evaluation.
