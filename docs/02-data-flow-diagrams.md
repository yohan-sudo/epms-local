# 02 — Data Flow Diagrams (DFD)

> Levels: **Context (Level 0)** → **Level 1** (major subsystems) → **Level 2** (the multi-step chains: procurement requisition, cash request, material request, production verification, shipment, correction).
> Every process number maps to a real PHP page; every store (D#) maps to a real MySQL/SQLite table (30 tables, dual-driver).

## 1. External Entities

| Entity | What it exchanges with the system |
|---|---|
| **CEO (Owner)** | Credentials, user management, settings, requisition approvals, cash-request approvals, float issuance, shipment requests, correction approvals, audit queries, attendance views, report requests |
| **Manager** | Credentials, verification of production logs, shipment approvals, material-request approvals, cash requests and receipts, expense countersigning, targets, report requests |
| **Accountant** | Credentials, disbursements of approved cash, expenses, float close-outs, float top-up requests, report requests (cash only) |
| **Procurement Officer** | Credentials, inventory control, requisitions, procurement records, goods receipt, shipment preparation, cash requests, report requests |
| **Supervisor** | Credentials, shift production logs, electricity readings, machine-failure reports, material requests, receipt confirmations |
| **Biometric Device** | Fingerprint/face check-in events (key-authenticated endpoint) |

## 2. Level 0 — Context Diagram

```mermaid
graph TB
    CEO["CEO (Owner)"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]
    SUP["Supervisor"]
    DEV["Biometric Device"]

    P0(("0<br/>U EPMS<br/>Enterprise Plant<br/>Monitoring System"))

    CEO -->|"credentials; approvals (requisitions, cash, corrections); float issuance; shipment requests; user management"| P0
    P0 -->|"full overview dashboards; worklists; audit trail; chatbot overview; reports"| CEO

    MGR -->|"credentials; log verification; shipment and material approvals; cash requests"| P0
    P0 -->|"approval worklists; production overview; own finances; reports"| MGR

    ACC -->|"credentials; disbursements; expenses; float close-outs"| P0
    P0 -->|"cash queues; float balances; payment reminders"| ACC

    PO -->|"credentials; inventory control; requisitions; procurement records; goods receipt; shipment preparation"| P0
    P0 -->|"inventory console; requisition status; shipment queue; payment reminders"| PO

    SUP -->|"credentials; production and electricity logs; failure reports; material requests"| P0
    P0 -->|"logging portal; request status; shortage alerts"| SUP

    DEV -->|"check-in and check-out events"| P0
    P0 -->|"attendance records"| DEV
```

## 3. Level 1 — Major Subsystems

Data stores (all live tables, MySQL `factory_db` or the SQLite equivalent):

- **D1 `users`** — accounts, roles, lockout, forced password changes
- **D2 `audit_logs`** — hash-chained, tamper-evident event trail
- **D3 `procurement_entries`** — procurement records, requisition + approval state
- **D4 `inventory_items` / `inventory_transactions`** — stock master + movement ledger
- **D5 `inventory_requests`** — Supervisor material requests
- **D6 `stock_receipts` / `stock_issues` / `material_batches`** — goods in, materials out
- **D7 `daily_reports` / `process_reject_logs`** — production headers + reject breakdown
- **D8 `electricity_readings` / `machine_failures` / `machine_downtime` / `shift_targets`** — floor data
- **D9 `cash_requests`** — the cash pipeline (request → CEO → Accountant → confirm)
- **D10 `petty_cash_issuances` / `petty_cash_expenses` / `petty_cash_requests`** — float, expenses, top-ups
- **D11 `shipment_orders` / `dispatches` / `customers`** — shipments and sales
- **D12 `correction_requests`** — supervised corrections
- **D13 `workers` / `worker_attendance`** — biometric-ready workforce records
- **D14 `notifications`** — per-user alert queue
- **D15 `processes` / `machines`** — 6-stage pipeline and machine registry
- **D16 `attachments` / `delegations` / `app_settings`** — support stores

```mermaid
graph TB
    CEO["CEO"]
    MGR["Manager"]
    ACC["Accountant"]
    PO["Procurement Officer"]
    SUP["Supervisor"]
    DEV["Biometric Device"]

    P1(("1<br/>Authentication<br/>and Sessions<br/>index.php / auth.php"))
    P2(("2<br/>Dashboards, Notifications<br/>and Chatbot<br/>dashboard.php / api_chatbot.php"))
    P3(("3<br/>Production Logging<br/>and Verification<br/>production.php / floor.php"))
    P4(("4<br/>Inventory and<br/>Procurement<br/>inventory.php / procurement.php"))
    P5(("5<br/>Cash Requests and<br/>Petty Cash<br/>cash_requests.php / petty_cash.php"))
    P6(("6<br/>Shipments and<br/>Dispatch<br/>shipments.php / dispatch.php"))
    P7(("7<br/>Correction<br/>Workflow<br/>corrections.php"))
    P8(("8<br/>Workers and<br/>Biometric Attendance<br/>workers.php / api_biometric.php"))
    P9(("9<br/>User, Settings and<br/>Audit Admin<br/>users.php / settings.php / audit_logs.php"))
    P10(("10<br/>Reporting<br/>Engine<br/>reports.php"))
    P11(("11<br/>Audit Sealer<br/>logAudit()"))

    D1[("D1 users")]
    D2[("D2 audit_logs")]
    D3[("D3 procurement_entries")]
    D4[("D4 inventory_items + transactions")]
    D5[("D5 inventory_requests")]
    D6[("D6 stock_receipts / stock_issues / material_batches")]
    D7[("D7 daily_reports + reject_logs")]
    D8[("D8 electricity / failures / downtime / targets")]
    D9[("D9 cash_requests")]
    D10[("D10 petty cash tables")]
    D11[("D11 shipments / dispatches / customers")]
    D12[("D12 correction_requests")]
    D13[("D13 workers + worker_attendance")]
    D14[("D14 notifications")]
    D15[("D15 processes / machines")]
    D16[("D16 attachments / delegations / app_settings")]

    CEO -->|"credentials"| P1
    MGR -->|"credentials"| P1
    ACC -->|"credentials"| P1
    PO -->|"credentials"| P1
    SUP -->|"credentials"| P1
    P1 -->|"role, session, flash"| CEO
    P1 --> MGR
    P1 --> ACC
    P1 --> PO
    P1 --> SUP
    P1 -->|"verify password, role, lockout"| D1

    CEO --> MGR
    CEO --> ACC
    CEO --> PO
    CEO --> SUP
    P2 -->|"role-scoped KPIs, worklists, reminders, chat answers"| CEO
    P2 --> MGR
    P2 --> ACC
    P2 --> PO
    P2 --> SUP
    P2 -->|"write alerts"| D14
    P2 -->|"read pending work across D3 D5 D7 D9 D11 D12"| D14
    P2 -.->|"CEO only: overview queries"| D1

    SUP -->|"shift log, electricity, failure report"| P3
    P3 -->|"Pending Verification rows"| D7
    P3 -->|"floor data"| D8
    P3 -->|"stage and machine lists"| D15
    MGR -->|"verify authenticity"| P3
    P3 -->|"Verified rows count in KPIs"| D7

    PO -->|"requisition; procurement record; goods receipt; item and stock control"| P4
    CEO -->|"approve requisition"| P4
    MGR -->|"final approval; material-request approval"| P4
    SUP -->|"material request; receipt confirmation"| P4
    P4 -->|"read and write"| D3
    P4 -->|"stock master and movements"| D4
    P4 -->|"requests"| D5
    P4 -->|"receipts and issues"| D6
    P4 -->|"shortage alerts"| P2

    PO -->|"cash request"| P5
    MGR -->|"cash request; confirm receipt"| P5
    CEO -->|"approve request; issue float"| P5
    ACC -->|"disburse; expenses; close-out"| P5
    P5 -->|"cash pipeline"| D9
    P5 -->|"floats, expenses, top-ups"| D10
    P5 -->|"approved-cash alerts"| P2

    CEO -->|"request shipment"| P6
    PO -->|"prepare shipment"| P6
    MGR -->|"approve and dispatch"| P6
    MGR -->|"record customer dispatch"| P6
    P6 -->|"shipment lifecycle"| D11
    P6 -->|"deduct finished stock"| D4

    MGR --> ACC
    MGR --> PO
    MGR --> SUP
    CEO -->|"request correction"| P7
    CEO -->|"approve correction"| P7
    P7 -->|"requests and grants"| D12
    P7 -->|"scoped update to target table"| D7
    P7 -.-> D3
    P7 -.-> D10

    MGR -->|"register worker; enrol biometrics"| P8
    CEO -->|"register worker; enrol biometrics; view attendance"| P8
    DEV -->|"key-authenticated check-in events"| P8
    P8 -->|"workforce records"| D13

    CEO -->|"user CRUD, settings, audit review"| P9
    P9 -->|"accounts"| D1
    P9 -->|"settings"| D16
    P9 -->|"read trail"| D2

    CEO --> MGR
    CEO --> ACC
    CEO --> PO
    CEO --> SUP
    MGR -->|"report request"| P10
    ACC -->|"report request"| P10
    PO -->|"report request"| P10
    SUP -->|"report request"| P10
    P10 -->|"HTML / PDF / XLSX"| CEO
    P10 --> MGR
    P10 --> ACC
    P10 --> PO
    P10 --> SUP
    D3 -->|"role-filtered reads"| P10
    D7 --> P10
    D10 --> P10
    D9 --> P10
    D4 --> P10
    D11 --> P10
    D1 --> P10
    D2 --> P10

    P11 -->|"append sealed rows"| D2
    P4 -->|"events"| P11
    P5 -->|"events"| P11
    P6 -->|"events"| P11
    P7 -->|"events"| P11
    P9 -->|"events"| P11
    P10 -->|"events"| P11
```

## 4. Level 2 — Procurement Requisition Chain (process 4)

```mermaid
graph TB
    PO["Procurement Officer"]
    CEO["CEO"]
    MGR["Manager - final gate"]

    P41(("4.1<br/>Submit Requisition<br/>lock own procuring"))
    P42(("4.2<br/>CEO Requisition<br/>Decision"))
    P43(("4.3<br/>Record Procurement<br/>PRC-YYYY-NNNN"))
    P44(("4.4<br/>Manager Final<br/>Approval"))
    P45(("4.5<br/>Receive Goods<br/>into Inventory - once"))
    P46(("4.6<br/>Notify Officer<br/>to Request Cash"))

    D3[("D3 procurement_entries")]
    D4[("D4 inventory_items + transactions")]
    D6[("D6 stock_receipts / material_batches")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    PO -->|"items, estimated cost, justification"| P41
    P41 -->|"status = Pending CEO Approval<br/>requisition_status = Pending"| D3
    D3 -->|"worklist"| P42
    CEO -->|"approve or reject + notes"| P42
    P42 -->|"Approved or Rejected"| D3
    P42 -->|"notify decision"| D14
    D3 -->|"cleared requisition"| P43
    PO -->|"supplier, item, qty, unit cost"| P43
    P43 -->|"total = qty x unit cost"| D3
    D3 -->|"Pending Manager Review"| P44
    MGR -->|"approve = Finalized / reject"| P44
    P44 -->|"status = Finalized, locked"| D3
    P44 -->|"event"| D2
    D3 -->|"Finalized record"| P45
    PO -->|"quantities received"| P45
    P45 -->|"stock_receipts + material_batches"| D6
    P45 -->|"stock IN movement"| D4
    P45 -->|"inventory_received = 1 guard"| D3
    D3 -->|"Finalized, unpaid"| P46
    P46 -->|"payment reminder"| D14
```

**Guards (server-enforced):**
1. The Officer cannot procure while their requisition is `Pending` — the gate is checked on every procurement POST.
2. Only the CEO can decide a requisition; the Officer cannot see or use the decision endpoint.
3. The Manager's approval is **final** — there is no Accountant approval step; the Accountant only pays via Cash Requests (4.6 → process 5).
4. Goods receipt is guarded by `inventory_received` — stock can never be double-counted.

## 5. Level 2 — Cash Request Chain (process 5)

```mermaid
graph TB
    REQ["Requester: Officer or Manager"]
    CEO["CEO - sole approver"]
    ACC["Accountant - sole mover of money"]
    RCV["Receiver: requester confirms"]

    P51(("5.1<br/>Create Cash Request<br/>CR-YYYY-NNNN"))
    P52(("5.2<br/>CEO Decision"))
    P53(("5.3<br/>Auto-Route to<br/>Accountant"))
    P54(("5.4<br/>Disburse<br/>record money out"))
    P55(("5.5<br/>Confirm Received<br/>close the loop"))

    D9[("D9 cash_requests")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    REQ -->|"amount, purpose"| P51
    P51 -->|"status = Pending CEO Approval"| D9
    D9 -->|"queue"| P52
    CEO -->|"approve or reject + notes"| P52
    P52 -->|"Approved or Rejected"| D9
    P52 -->|"event"| D2
    D9 -->|"Approved row"| P53
    P53 -->|"alert Accountant"| D14
    D9 -->|"queue"| P54
    ACC -->|"pay out"| P54
    P54 -->|"Disbursed + disbursed_by/at"| D9
    P54 -->|"event"| D2
    D9 -->|"Disbursed row"| P55
    RCV -->|"confirm"| P55
    P55 -->|"Received + confirmed_by/at"| D9
    P55 -->|"notify CEO"| D14
```

**Guards:** only the CEO approves; only the Accountant disburses (the disburse action rejects any other role); double-press protection prevents duplicate payouts; the requester's confirmation closes the loop and the CEO is told.

## 6. Level 2 — Material Request Chain (process 4, requests)

```mermaid
graph TB
    SUP["Supervisor"]
    MGR["Manager"]
    PO["Procurement Officer"]

    P61(("6.1<br/>Request Material<br/>MR-YYYY-NNNN"))
    P62(("6.2<br/>Stock Check<br/>shortage alert"))
    P63(("6.3<br/>Manager<br/>Decision"))
    P64(("6.4<br/>Officer Releases<br/>stock OUT"))
    P65(("6.5<br/>Supervisor<br/>Confirms Receipt"))

    D5[("D5 inventory_requests")]
    D4[("D4 inventory_items + transactions")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    SUP -->|"item, quantity, purpose"| P61
    P61 -->|"Pending Manager Approval"| D5
    P61 -->|"read stock"| D4
    D4 -->|"requested above available"| P62
    P62 -->|"shortage alert to Officer and Manager"| D14
    D5 -->|"queue"| P63
    MGR -->|"approve or reject"| P63
    P63 -->|"Approved or Rejected"| D5
    D5 -->|"approved queue"| P64
    PO -->|"release"| P64
    P64 -->|"stock OUT movement + deduct"| D4
    P64 -->|"Released"| D5
    D5 -->|"Released row"| P65
    SUP -->|"confirm"| P65
    P65 -->|"Received"| D5
    P64 -->|"event"| D2
```

**Guards:** the Supervisor never touches stock levels — they only request and confirm. Stock is deducted exactly once, at release. A request larger than available stock fires an alert immediately, before the Manager even decides.

## 7. Level 2 — Production Verification Chain (process 3)

```mermaid
graph TB
    SUP["Supervisor (or CEO)"]
    MGR["Manager - verifier"]

    P31(("3.1<br/>Validate Shift<br/>Report"))
    P32(("3.2<br/>Classify Units<br/>Cups = Completed Goods<br/>earlier = In-Process"))
    P33(("3.3<br/>Persist as<br/>Pending Verification<br/>one transaction"))
    P34(("3.4<br/>Manager Verifies<br/>authenticity"))
    P35(("3.5<br/>Verified rows<br/>feed KPIs and reports"))

    D7[("D7 daily_reports + process_reject_logs")]
    D15[("D15 processes / machines")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    SUP -->|"date, shift, machine, process,<br/>units, rejects, reason, root cause"| P31
    D15 -->|"6-stage pipeline; machines"| P31
    P31 -->|"rejects not more than units<br/>accepted = units - rejects (derived)"| P32
    P32 -->|"stage-based classification"| P33
    P33 -->|"header + breakdown, approval_status = Pending Verification"| D7
    P33 -->|"notify Manager"| D14
    D7 -->|"queue"| P34
    MGR -->|"verify or send back"| P34
    P34 -->|"Verified / Pending Verification"| D7
    P34 -->|"event"| D2
    D7 -->|"Verified only"| P35
```

**Guards:** logging is Supervisor-only (the CEO may enter as owner, but every entry — CEO's included — waits for the Manager's verification). The Manager cannot log. Verified rows are the only ones counted in dashboards and reports.

## 8. Level 2 — Shipment Chain (process 6)

```mermaid
graph TB
    CEO["CEO - requests"]
    PO["Procurement Officer - prepares"]
    MGR["Manager - approves and dispatches"]

    P71(("7.1<br/>CEO Creates<br/>Shipment Request"))
    P72(("7.2<br/>Officer Prepares<br/>SH-YYYY-NNNN<br/>bundles, units, destination"))
    P73(("7.3<br/>Manager Approves<br/>dispatch + deduct stock"))

    D11[("D11 shipment_orders")]
    D4[("D4 inventory_items (finished goods)")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    CEO -->|"destination, product"| P71
    P71 -->|"status = Requested"| D11
    D11 -->|"queue"| P72
    PO -->|"bundles, units, notes"| P72
    P72 -->|"status = Prepared"| D11
    P72 -->|"notify Manager"| D14
    D11 -->|"queue"| P73
    MGR -->|"approve"| P73
    P73 -->|"Approved + dispatched_at + stock deduction"| D11
    P73 -->|"finished-goods OUT"| D4
    P73 -->|"event"| D2
```

## 9. Level 2 — Correction Workflow (process 7)

```mermaid
graph TB
    USER["Any role - requester"]
    CEO["CEO - approver"]
    USER2["Requester - applies scoped change"]

    P81(("8.1<br/>Select Record<br/>+ fields + reason"))
    P82(("8.2<br/>CEO Decision<br/>approve or deny"))
    P83(("8.3<br/>Scoped Application<br/>whitelisted fields only"))

    D12[("D12 correction_requests")]
    D2[("D2 audit_logs")]
    D14[("D14 notifications")]

    USER -->|"entity, correction type, reason"| P81
    P81 -->|"Pending CEO Approval"| D12
    D12 -->|"queue"| P82
    CEO -->|"decision + notes"| P82
    P82 -->|"Approved - Awaiting Application or Denied"| D12
    P82 -->|"notify requester"| D14
    D12 -->|"Approved grant for requester"| P83
    USER2 -->|"new values, selected fields only"| P83
    P83 -->|"scoped UPDATE to target table"| D12
    P83 -->|"Applied + applied_by/at"| D12
    P81 -->|"event"| D2
    P82 -->|"event"| D2
    P83 -->|"event"| D2
```

**Guard:** the application step compares submitted fields against the recorded selection — anything outside the approved scope is rejected server-side. The old value, new value and approver all land in the sealed audit log.

## 10. Reporting and Biometric Data Flows

```mermaid
graph LR
    REQ["Report request<br/>(templates, date range,<br/>format = html / pdf / xlsx)"]
    P101(("10.1<br/>Role-check<br/>template registry"))
    P102(("10.2<br/>Run role-filtered<br/>SQL builders"))
    P103(("10.3<br/>Render"))
    OUT1["HTML preview"]
    OUT2["PDF (pure-PHP engine)"]
    OUT3["XLSX (pure-PHP engine)"]
    D2[("D2 audit_logs")]

    REQ --> P101
    P101 --> P102
    P102 --> P103
    P103 --> OUT1
    P103 --> OUT2
    P103 --> OUT3
    P102 -->|"generation event"| D2
```

```mermaid
graph LR
    DEV["Biometric device<br/>(fingerprint / face)"]
    P111(("11.1<br/>Device key check"))
    P112(("11.2<br/>Match worker<br/>template"))
    P113(("11.3<br/>Write attendance row<br/>method = biometric"))
    D13[("D13 workers + worker_attendance")]

    DEV -->|"signed check-in event"| P111
    P111 --> P112
    P112 --> P113
    P113 --> D13
```
