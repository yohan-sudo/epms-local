# 02 — Data Flow Diagrams (DFD)

> Levels: **Context (Level 0)** → **Level 1** (major subsystems) → **Level 2** (the two multi-step flows: procurement approval, petty cash).
> Every process number maps to a real PHP page; every store (D#) maps to a real MySQL table in `factory_db`.

## 1. External Entities

| Entity | What it exchanges with the system |
|---|---|
| **CEO (Owner)** | Credentials, user-management commands, settings, float issuance, audit queries, report requests |
| **Manager** | Credentials, shift production reports, procurement 1st-line decisions, expenses, report requests |
| **Accountant** | Credentials, final procurement decisions, expenses, report requests |
| **Procurement Officer** | Credentials, procurement submissions, report requests |
| **(Actor roles arrive via one browser session; the system pushes back HTML pages, PDF/XLSX files, flash messages)** |

## 2. Level 0 — Context Diagram

```mermaid
graph TB
    CEO["CEO (Owner)"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]

    P0(("0<br/>U EPMS<br/>Enterprise Plant<br/>Monitoring System"))

    CEO -->|"credentials; user mgmt; settings; float issuance; audit queries; report requests"| P0
    P0 -->|"admin dashboards; registries; audit trail; reports (HTML / PDF / XLSX)"| CEO

    MGR -->|"credentials; shift production reports; procurement approvals; expenses"| P0
    P0 -->|"worklists; KPI dashboards; ledgers; reports"| MGR

    ACC -->|"credentials; final procurement approvals; expenses"| P0
    P0 -->|"approval worklists; float balances; reports"| ACC

    PO -->|"credentials; procurement submissions"| P0
    P0 -->|"submission portal; own-submission status; reports"| PO
```

## 3. Level 1 — Major Subsystems

Data stores (all MySQL, database `factory_db`):

- **D1 `users`** — accounts, roles, status
- **D2 `audit_logs`** — immutable event trail
- **D3 `procurement_entries`** — procurement records & approval state
- **D4 `daily_reports`** — shift production headers
- **D5 `process_reject_logs`** — reject breakdown per report
- **D6 `processes`** — 6-stage pipeline (Rounding → … → Packaging/Sewing)
- **D7 `machines`** — machines (R1, R2, S1, S2, K1, O1 …)
- **D8 `petty_cash_issuances`** — float vouchers
- **D9 `petty_cash_expenses`** — expenses against floats

```mermaid
graph TB
    CEO["CEO"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]

    P1(("1<br/>Authentication<br/>and Sessions<br/>index.php / auth.php"))
    P2(("2<br/>Dashboard<br/>Aggregation<br/>dashboard.php"))
    P3(("3<br/>Production<br/>Logging<br/>production.php"))
    P4(("4<br/>Procurement<br/>and Approvals<br/>procurement.php"))
    P5(("5<br/>Petty Cash<br/>petty_cash.php"))
    P6(("6<br/>User and Settings<br/>Admin<br/>users.php / settings.php"))
    P7(("7<br/>Reporting<br/>Engine<br/>reports.php"))
    P8(("8<br/>Audit<br/>Logging<br/>logAudit()"))

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
    P1 -->|"session; flash messages"| CEO
    P1 --> MGR
    P1 --> ACC
    P1 --> PO

    MGR --> P2
    ACC --> P2
    PO --> P2
    CEO --> P2
    P2 -->|"role-scoped KPIs and worklists"| MGR
    P2 --> ACC
    P2 --> PO
    P2 --> CEO
    P4 -->|"pending approvals"| P2

    MGR -->|"shift report: units, rejects, cause"| P3
    P3 -->|"header row"| D4
    P3 -->|"reject breakdown"| D5
    D6 -->|"process list (Cups = completion stage)"| P3
    D7 -->|"machine list"| P3

    PO -->|"procurement submission"| P4
    MGR -->|"1st-line approve/reject"| P4
    ACC -->|"final approve/reject"| P4
    P4 -->|"read and write records"| D3
    P4 -->|"audit event"| P8

    CEO -->|"issue float"| P5
    MGR -->|"expenses"| P5
    ACC -->|"expenses"| P5
    P5 -->|"read and write"| D8
    P5 -->|"read and write"| D9
    P5 -->|"audit event"| P8

    CEO -->|"user CRUD; settings"| P6
    P6 -->|"read and write accounts"| D1
    P6 -->|"audit event"| P8

    CEO -->|"report request (key, range, format)"| P7
    MGR -->|"report request (key, range, format)"| P7
    ACC -->|"report request (key, range, format)"| P7
    PO -->|"report request (key, range, format)"| P7
    P7 -->|"HTML preview; PDF; XLSX"| CEO
    P7 --> MGR
    P7 --> ACC
    P7 --> PO
    D3 -->|"role-filtered queries"| P7
    D4 --> P7
    D5 --> P7
    D8 --> P7
    D9 --> P7
    D1 --> P7
    D2 --> P7
    P7 -->|"audit event"| P8

    P8 --> D2
```

## 4. Level 2 — Procurement Approval Flow (process 4)

```mermaid
graph TB
    PO["Procurement Officer"]
    MGR["Manager"]
    ACC["Accountant"]
    CEO["CEO (override at any gate)"]

    P41(("4.1<br/>Validate and Price<br/>Submission"))
    P42(("4.2<br/>Register<br/>PRC-YYYY-NNNN"))
    P43(("4.3<br/>Manager<br/>Decision"))
    P44(("4.4<br/>Accountant<br/>Final Decision"))
    P45(("4.5<br/>Lock / Archive<br/>(immutable)"))

    D1[("D1 users")]
    D3[("D3 procurement_entries")]
    D2[("D2 audit_logs")]

    PO -->|"supplier, item, qty, unit cost"| P41
    P41 -->|"total = qty x cost; ref no."| P42
    P42 -->|"status = Pending Manager Review"| D3
    D3 -->|"worklist"| P43
    MGR -->|"approve + notes / reject + reason"| P43
    P43 -->|"status = Pending Accountant Review<br/>or Rejected"| D3
    D3 -->|"worklist"| P44
    ACC -->|"final approve + notes / reject"| P44
    P44 -->|"status = Finalized / Rejected"| D3
    D3 -->|"Finalized record"| P45
    P45 -->|"read-only forever"| D3
    P41 -->|"events"| D2
    P43 -->|"events"| D2
    P44 -->|"events"| D2
    P41 -.->|"resolve submitter id"| D1
    P43 -.->|"resolve approver id"| D1
    P44 -.->|"resolve approver id"| D1
    CEO -.->|"may act as Manager or Accountant gate"| P43
    CEO -.-> P44
```

**Guard conditions (server-enforced):**
1. Officer can only CREATE — no approve action exists for their session.
2. Accountant gate refuses records whose status ≠ *Pending Accountant Review* (no stage skipping).
3. *Finalized* or *Rejected* records are immutable; no UPDATE path exists.

## 5. Level 2 — Petty Cash Flow (process 5)

```mermaid
graph TB
    CEO["CEO"]
    MGR["Manager"]
    ACC["Accountant"]

    P51(("5.1<br/>Validate Float<br/>Request"))
    P52(("5.2<br/>Issue Voucher<br/>PV-YYYY-NNNN"))
    P53(("5.3<br/>Record Expense"))
    P54(("5.4<br/>Balance<br/>Computation"))
    P55(("5.5<br/>Close / Reconcile<br/>Float"))

    D1[("D1 users")]
    D8[("D8 petty_cash_issuances")]
    D9[("D9 petty_cash_expenses")]
    D2[("D2 audit_logs")]

    CEO -->|"holder (Accountant only), amount, purpose"| P51
    P51 -->|"check: amount between 800,000 and 7,000,000 TZS; holder role = Accountant, Active"| P52
    P52 -->|"voucher row (status Active)"| D8
    P52 -.->|"resolve issuer/holder ids"| D1
    MGR -->|"date, category, description, receipt no, amount"| P53
    ACC -->|"date, category, description, receipt no, amount"| P53
    P53 -->|"expense row"| D9
    D8 -->|"float id"| P53
    D9 -->|"sum of expenses, amount"| P54
    D8 -->|"sum of expenses, amount"| P54
    P54 -->|"remaining balance shown on ledgers and KPIs"| MGR
    P54 --> ACC
    P54 --> CEO
    P54 -->|"status = Closed when fully expensed / reconciled"| D8
    P51 -->|"events"| D2
    P52 -->|"events"| D2
    P53 -->|"events"| D2
```

**Guard conditions:**
1. Only the CEO sees the issue-float form; server re-checks role on POST.
2. Holder must be an **active Accountant** (role checked server-side; dropdown pre-filtered).
3. Amount must lie in **[800,000, 7,000,000]** TZS — validated before insert.
4. Expense must reference an existing float; the ledger shows remaining = amount − Σ(expenses).

## 6. Level 2 — Production Logging (process 3)

```mermaid
graph TB
    MGR["Manager / CEO"]
    P31(("3.1<br/>Validate Shift<br/>Report"))
    P32(("3.2<br/>Classify Units<br/>(Cups = Completed Goods,<br/>earlier = In-Process)"))
    P33(("3.3<br/>Persist Report<br/>+ Reject Breakdown<br/>(one transaction)"))

    D4[("D4 daily_reports")]
    D5[("D5 process_reject_logs")]
    D6[("D6 processes")]
    D7[("D7 machines")]
    D2[("D2 audit_logs")]

    MGR -->|"date, shift, machine, process,<br/>units processed, partial rejects, scrap,<br/>reason, root cause"| P31
    D6 -->|"6-stage pipeline"| P31
    D7 -->|"operational machines"| P31
    P31 -->|"rejects not more than units; accepted = units - rejects (derived)"| P32
    P32 -->|"unit status tag"| P33
    P33 -->|"header"| D4
    P33 -->|"breakdown"| D5
    P33 -->|"event"| D2
```

## 7. Reporting Data Flow (process 7)

```mermaid
graph LR
    REQ["Report request<br/>(template key(s), date range,<br/>format = html / pdf / xlsx)"]
    P71(("7.1<br/>Role-check<br/>against registry"))
    P72(("7.2<br/>Run SQL builders<br/>(role-filtered)"))
    P73(("7.3<br/>Render"))
    OUT1["HTML preview (browser)"]
    OUT2["PDF (pure-PHP engine:<br/>full wrapped cells, row rules,<br/>totals, page footer)"]
    OUT3["XLSX (pure-PHP engine:<br/>merged title, widths, freeze,<br/>auto-filter, zebra)"]
    D2[("D2 audit_logs")]

    REQ --> P71
    P71 --> P72
    P72 --> P73
    P73 --> OUT1
    P73 --> OUT2
    P73 --> OUT3
    P72 -->|"generation event"| D2
```
