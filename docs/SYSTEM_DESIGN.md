# System design

Diagrams reflect the current implementation, reconstructed November 5, 2026. The current system has one regular-user role. These are design explanations, not evidence of an earlier approved design submission.

## Architecture

![Architecture](diagrams/architecture.png)

```mermaid
flowchart TD
    Browser[Browser: HTML / CSS / JavaScript] -->|Forms and Fetch over HTTPS| PHP[PHP: authentication, validation, CRUD]
    PHP -->|Prepared MySQLi queries| DB[(MySQL / MariaDB)]
    Browser -->|Reference rates| Rates[Frankfurter API]
    Browser --> CDN[CDN libraries and fonts]
    PHP -->|Owner-authorized route| Files[Private receipt files]
```

## Use cases

![Use cases](diagrams/use-case.png)

```mermaid
flowchart LR
    U[Regular user] --> Auth[Register / sign in / recover]
    U --> T[Transactions and categories]
    U --> B[Budgets and savings]
    U --> I[Work / habits / purchase planning]
    U --> D[Debts, installments and repayments]
    U --> R[Reports and reminders]
    U --> P[Profile, security and logout]
```

## Record mutation flow

![Flowchart](diagrams/flowchart.png)

```mermaid
flowchart TD
    A[Protected request] --> B{Valid session?}
    B -->|No| L[Sign in]
    B -->|Yes| C{CSRF and request token valid?}
    C -->|No| E[Safe rejection; no mutation]
    C -->|Yes| D{Record owned and input valid?}
    D -->|No| E
    D -->|Yes| F{Business rules / version valid?}
    F -->|No| E
    F -->|Yes| G[Atomic save and supported audit/history]
    G --> H[Feedback and refreshed view]
```

## ERD and physical constraints

![Selected core financial entities](diagrams/erd.png)

The PNG is a selected core view, not the full schema. The [complete 38-table ERD source](diagrams/erd-full.mmd) lists all fields and identifies physical FKs versus application-only references. The [database dictionary](DATA_DICTIONARY.md) and clean SQL are authoritative for actual column types, keys and the ten physical foreign-key constraints. Many extension tables rely on PHP ownership/reference checks. A relationship in the logical ERD does not mean a database RLS policy or physical FK exists.

Editable Mermaid sources are supplied with the PNGs. For the complete ERD, open erd-full.mmd in a Mermaid-capable viewer and export at a readable size for submission; do not shrink all 38 tables into a single unreadable slide.

## Interface references

The published screenshot folder shows the existing implementation using synthetic local test data, explicitly approved for publication by the project owner. Actual pages include shared responsive navigation, blue/navy/cyan banners, forms, summary cards, charts, clear feedback and dark/light modes. These are implementation references, not invented evidence of historical wireframe work.
