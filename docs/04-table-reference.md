# 04 — Table Reference & Relations

> Column-for-column reference for all 9 tables in `factory_db`, extracted from the live schema
> (`SHOW COLUMNS` / `information_schema`). Types, defaults, keys and every relationship are as deployed.

## Quick Map

| # | Table | Purpose | Rows reference (live) | Primary children |
|---|---|---|---|---|
| 1 | `users` | Accounts & roles | 5 (CEO, Manager, Accountant, 2× Procurement Officer) | referenced by everything |
| 2 | `processes` | 6-stage production pipeline | 6 | machines, process_reject_logs |
| 3 | `machines` | Machine registry per stage | 6 (R1, R2, S1, S2, K1, O1) | daily_reports |
| 4 | `daily_reports` | Shift production headers | grows daily | process_reject_logs |
| 5 | `process_reject_logs` | Reject breakdown per report | 1 : 0..1 with daily_reports | — |
| 6 | `procurement_entries` | Procurement records + 2-gate approval | pipeline rows | — |
| 7 | `petty_cash_issuances` | Float vouchers | 2 Active | petty_cash_expenses |
| 8 | `petty_cash_expenses` | Expenses against floats | ledger rows | — |
| 9 | `audit_logs` | Immutable activity trail | grows forever | — |

---

## 1. `users` — accounts & roles

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| name | VARCHAR(120) | NO | | | Full display name |
| username | VARCHAR(60) | NO | **UNI** | | Login handle |
| password_hash | VARCHAR(255) | NO | | | bcrypt (`password_hash`) |
| password_encrypted | VARBINARY(512) | YES | | NULL | Legacy vault copy (read-only feature) |
| role | VARCHAR(40) | NO | | | `CEO` \| `Manager` \| `Accountant` \| `Procurement Officer` |
| status | VARCHAR(20) | NO | | `Active` | `Active` \| `Banned` (banned ⇒ login refused) |
| created_at | DATETIME | NO | | current_timestamp() | |

**Referenced by (roles in brackets):**
- `procurement_entries.submitted_by` [Procurement Officer] · `.manager_approved_by` [Manager] · `.accountant_approved_by` [Accountant] · `.admin_approved_by` [CEO]
- `daily_reports.supervisor_id` [Manager/CEO]
- `petty_cash_issuances.issued_by` [CEO] · `.issued_to` [Accountant]
- `petty_cash_expenses.approved_by` [Accountant/Manager]
- `audit_logs.actor_id` [any] — *SET NULL on user deletion*

---

## 2. `processes` — production pipeline stages

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| name | VARCHAR(150) | NO | | | Seeded order matters: **1** Rounding · **2** Sanding · **3** P.V.C (K Line) · **4** P.V.C (O Line) · **5** Cups · **6** Packaging / Sewing |
| description | TEXT | YES | | NULL | |
| status | VARCHAR(20) | NO | | `Active` | |

**Referenced by:** `machines.process_id`, `process_reject_logs.process_id`.

**Business meaning of id 5 (Cups):** the completion stage — units logged here are **Completed Goods** (finished broom sticks are counted at Cups). Units logged at ids 1–4 are **In-Process**. Stage 6 consumes completed sticks into bundles (packaging/sewing, machine or hand).

---

## 3. `machines` — machine registry

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| code | VARCHAR(30) | NO | **UNI** | | `R1, R2, S1, S2, K1, O1` |
| name | VARCHAR(150) | NO | | | |
| process_id | INT UNSIGNED | NO | **FK → processes.id** | | The stage this machine performs |
| status | VARCHAR(20) | NO | | `Operational` | `Operational` \| `Maintenance` \| `Down` |

**Referenced by:** `daily_reports.machine_id`.

---

## 4. `daily_reports` — shift production headers

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| report_date | DATE | NO | | | Cannot be in the future (validated) |
| shift | VARCHAR(60) | NO | | | `Morning (06:00 - 14:00)` \| `Afternoon (14:00 - 22:00)` \| `Night (22:00 - 06:00)` |
| supervisor_id | INT UNSIGNED | YES | **FK → users.id** | NULL | Filing Manager/CEO |
| machine_id | INT UNSIGNED | YES | **FK → machines.id** | NULL | Machine used |
| units_produced | INT UNSIGNED | NO | | 0 | **"Units Processed"** (gross input at that stage) |
| good_units | INT UNSIGNED | NO | | 0 | **DERIVED** = units − partial − scrap (never user-typed; the old "Good Units Passed QA" field was removed) |
| supervisor_notes | TEXT | YES | | NULL | |
| created_at | DATETIME | NO | | current_timestamp() | |

**References:** users, machines. **Referenced by:** `process_reject_logs.report_id`.

---

## 5. `process_reject_logs` — reject breakdown (1 : 0..1 with daily_reports)

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| report_id | INT UNSIGNED | NO | **FK → daily_reports.id** | | One breakdown per report |
| process_id | INT UNSIGNED | YES | **FK → processes.id** | NULL | Stage where rejects occurred (drives Completed-Goods vs In-Process tag) |
| partial_reject_count | INT UNSIGNED | NO | | 0 | Reworkable at the same stage |
| total_reject_count | INT UNSIGNED | NO | | 0 | Scrapped — total loss |
| reject_reason | VARCHAR(255) | NO | | | e.g. burr formation, out-of-spec dimension |
| root_cause | VARCHAR(255) | YES | | NULL | e.g. tool punch wear, coolant failure |

**Rule:** `partial + total ≤ daily_reports.units_produced` (validated before the transactional insert).

---

## 6. `procurement_entries` — records & the two-gate approval

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| reference_no | VARCHAR(30) | NO | **UNI** | | `PRC-YYYY-NNNN` (auto-issued) |
| submitted_by | INT UNSIGNED | YES | **FK → users.id** | NULL | Procurement Officer |
| supplier | VARCHAR(150) | NO | | | |
| item_name | VARCHAR(255) | NO | | | |
| category | VARCHAR(60) | NO | | | |
| quantity | DECIMAL(12,2) | NO | | 0.00 | |
| unit | VARCHAR(30) | NO | | | pcs, kg, box … |
| unit_cost | DECIMAL(14,2) | NO | | 0.00 | |
| total_cost | DECIMAL(14,2) | NO | | 0.00 | **Computed** = quantity × unit_cost |
| status | VARCHAR(40) | NO | | `Pending Approval`* | *New inserts use `Pending Manager Review`. Full lifecycle below. |
| manager_approved_by | INT UNSIGNED | YES | **FK → users.id** | NULL | 1st gate |
| manager_approved_at | DATETIME | YES | | NULL | |
| manager_notes | TEXT | YES | | NULL | |
| accountant_approved_by | INT UNSIGNED | YES | **FK → users.id** | NULL | 2nd gate — **final approver** |
| accountant_approved_at | DATETIME | YES | | NULL | |
| accountant_notes | TEXT | YES | | NULL | |
| admin_approved_by | INT UNSIGNED | YES | **FK → users.id** | NULL | CEO-override column (reserved path) |
| admin_approved_at | DATETIME | YES | | NULL | |
| admin_notes | TEXT | YES | | NULL | |
| rejection_reason | TEXT | YES | | NULL | Set when rejected at either gate |
| date | DATE | NO | | | Procurement date entered by officer |
| created_at | DATETIME | NO | | current_timestamp() | |

**Status lifecycle (exactly as enforced in `procurement.php`):**

```
Pending Manager Review ──manager approves──▶ Pending Accountant Review ──accountant approves──▶ Finalized (locked)
        │                                            │
        └────────────── reject ──────────────────────┴──────────────▶ Rejected (terminal)
```

Guards: Officer has no approve action at all · Accountant refuses anything not in *Pending Accountant Review* (no stage skipping) · Finalized/Rejected rows are immutable.

---

## 7. `petty_cash_issuances` — float vouchers

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| voucher_no | VARCHAR(30) | NO | **UNI** | | `PV-YYYY-NNNN` (auto-issued) |
| issued_to | INT UNSIGNED | YES | **FK → users.id** | NULL | **Sole holder: an active Accountant** (server-checked) |
| issued_by | INT UNSIGNED | YES | **FK → users.id** | NULL | CEO (only role shown the issue form) |
| amount | DECIMAL(14,2) | NO | | 0.00 | Must be **800,000 – 7,000,000 TZS** (server-checked) |
| purpose | VARCHAR(255) | NO | | | |
| status | VARCHAR(20) | NO | | `Active` | `Active` \| `Closed` |
| issued_date | DATE | NO | | | |
| created_at | DATETIME | NO | | current_timestamp() | |

**Referenced by:** `petty_cash_expenses.issuance_id`. **Balance:** `amount − Σ(expenses.amount)` is always computed live, never stored.

---

## 8. `petty_cash_expenses` — expense ledger

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| issuance_id | INT UNSIGNED | NO | **FK → petty_cash_issuances.id** | | Which float was spent |
| expense_date | DATE | NO | | | |
| category | VARCHAR(60) | NO | | | e.g. Shop Consumables, Equipment, Transport |
| description | VARCHAR(255) | NO | | | |
| amount | DECIMAL(14,2) | NO | | 0.00 | |
| receipt_no | VARCHAR(60) | NO | | | Physical receipt reference |
| approved_by | INT UNSIGNED | YES | **FK → users.id** | NULL | Recording officer (Accountant/Manager) |
| created_at | DATETIME | NO | | current_timestamp() | |

---

## 9. `audit_logs` — immutable audit trail

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| actor_id | INT UNSIGNED | YES | **FK → users.id** | NULL | **SET NULL on user delete** — history survives account removal |
| action | VARCHAR(60) | NO | | | e.g. `LOGIN_SUCCESS`, `USER_CREATED`, `FLOAT_ISSUED`, `PROCUREMENT_FINALIZED`, `REPORT_GENERATED` |
| entity_type | VARCHAR(40) | NO | | | Coarse target, e.g. `USER`, `REPORT`, `PROCUREMENT_ENTRY` |
| entity_id | VARCHAR(60) | NO | | | Target id or key |
| details | TEXT | NO | | | Human-readable event description |
| timestamp | DATETIME | NO | | current_timestamp() | |

**Write-only by design:** the application only ever INSERTs here; there is no UPDATE/DELETE code path (reporting reads it for the CEO-only Audit Trail report/page).

---

## 10. Complete FK Cross-Reference

```
audit_logs.actor_id                → users.id            (SET NULL)
daily_reports.machine_id           → machines.id
daily_reports.supervisor_id        → users.id
machines.process_id                → processes.id
petty_cash_expenses.approved_by    → users.id
petty_cash_expenses.issuance_id    → petty_cash_issuances.id
petty_cash_issuances.issued_by     → users.id
petty_cash_issuances.issued_to     → users.id
process_reject_logs.process_id     → processes.id
process_reject_logs.report_id      → daily_reports.id
procurement_entries.accountant_approved_by → users.id
procurement_entries.admin_approved_by      → users.id
procurement_entries.manager_approved_by    → users.id
procurement_entries.submitted_by           → users.id
```
*(13 foreign keys — all indexed; verified against `information_schema.KEY_COLUMN_USAGE`.)*
