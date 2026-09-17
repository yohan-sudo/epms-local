<div align="center">

# U EPMS — Enterprise Plant Monitoring System

**Role-based ERP for a broom-stick factory** — procurement, production, petty cash & audit under one roof.

Pure PHP 8.x • Plain HTML5/CSS3 (zero frameworks) • MySQL (localhost) or SQLite fallback • Currency: **TZS**

**[📖 Use Cases](docs/01-use-case-diagram.md) · [🔀 Data Flow Diagrams](docs/02-data-flow-diagrams.md) · [🗄️ ERD](docs/03-entity-relationship-diagram.md) · [📋 Table Reference](docs/04-table-reference.md)** — all diagrams below render inline.

</div>

---

## 📐 System Diagrams

### Use Case Diagram

Four human roles plus an automated audit system. The **CEO (Owner)** inherits every role's
capabilities as override authority; the **Procurement Officer submits but never approves**.

```mermaid
graph LR
    subgraph UEPMS["U EPMS - Enterprise Plant Monitoring System"]
        UC1(["Log In / Log Out"])
        UC2(["View Personal Dashboard"])

        subgraph Admin["Administration - CEO only"]
            UC10(["Manage User Accounts"])
            UC12(["Configure System Settings"])
            UC13(["Review Immutable Audit Trail"])
            UC14(["Issue Petty-Cash Float<br/>800k to 7M TZS, Accountant only"])
        end

        subgraph Prod["Production"]
            UC20(["File Shift Production Report"])
            UC21(["Classify Units:<br/>In-Process vs Completed Goods"])
            UC22(["Log Rejects, Reasons and Root Causes"])
        end

        subgraph Proc["Procurement: Submit, then Manager, then Accountant"]
            UC30(["Submit Procurement Record"])
            UC31(["Approve or Reject<br/>1st line (Manager)"])
            UC32(["Final-Approve or Reject<br/>locks record (Accountant)"])
        end

        subgraph Cash["Petty Cash"]
            UC40(["Record Expense Against Float"])
            UC41(["View Float Balances and Ledger"])
        end

        UC50(["Search and Date-Filter Records"])
        UC60(["Generate Report - HTML / PDF / Excel"])
    end

    CEO(["CEO (Owner)"]) --> UC1
    MGR(["Manager"]) --> UC1
    ACC(["Accountant"]) --> UC1
    PO(["Procurement Officer"]) --> UC1

    CEO --> UC10
    CEO --> UC12
    CEO --> UC13
    CEO --> UC14
    CEO -.->|"inherits all role abilities"| UC20
    CEO -.-> UC31
    CEO -.-> UC32

    MGR --> UC20
    MGR --> UC31
    MGR --> UC40

    ACC --> UC32
    ACC --> UC40
    ACC --> UC41

    PO --> UC30

    UC1 --> UC2
    UC20 -.->|"include"| UC21
    UC20 -.->|"include"| UC22
    UC14 -.->|"include"| UC41
    UC40 -.->|"include"| UC41
    UC60 -.->|"extend"| UC50

    SYS(["System (Audit Logger)"]) -.->|"records every event"| UC1
    SYS -.-> UC10
    SYS -.-> UC31
    SYS -.-> UC32
    SYS -.-> UC60
```

### Data Flow Diagrams

**Level 0 (Context)** — the system boundary:

```mermaid
graph TB
    CEO["CEO (Owner)"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]
    P0(("0<br/>U EPMS<br/>Enterprise Plant<br/>Monitoring System"))

    CEO -->|"credentials, user mgmt, settings, float issuance, audit queries"| P0
    P0 -->|"admin dashboards, audit trail, reports (HTML / PDF / XLSX)"| CEO
    MGR -->|"shift production reports, 1st-line approvals, expenses"| P0
    P0 -->|"worklists, KPI dashboards, ledgers"| MGR
    ACC -->|"final procurement approvals, expenses"| P0
    P0 -->|"approval worklists, float balances"| ACC
    PO -->|"procurement submissions"| P0
    P0 -->|"submission portal, status tracking"| PO
```

**Level 1** — major subsystems mapped to real pages and tables (D# = MySQL table in `factory_db`):

```mermaid
graph TB
    CEO["CEO"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]

    P1(("1 Authentication<br/>index.php / auth.php"))
    P2(("2 Dashboard<br/>dashboard.php"))
    P3(("3 Production Logging<br/>production.php"))
    P4(("4 Procurement and Approvals<br/>procurement.php"))
    P5(("5 Petty Cash<br/>petty_cash.php"))
    P6(("6 User and Settings Admin<br/>users.php / settings.php"))
    P7(("7 Reporting Engine<br/>reports.php"))
    P8(("8 Audit Logging<br/>logAudit()"))

    D1[("D1 users")]
    D2[("D2 audit_logs")]
    D3[("D3 procurement_entries")]
    D4[("D4 daily_reports")]
    D5[("D5 process_reject_logs")]
    D6[("D6 processes")]
    D7[("D7 machines")]
    D8[("D8 petty_cash_issuances")]
    D9[("D9 petty_cash_expenses")]

    CEO -->|"credentials"| P1
    MGR -->|"credentials"| P1
    ACC -->|"credentials"| P1
    PO -->|"credentials"| P1
    P1 -->|"verify password, role, status"| D1

    MGR -->|"shift report"| P3
    P3 --> D4
    P3 --> D5
    D6 --> P3
    D7 --> P3

    PO -->|"submission"| P4
    MGR -->|"gate decisions"| P4
    ACC -->|"gate decisions"| P4
    P4 --> D3

    CEO -->|"issue float"| P5
    MGR -->|"expenses"| P5
    ACC -->|"expenses"| P5
    P5 --> D8
    P5 --> D9

    CEO -->|"user CRUD, settings"| P6
    P6 --> D1

    CEO -->|"report requests"| P7
    MGR -->|"report requests"| P7
    ACC -->|"report requests"| P7
    PO -->|"report requests"| P7
    P7 -->|"HTML / PDF / XLSX"| CEO
    P7 --> MGR
    P7 --> ACC
    P7 --> PO

    P3 -->|"events"| P8
    P4 -->|"events"| P8
    P5 -->|"events"| P8
    P6 -->|"events"| P8
    P7 -->|"events"| P8
    P8 --> D2

    MGR --> P2
    ACC --> P2
    PO --> P2
    CEO --> P2
    P2 -->|"role-scoped KPIs and worklists"| MGR
    P2 --> ACC
    P2 --> PO
    P2 --> CEO
```

### Entity-Relationship Diagram

13 foreign keys across 9 tables — the `users` table is the hub every "who did this" points at.

```mermaid
erDiagram
    users ||--o{ procurement_entries : "submits and approves"
    users ||--o{ daily_reports : "supervisor_id"
    users ||--o{ petty_cash_issuances : "issued_by and issued_to"
    users ||--o{ petty_cash_expenses : "approved_by"
    users ||--o{ audit_logs : "actor_id, SET NULL on delete"

    processes ||--o{ machines : "process_id"
    machines ||--o{ daily_reports : "machine_id"
    daily_reports ||--o| process_reject_logs : "report_id"
    processes ||--o{ process_reject_logs : "process_id"
    petty_cash_issuances ||--o{ petty_cash_expenses : "issuance_id"

    users {
        int id PK
        varchar username UK
        varchar password_hash "bcrypt"
        varchar role "CEO / Manager / Accountant / Procurement Officer"
        varchar status "Active or Banned"
    }
    processes {
        int id PK
        varchar name "Rounding to Sanding to P.V.C to Cups to Packaging"
    }
    machines {
        int id PK
        varchar code UK "R1 R2 S1 S2 K1 O1"
        int process_id FK
    }
    daily_reports {
        int id PK
        date report_date
        varchar shift
        int units_produced "Units Processed gross"
        int good_units "DERIVED: units minus rejects"
    }
    process_reject_logs {
        int report_id FK
        int process_id FK
        int partial_reject_count "reworkable"
        int total_reject_count "scrapped"
    }
    procurement_entries {
        int id PK
        varchar reference_no UK "PRC-YYYY-NNNN"
        varchar status "Pending Manager Review then Pending Accountant Review then Finalized"
        decimal total_cost "qty x unit_cost"
    }
    petty_cash_issuances {
        int id PK
        varchar voucher_no UK "PV-YYYY-NNNN"
        decimal amount "800,000 to 7,000,000 TZS"
        varchar status "Active or Closed"
    }
    petty_cash_expenses {
        int issuance_id FK
        decimal amount
        varchar receipt_no
    }
    audit_logs {
        int actor_id FK "nullable"
        varchar action
        varchar entity_type
        varchar entity_id
        text details
    }
```

> Full-size diagrams, step-numbered Level-2 DFDs (procurement gates, petty cash, production,
> reporting), and complete use-case specifications live in the
> [`docs/`](docs/) folder.

---

## 🔁 Core Business Flows

**Procurement (two-gate, immutable once finalized):**

```
Procurement Officer            Manager                      Accountant
      │                          │                              │
      │ submit (PRC-2026-XXXX)   │                              │
      ├─────────────────────────▶│  appears on dashboard        │
      │                          │ approve / reject             │
      │                          ├─────────────────────────────▶│ appears on dashboard
      │                          │                              │ FINAL approval (locks)
      │                          │                              ├─▶ Finalized 🔒
```
- The Officer has **no approve action at all** (server-enforced).
- The Accountant **cannot skip the Manager's gate** (status-checked server-side).
- Finalized/Rejected records are immutable.

**Production (6-stage broom-stick pipeline):**
Rounding (R1, R2) → Sanding (S1, S2) → P.V.C K Line (K1) → P.V.C O Line (O1) → **Cups** (by hand —
finished broom sticks are counted here) → Packaging/Sewing (machine or hand, bundled).
Units are logged as **Units Processed** per shift/machine; units at Cups are auto-classified
**Completed Goods**, earlier stages **In-Process**. Accepted units are **derived**
(units − reworkable − scrapped) — there is no manual QA field.

**Petty cash:** the CEO issues floats of **TZS 800,000 – 7,000,000**; the **Accountant is the sole
permitted float holder** (server-validated). Expenses are receipted against the float; the
remaining balance is always computed live (`amount − Σ expenses`).

**Reporting:** every role gets role-scoped report templates (Production, Procurement, Petty-Cash
Floats, Petty-Cash Expenses, Audit Trail — CEO only) with HTML preview and downloads:
- **PDF** — pure-PHP engine (no libraries): fully wrapped table cells (no truncation), row
  separators, zebra striping, totals row, page footers.
- **Excel (.xlsx)** — pure-PHP engine: merged title banner, content-sized columns, frozen header,
  auto-filter, banded rows, typed numeric/date cells, styled totals.

## 👥 Role & Permission Model

| Capability | Procurement Officer | Manager | Accountant | CEO |
|---|---|---|---|---|
| **Submit procurement records** | ✅ (only authority) | — | — | — |
| First procurement approval | — | ✅ (dashboard) | — | — |
| **Final procurement approval** | — | — | ✅ (dashboard, locks record) | — |
| View procurement records | ✅ | ✅ | ✅ | ✅ |
| Log production shift reports | — | ✅ | view-only | ✅ |
| **Record petty cash expenses** | — | ✅ | ✅ | — |
| **Issue new petty cash floats** | — | — | ❌ (holder, not issuer) | ✅ (only authority) |
| Machinery & process config | — | — | — | ✅ |
| **Audit trail** | ❌ | ❌ | ❌ | ✅ |
| Ban / delete users, password vault | — | — | — | ✅ |
| Reports | procurement only | role-scoped | role-scoped | all |

Deleting a user removes the account completely while preserving historical business records
(procurement entries, floats, expenses, reports) — their user references become NULL via
`ON DELETE SET NULL` foreign keys, and the audit trail stays intact.

## 🚀 Run Locally (XAMPP / localhost)

**Prerequisites:** PHP 8.x with `pdo_mysql` (XAMPP includes it), MySQL/MariaDB running.

1. Start **Apache/MySQL** in the XAMPP control panel (MySQL is required for the default config).
2. Configure the environment in `.env` (already set up for localhost XAMPP):

   ```env
   DB_DRIVER=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=factory_db
   DB_USER=root
   DB_PASS=
   ```

3. Create the database + seed data — either:
   - open **phpMyAdmin** → Import → choose `database.sql`, **or**
   - run `mysql -u root < database.sql`, **or**
   - just start the app: it auto-creates the schema and seeds demo data on first run.

4. Open the app — no terminal needed:
   - **XAMPP Apache (recommended):** start Apache in the XAMPP Control Panel, then browse to
     <http://localhost:3000>. (The folder ships with `apache-conf/httpd-uepms.conf` — if you ever
     reinstall XAMPP, copy it to `C:\xampp\apache\conf\extra\` and add
     `Include "conf/extra/httpd-uepms.conf"` to `httpd.conf`.)
   - **Built-in server alternative:** `php -S 127.0.0.1:3000 router.php` → <http://127.0.0.1:3000>

### SQLite fallback (zero configuration)

Set `DB_DRIVER=sqlite` in `.env` — the schema is created and seeded automatically at
`data/factory.db`. No MySQL needed.

## 🔑 Demo Accounts

Password for every seeded account: `factory123`

| User | Username | Role |
|---|---|---|
| BRIGHTON MMARI | `brighton_mmari` | CEO (Owner) |
| GLORY GEORGE | `glory_george` | Manager |
| SWAUMU MKOMWA | `swaumu_mkomwa` | Accountant |
| GLORIA MGASSA | `gloria_mgassa` | Procurement Officer |
| Victor Diaz | `victor_diaz` | Procurement Officer (**Banned** — login rejected) |

## 🔐 Password Vault (CEO)

On the **Users** page, the CEO can:

- **View Password** — reveals the account's current password (from the encrypted vault).
  Every reveal is written to the Audit Trail as `PASSWORD_VIEWED`.
- **Change Password** — sets a new password (min 6 characters) for any account.
  Logged in the Audit Trail as `PASSWORD_CHANGED`.

How it works: login verification always uses the one-way **bcrypt** hash (`password_hash`).
A second, **AES-256-GCM encrypted** copy of the password is stored in the
`users.password_encrypted` column so it can be recovered by the CEO. The key lives
in `APP_KEY` (`.env`) — generate one with `php -r "echo bin2hex(random_bytes(32));"`.
Changing `APP_KEY` makes all stored vault passwords unreadable (logins still work).

> **Security note:** storing reversible passwords weakens the security model (anyone with
> DB + `APP_KEY` access can read every password). This is intentional for this internal
> deployment; remove `view_password`/`change_password` and the `password_encrypted` column
> to revert to hash-only storage.

## 💱 Currency

All monetary amounts are stored and displayed in **Tanzanian Shillings (TZS)** — see
`formatMoney()` in `includes/functions.php` and the `APP_CURRENCY` constant in `config.php`.

## 🗄️ Database Schema

`database.sql` contains the complete MySQL schema (utf8mb4, InnoDB, foreign keys) plus seed data:

| Table | Purpose |
|---|---|
| `users` | RBAC directory (role, status: Active/Banned) |
| `processes`, `machines` | 6-stage pipeline + machine registry (R1…O1) |
| `procurement_entries` | records + two-gate approval columns (submit → manager → accountant) |
| `daily_reports`, `process_reject_logs` | shift production & defect breakdowns |
| `petty_cash_issuances`, `petty_cash_expenses` | float vouchers (TZS 800k–7M) & receipted expenses |
| `audit_logs` | immutable audit trail (CEO visibility) |

Column-by-column reference: [`docs/04-table-reference.md`](docs/04-table-reference.md).

## ✅ Tests

An end-to-end RBAC suite boots the PHP server and verifies every role boundary over HTTP —
**41 assertions** including procurement gate order, petty-cash holder/amount rules, the
nav-label regression, and PDF/XLSX generation:

```bash
bash tests/e2e_rbac_test.sh            # 41 RBAC assertions (needs MySQL running)
```

## 📝 Notes

- Sessions use plain cookies on `http://localhost` and secure `SameSite=None` cookies on
  HTTPS automatically (see `includes/session.php`).
- An immutable audit record is written for every security-relevant action (logins, bans,
  deletions, approvals, float issuance, expenses, machine changes, report generation).
- The login page intentionally ships **no** 1-click demo logins or password hints.
