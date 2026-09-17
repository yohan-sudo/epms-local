# 03 — Entity-Relationship Diagram (ERD)

> Generated from the **live** `factory_db` schema (13 foreign keys across 9 tables).
> Notation: `||` exactly one, `o|` zero-or-one, `o{` zero-or-more.

## 1. Full ERD

```mermaid
erDiagram
    users ||--o{ procurement_entries : "submitted_by"
    users ||--o{ procurement_entries : "manager_approved_by"
    users ||--o{ procurement_entries : "accountant_approved_by"
    users ||--o{ procurement_entries : "admin_approved_by"
    users ||--o{ daily_reports : "supervisor_id"
    users ||--o{ petty_cash_issuances : "issued_by (CEO)"
    users ||--o{ petty_cash_issuances : "issued_to (Accountant)"
    users ||--o{ petty_cash_expenses : "approved_by"
    users ||--o{ audit_logs : "actor_id"

    processes ||--o{ machines : "process_id"
    machines ||--o{ daily_reports : "machine_id"
    daily_reports ||--o| process_reject_logs : "report_id"
    processes ||--o{ process_reject_logs : "process_id"

    petty_cash_issuances ||--o{ petty_cash_expenses : "issuance_id"

    users {
        int id PK
        varchar name
        varchar username UK
        varchar password_hash
        varbinary password_encrypted "nullable, legacy vault copy"
        varchar role "CEO / Manager / Accountant / Procurement Officer"
        varchar status "Active or Banned"
        datetime created_at
    }
    processes {
        int id PK
        varchar name "Rounding / Sanding / P.V.C K Line / P.V.C O Line / Cups / Packaging-Sewing"
        text description
        varchar status "Active"
    }
    machines {
        int id PK
        varchar code UK "R1 R2 S1 S2 K1 O1"
        varchar name
        int process_id FK
        varchar status "Operational / Maintenance / Down"
    }
    daily_reports {
        int id PK
        date report_date
        varchar shift "Morning / Afternoon / Night"
        int supervisor_id FK
        int machine_id FK
        int units_produced "Units Processed (gross)"
        int good_units "DERIVED: units - rejects"
        text supervisor_notes
        datetime created_at
    }
    process_reject_logs {
        int id PK
        int report_id FK
        int process_id FK
        int partial_reject_count "reworkable"
        int total_reject_count "scrapped"
        varchar reject_reason
        varchar root_cause
    }
    procurement_entries {
        int id PK
        varchar reference_no UK "PRC-YYYY-NNNN"
        int submitted_by FK
        varchar supplier
        varchar item_name
        varchar category
        decimal quantity
        varchar unit
        decimal unit_cost
        decimal total_cost "qty x unit_cost"
        varchar status "Pending Manager Review / Pending Accountant Review / Finalized / Rejected"
        int manager_approved_by FK
        datetime manager_approved_at
        text manager_notes
        int accountant_approved_by FK
        datetime accountant_approved_at
        text accountant_notes
        int admin_approved_by FK
        datetime admin_approved_at
        text admin_notes
        text rejection_reason
        date date
        datetime created_at
    }
    petty_cash_issuances {
        int id PK
        varchar voucher_no UK "PV-YYYY-NNNN"
        int issued_to FK "Accountant only (app rule)"
        int issued_by FK "CEO only (app rule)"
        decimal amount "800,000 to 7,000,000 TZS (app rule)"
        varchar purpose
        varchar status "Active or Closed"
        date issued_date
        datetime created_at
    }
    petty_cash_expenses {
        int id PK
        int issuance_id FK
        date expense_date
        varchar category
        varchar description
        decimal amount
        varchar receipt_no
        int approved_by FK "recording officer"
        datetime created_at
    }
    audit_logs {
        int id PK
        int actor_id FK "nullable, detached rows remain"
        varchar action "e.g. LOGIN, REPORT_GENERATED, PROCUREMENT_FINALIZED"
        varchar entity_type
        varchar entity_id
        text details
        datetime timestamp
    }
```

## 2. Relationship Narrative (every line explained)

### The `users` hub (8 outbound FK references)
`users` is the centre of the schema. Every "who did this" column in the system points at it:

| Relationship | Cardinality | Meaning |
|---|---|---|
| users → `procurement_entries.submitted_by` | 1 : 0..N | The Officer who created the record |
| users → `procurement_entries.manager_approved_by` | 1 : 0..N | Manager gate decision-maker (NULL until approved) |
| users → `procurement_entries.accountant_approved_by` | 1 : 0..N | Final gate decision-maker (NULL until finalized) |
| users → `procurement_entries.admin_approved_by` | 1 : 0..N | Reserved CEO-override column (kept for the override path) |
| users → `daily_reports.supervisor_id` | 1 : 0..N | The Manager/CEO who filed the shift report |
| users → `petty_cash_issuances.issued_by` | 1 : 0..N | CEO who disbursed the float |
| users → `petty_cash_issuances.issued_to` | 1 : 0..N | Accountant holding the float (app rule: only Accountants) |
| users → `petty_cash_expenses.approved_by` | 1 : 0..N | Officer (Accountant/Manager) who recorded the expense |
| users → `audit_logs.actor_id` | 1 : 0..N | Who performed the audited action. **Nullable + SET NULL on delete:** when a user is hard-deleted (CEO's Personnel Registry action), the audit rows survive detached — the trail stays immutable. |

> **Why four approval columns instead of one?** The two-gate workflow (Manager → Accountant) stores *who decided at which gate*, *when*, and *their notes* separately. This makes the approval chain auditable and prevents any single role from rewriting another's decision.

### The production chain (processes → machines → daily_reports → process_reject_logs)
| Relationship | Cardinality | Meaning |
|---|---|---|
| processes → machines | 1 : N | Each machine belongs to exactly one process stage (R1, R2 → Rounding; S1, S2 → Sanding; K1 → P.V.C K Line; O1 → P.V.C O Line). |
| machines → daily_reports | 1 : N | Each shift report is filed against one machine. |
| daily_reports → process_reject_logs | 1 : 0..1 | One reject breakdown per report (written in the same transaction; the JOIN in queries is `LEFT JOIN … ON l.report_id = r.id`). |
| processes → process_reject_logs | 1 : N | The stage where the rejects occurred (drives the In-Process vs Completed-Goods classification: stage 5 = Cups ⇒ Completed Goods). |

**Production business rule encoded here:** `daily_reports.units_produced` is the gross *Units Processed*; `good_units` is **derived** (`units_produced − partial_reject_count − total_reject_count`, floored at 0) and never typed by the user. Rejects can never exceed units processed (validated in `production.php` before insert).

### The petty-cash chain (issuances → expenses)
| Relationship | Cardinality | Meaning |
|---|---|---|
| petty_cash_issuances → petty_cash_expenses | 1 : N | A float voucher is disbursed once, then consumed by many expenses. Remaining balance = `amount − SUM(expenses.amount)` — computed in every listing/report, never stored, so it can never go stale. |
| users (CEO) → issuances.issued_by, users (Accountant) → issuances.issued_to | 1 : N | Two roles in one voucher, enforced by app logic (amount 800k–7M TZS; holder = active Accountant). |

### The audit spine
`audit_logs` is write-only from the application's point of view: `logAudit()` inserts (actor, action, entity_type, entity_id, details, timestamp) whenever security-relevant events happen — logins, user CRUD, float issuance, procurement decisions, report generation. Nothing ever UPDATEs or DELETEs it, and `actor_id` is `ON DELETE SET NULL` so even deleted users leave a trace.

## 3. Crow's-Foot ERD (alternative Chen-style Mermaid)

If your documentation tooling prefers classic crow's-foot diagrams (e.g. draw.io import), use this layout-optimised version:

```mermaid
erDiagram
    PROCESSES ||--o{ MACHINES : "groups"
    MACHINES ||--o{ DAILY_REPORTS : "produced on"
    DAILY_REPORTS ||--o| PROCESS_REJECT_LOGS : "breaks down into"
    PROCESSES ||--o{ PROCESS_REJECT_LOGS : "occurred at"

    PETTY_CASH_ISSUANCES ||--o{ PETTY_CASH_EXPENSES : "spent through"

    USERS ||--o{ AUDIT_LOGS : "acted in"
    USERS ||--o{ PROCUREMENT_ENTRIES : "submits and approves x3"
    USERS ||--o{ DAILY_REPORTS : "supervises"
    USERS ||--o{ PETTY_CASH_ISSUANCES : "issues / holds"
    USERS ||--o{ PETTY_CASH_EXPENSES : "records"
```

## 4. Integrity Rules Summary

| Rule | Enforced by |
|---|---|
| Every FK has an index (`MUL`) | Schema (verified via `information_schema`) |
| Deleting a user keeps their audit rows | `audit_logs.actor_id` nullable + SET NULL |
| Orphaned reports impossible if machine/process deleted | FK constraints on `machines.process_id`, `daily_reports.machine_id`, etc. |
| One reject-log per report | App transaction + `report_id` semantics (single insert per report) |
| Unique identifiers | `users.username`, `machines.code`, `procurement_entries.reference_no`, `petty_cash_issuances.voucher_no` (all `UNI`) |
| Money precision | `DECIMAL(14,2)` on all monetary columns; quantities `DECIMAL(12,2)` |
