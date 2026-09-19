# 04 — Table Reference & Relations

> Column-for-column reference for the **30 tables** in the live schema, extracted from the
> deployed database (`SHOW COLUMNS` on `factory_db`; the identical schema is created on SQLite
> by `includes/migrations.php`). The 9 original tables are marked ⭕, the v2.3 tables 🆕.

## Quick Map

| # | Table | Purpose | Primary actors |
|---|---|---|---|
| 1 | ⭕ `users` | Accounts & roles (5 roles) | everyone |
| 2 | ⭕ `processes` | 6-stage production pipeline | reference data |
| 3 | ⭕ `machines` | Machine registry per stage | reference data |
| 4 | ⭕ `daily_reports` | Shift production headers + verification gate | Supervisor logs, Manager verifies |
| 5 | ⭕ `process_reject_logs` | Reject breakdown per report | Supervisor |
| 6 | ⭕ `procurement_entries` | Procurement records + requisition + final approval | Officer, CEO, Manager |
| 7 | ⭕ `petty_cash_issuances` | Float vouchers (single holder) | CEO issues, Accountant holds |
| 8 | ⭕ `petty_cash_expenses` | Expense ledger (two signatures) | Accountant, Manager/CEO |
| 9 | ⭕ `audit_logs` | Hash-chained, tamper-evident trail | system |
| 10 | 🆕 `inventory_items` | Stock master (materials + finished goods) | Officer |
| 11 | 🆕 `inventory_transactions` | Movement ledger (IN/OUT/ADJUST) | system on behalf of Officer |
| 12 | 🆕 `inventory_requests` | Supervisor material requests | Supervisor → Manager → Officer |
| 13 | 🆕 `stock_receipts` | Goods-in ledger per batch | Officer |
| 14 | 🆕 `stock_issues` | Materials-out ledger per batch | Officer |
| 15 | 🆕 `material_batches` | Batch identity for received materials | Officer |
| 16 | 🆕 `cash_requests` | The cash pipeline (request → CEO → Accountant → confirm) | Officer/Manager, CEO, Accountant |
| 17 | 🆕 `shipment_orders` | Finished-product shipment lifecycle | CEO → Officer → Manager |
| 18 | 🆕 `machine_failures` | Failure reports + Manager verification | Supervisor, Manager |
| 19 | 🆕 `electricity_readings` | Power usage per shift | Supervisor |
| 20 | 🆕 `machine_downtime` | Downtime minutes per machine | Supervisor/Manager |
| 21 | 🆕 `shift_targets` | Target output per stage | Manager |
| 22 | 🆕 `correction_requests` | Supervised corrections | any user → CEO |
| 23 | 🆕 `notifications` | Per-user alert queue | system |
| 24 | 🆕 `workers` | Workforce register + biometric templates | Manager/CEO |
| 25 | 🆕 `worker_attendance` | Check-in/out per worker | device or manual |
| 26 | 🆕 `petty_cash_requests` | Accountant's float top-up requests | Accountant → CEO |
| 27 | 🆕 `customers` | Sales counterparties | Manager/CEO |
| 28 | 🆕 `dispatches` | Sales dispatch ledger | Manager/CEO |
| 29 | 🆕 `delegations` | Deputy coverage windows | CEO |
| 30 | 🆕 `attachments` | File attachments on records | any role (scoped) |

Plus `app_settings` (key-value configuration store).

---

## 1. ⭕ `users` — accounts & roles

| Column | Type | Null | Key | Default | Notes |
|---|---|:-:|:-:|---|---|
| id | INT UNSIGNED | NO | **PK** | auto_increment | |
| name | VARCHAR(120) | NO | | | Full display name — **CAPITALS + letters only** (validated client & server) |
| username | VARCHAR(60) | NO | **UNI** | | Login handle |
| password_hash | VARCHAR(255) | NO | | | bcrypt; new passwords ≥ 12 chars |
| password_encrypted | VARBINARY(512) | YES | | NULL | Legacy vault copy |
| role | VARCHAR(40) | NO | | | `CEO` \| `Manager` \| `Accountant` \| `Procurement Officer` \| `Supervisor` |
| status | VARCHAR(20) | NO | | `Active` | `Active` \| `Banned` |
| failed_attempts | INT | NO | | 0 | Lockout counter |
| locked_until | DATETIME | YES | | NULL | 5 failures ⇒ 1-day lock |
| must_change_password | TINYINT | NO | | 0 | Forced change at next sign-in |
| password_changed_at | DATETIME | YES | | NULL | |
| created_at | DATETIME | NO | | current_timestamp() | |

**Referenced by:** 20+ "who did this" columns across the schema (see §10).

---

## 2–3. ⭕ `processes` and `machines` — production reference data

`processes`: id, name, description, status. Seeded order defines the pipeline: **1** Rounding · **2** Sanding · **3** P.V.C (K Line) · **4** P.V.C (O Line) · **5** Cups (completion stage — units here are *Completed Goods*) · **6** Packaging / Sewing (bundles made, machine or hand).

`machines`: id, code (**UNI**: R1, R2, S1, S2, K1, O1), name, process_id **FK → processes.id**, status (`Operational`/`Maintenance`/`Down`).

---

## 4. ⭕ `daily_reports` — shift production headers (now with verification)

| Column | Type | Null | Key | Notes |
|---|---|:-:|:-:|---|
| id | INT UNSIGNED | NO | **PK** | |
| report_date | DATE | NO | | Future dates rejected |
| shift | VARCHAR(60) | NO | | Morning / Afternoon / Night |
| supervisor_id | INT UNSIGNED | YES | **FK → users.id** | The **logger** (Supervisor, or CEO as owner) |
| machine_id | INT UNSIGNED | YES | **FK → machines.id** | |
| units_produced | INT UNSIGNED | NO | | Gross "Units Processed" |
| good_units | INT UNSIGNED | NO | | **DERIVED** = units − partial − scrap (never typed) |
| supervisor_notes | TEXT | YES | | |
| approval_status | VARCHAR(30) | NO | | `Pending Verification` → `Verified` (Manager) |
| approved_by | INT UNSIGNED | YES | **FK → users.id** | The **verifier** (Manager) — separate column from the logger |
| approved_at | DATETIME | YES | | |
| approval_notes | TEXT | YES | | |
| created_at | DATETIME | NO | | |

**Rule:** only `Verified` rows feed dashboards, KPIs and reports. The Manager verifies but never logs; every logged row — CEO's included — passes the Manager's gate.

---

## 5. ⭕ `process_reject_logs` — reject breakdown (1 : 0..1 with daily_reports)

id **PK** · report_id **FK → daily_reports.id** · process_id **FK → processes.id** · partial_reject_count (reworkable) · total_reject_count (scrapped) · reject_reason · root_cause.

**Rule:** `partial + total ≤ units_produced`, validated before the transactional insert.

---

## 6. ⭕ `procurement_entries` — requisition gate + final approval

| Column | Type | Null | Key | Notes |
|---|---|:-:|:-:|---|
| id | INT UNSIGNED | NO | **PK** | |
| reference_no | VARCHAR(30) | NO | **UNI** | `PRC-YYYY-NNNN` |
| submitted_by | INT UNSIGNED | YES | **FK → users.id** | Officer |
| supplier / item_name / category | VARCHAR | NO | | |
| quantity / unit / unit_cost | DECIMAL/VARCHAR | NO | | |
| total_cost | DECIMAL(14,2) | NO | | **Computed** = qty × unit_cost |
| requisition_status | VARCHAR(30) | NO | | `Pending` → `Approved` / `Rejected` — CEO's gate |
| requisition_ceo_id | INT UNSIGNED | YES | **FK → users.id** | CEO who decided the requisition |
| requisition_ceo_at | DATETIME | YES | | |
| requisition_notes | VARCHAR(255) | YES | | |
| status | VARCHAR(40) | NO | | `Pending Manager Review` → `Finalized` / `Rejected` |
| manager_approved_by | INT UNSIGNED | YES | **FK → users.id** | **FINAL gate** (no Accountant gate exists) |
| manager_approved_at / manager_notes | — | YES | | |
| admin_approved_by/_at/_notes | — | YES | | CEO-override columns (owner path) |
| inventory_received | TINYINT | NO | | 0 | **Once-only** goods-receipt guard |
| rejection_reason | TEXT | YES | | |
| date / created_at | DATE/DATETIME | NO | | |

**Lifecycle:**

```
Requisition:  Pending ──CEO approves──▶ Approved ──▶ Officer may procure
                     └──CEO rejects──▶ Rejected (terminal)

Record:       Pending Manager Review ──manager approves──▶ Finalized (locked)
                          └──reject──▶ Rejected
Finalized + goods received ──▶ inventory_received = 1 (cannot receive twice)
```

Payment is **not** in this table — it flows through `cash_requests` (§16).

---

## 7–8. ⭕ `petty_cash_issuances` and `petty_cash_expenses` — the float

`petty_cash_issuances`: voucher_no **UNI** `PV-YYYY-NNNN` · issued_to **FK** (an **active Accountant** — sole holder, server-checked) · issued_by **FK** (CEO) · amount **800,000–7,000,000 TZS** · purpose · status `Active`/`Closed` · issued_date · closed_at/closed_by **FK**/close_note · countersigned_by **FK**/countersigned_at · batch_id **FK** (optional material-batch link) · created_at.

`petty_cash_expenses`: issuance_id **FK** · expense_date · category · description · amount · receipt_no (**duplicate-guarded per float**) · approved_by **FK** (the Accountant records) · confirmed_by **FK**/confirmed_at (**two-signature confirm** — CEO or Manager) · created_at.

Balance is always computed live: `amount − Σ(expenses.amount)` — never stored, never stale.

---

## 9. ⭕ `audit_logs` — sealed, tamper-evident trail

| Column | Type | Null | Key | Notes |
|---|---|:-:|:-:|---|
| id | INT UNSIGNED | NO | **PK** | |
| actor_id | INT UNSIGNED | YES | | Nullable — rows survive user deletion detached (no destructive FK) |
| action | VARCHAR(60) | NO | | e.g. `LOGIN_SUCCESS`, `CASH_REQUEST_APPROVED`, `CORRECTION_APPLIED` |
| entity_type / entity_id | VARCHAR | NO | | Coarse target |
| details | TEXT | NO | | Human-readable event |
| ip_address / user_agent | VARCHAR | YES | | Request context |
| prev_hash | VARCHAR(64) | NO | | Chain link to previous row |
| row_hash | VARCHAR(64) | NO | | SHA-256 seal over row contents |
| timestamp | DATETIME | NO | | |

**Write-only by design;** a live verifier re-computes the chain and shows a "chain verified" banner — any tampering breaks `prev_hash → row_hash`.

---

## 10–15. 🆕 Inventory tables

**`inventory_items`**: id **PK** · item_code **UNI** · item_name · unit · quantity · reorder_level (low-stock alert threshold) · unit_cost · is_finished_goods (finished products vs materials) · status · created_by **FK → users.id** · created_at.

**`inventory_transactions`**: item_id **FK** · txn_type (`IN`/`OUT`/`ADJUST`) · quantity · reference · note · performed_by **FK** · txn_date — every quantity change writes a row here in the same transaction, so stock never drifts silently.

**`inventory_requests`**: request_no **UNI** `MR-YYYY-NNNN` · item_id **FK** · quantity · purpose · status (`Pending Manager Approval` → `Approved` → `Released` → `Received`, or `Rejected`) · requested_by **FK** (Supervisor) · manager_decision_by **FK**/at/notes · released_by **FK**/at (Officer; stock deducted here) · received_confirmed_by **FK**/at (Supervisor) · created_at. A request above available stock fires a shortage alert **before** approval.

**`stock_receipts`**: batch_id **FK** · procurement_id **FK** · quantity · unit · receipt_date · received_by **FK** · note.
**`stock_issues`**: batch_id **FK** · quantity · unit · issue_date · issued_to_process **FK** · issued_by **FK** · note.
**`material_batches`**: batch_code **UNI** · procurement_id **FK** · material_name · quantity · unit · received_date · received_by **FK**.

---

## 16. 🆕 `cash_requests` — the only road to money

| Column | Type | Key | Notes |
|---|---|---|---|
| id | INT UNSIGNED | **PK** | |
| request_no | VARCHAR(30) | **UNI** | `CR-YYYY-NNNN` |
| requested_by | INT UNSIGNED | **FK → users.id** | Officer **or** Manager |
| requester_role | VARCHAR(40) | | Snapshot for the queue |
| amount / purpose | — | | |
| status | VARCHAR(40) | | `Pending CEO Approval` → `Approved` → `Disbursed` → `Received`, or `Rejected` |
| ceo_decision_by / _at / ceo_notes | — | **FK** | **Sole approver: CEO** |
| disbursed_by / disbursed_at | — | **FK** | **Accountant only** (role re-checked server-side) |
| confirmed_received_by / _at | — | **FK** | Requester confirms — closes the loop |
| created_at | DATETIME | | |

The CEO's approval **auto-routes** the request to the Accountant's queue with a notification; double-press protection prevents duplicate payouts.

---

## 17. 🆕 `shipment_orders` — finished products to market

shipment_no **UNI** `SH-YYYY-NNNN` · destination · product_item_id **FK → inventory_items.id** (finished good) · bundles · units · status (`Requested` → `Prepared` → `Approved` → dispatched, or `Rejected`) · requested_by_ceo **FK** · prepared_by_po **FK**/at/notes · approved_by_manager **FK**/at/notes · dispatched_at · created_at.

Approval and dispatch happen in one step (Manager) and deduct finished stock.

---

## 18–21. 🆕 Floor tables

**`machine_failures`**: machine_id **FK** · reported_by **FK** (Supervisor) · failure_reason · reported_at · status (Pending/Verified) · verified_by **FK** (Manager — authenticity gate) · verified_at · manager_notes · resolved_at.

**`electricity_readings`**: reading_date · shift · meter_kwh · units_produced · notes · logged_by **FK** (Supervisor).

**`machine_downtime`**: machine_id **FK** · report_date · shift · started_at · ended_at · minutes · reason · recorded_by **FK**.

**`shift_targets`**: process_id **FK** · effective_from · target_per_shift · created_by **FK** (Manager).

---

## 22. 🆕 `correction_requests` — mistakes fixed under supervision

| Column | Notes |
|---|---|
| entity_type | Target table: `production_logs`, `electricity_readings`, `petty_cash_expenses`, … |
| entity_id | Target row |
| correction_type | `Edit` / `Delete` |
| reason | Why the record was wrong (required) |
| requested_by **FK** | Any authenticated user |
| status | `Pending CEO Approval` → `Approved - Awaiting Application` → `Applied`, or `Denied` |
| decided_by **FK** / ceo_notes | CEO decision |
| applied_by **FK** / applied_at | The **requester** applies |

**Guard:** the application step enforces a **field whitelist** — the requester can change only what they selected and the CEO approved; old/new values land in the sealed audit log.

---

## 23–25. 🆕 Notifications and the workforce

**`notifications`**: user_id **FK** · title · body · link · is_read · created_at. The header bell and panel show unread counts; **opening a notification marks it read** and jumps to its target link.

**`workers`**: full_name (CAPITALS validated) · staff_no **UNI** · department · fingerprint_template (text) · face_template (text) · biometric_enrolled · enrolled_by **FK** (Manager **or** CEO) · status · created_at.

**`worker_attendance`**: worker_id **FK** · attend_date · check_in · check_out · method (`biometric`/`manual`) · device_info. Biometric devices POST to `api_biometric.php` (key-authenticated); manual check-in works until hardware is purchased.

---

## 26–30. 🆕 Support tables

**`petty_cash_requests`** — the Accountant's float top-ups: requested_by **FK** · amount · purpose · status · decided_by **FK** (CEO) · created_at.

**`customers`**: name · phone · address · is_active · created_at.

**`dispatches`**: dispatch_no · customer_id **FK** · bundles · units · unit_price · total_amount · amount_paid · dispatch_date · recorded_by **FK** — the sales ledger (distinct from `shipment_orders`, which is the internal factory dispatch workflow).

**`delegations`**: from_user_id **FK** · to_user_id **FK** · role_scope · date_from · date_to · created_by **FK** — deputy coverage; the single-cash-holder rule is never broken by a delegation.

**`attachments`**: entity_type · entity_id · file_name · stored_name · mime_type · size_bytes · uploaded_by **FK** · created_at.

**`app_settings`**: skey **UNI** · svalue.

---

## 10. Complete FK Cross-Reference (groups)

```
users →  procurement_entries (submitted_by, requisition_ceo_id, manager_approved_by, admin_approved_by)
      →  daily_reports (supervisor_id logger, approved_by verifier)
      →  inventory_items.created_by, inventory_transactions.performed_by
      →  inventory_requests (requested_by, manager_decision_by, released_by, received_confirmed_by)
      →  cash_requests (requested_by, ceo_decision_by, disbursed_by, confirmed_received_by)
      →  shipment_orders (requested_by_ceo, prepared_by_po, approved_by_manager)
      →  correction_requests (requested_by, decided_by, applied_by)
      →  machine_failures (reported_by, verified_by), electricity_readings.logged_by,
         machine_downtime.recorded_by, shift_targets.created_by
      →  petty_cash_issuances (issued_by, issued_to, closed_by, countersigned_by)
      →  petty_cash_expenses (approved_by, confirmed_by), petty_cash_requests (requested_by, decided_by)
      →  workers.enrolled_by, stock_receipts.received_by, stock_issues.issued_by,
         dispatches.recorded_by, attachments.uploaded_by, delegations (from_user_id, to_user_id)
      →  audit_logs.actor_id (nullable, detached rows survive)

processes → machines.process_id, process_reject_logs.process_id, shift_targets.process_id
machines  → daily_reports.machine_id, machine_failures.machine_id, machine_downtime.machine_id
daily_reports → process_reject_logs.report_id
procurement_entries → stock_receipts.procurement_id, material_batches.procurement_id
material_batches → stock_issues.batch_id, stock_receipts.batch_id, petty_cash_issuances.batch_id
inventory_items → inventory_transactions.item_id, inventory_requests.item_id,
                  shipment_orders.product_item_id, stock_issues (issued_to_process)
petty_cash_issuances → petty_cash_expenses.issuance_id
customers → dispatches.customer_id
workers → worker_attendance.worker_id
```

*(All foreign keys are indexed; verified against the live schema.)*
