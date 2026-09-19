# 01 — Use Case Diagram

> U EPMS (Enterprise Plant Monitoring System) — broom-stick factory, version 2.3.
> Roles verified against the live code (`requireRole()` on every page) and the live database.
> Six roles: **CEO (Owner), Manager, Accountant, Procurement Officer, Supervisor**, plus the
> automated system (audit seal, notifications, chatbot, biometric device).

## 1. Actors

| Actor | Type | What they do | What they must NOT do |
|---|---|---|---|
| **CEO (Owner)** | Primary | Owner of the factory. Approves procurement requisitions, cash requests and corrections. Requests product shipments. Issues the petty-cash float. Manages users, settings and the audit trail. Views everything, including inventory (read-only) and worker attendance. | Modify inventory stock directly; log day-to-day production (logged entries still need the Manager's verification) |
| **Manager** | Primary | Verifies (approves the authenticity of) production logs from the Supervisor. Approves shipments prepared by the Officer. Approves material requests from inventory. Sets production targets. Requests cash and confirms receiving it. Countersigns petty-cash expenses. | Touch inventory stock, log production or electricity, approve procurement requisitions |
| **Accountant** | Primary | Sole custodian of the petty-cash float. Records expenses, closes floats. Disburses cash **only after** the CEO approved the request. Requests float top-ups from the CEO. | See or log production, approve procurement, touch inventory, prepare shipments |
| **Procurement Officer** | Primary | Full control of inventory (items, stock in/out, low-stock alerts). Sends procurement requisitions to the CEO, procures after approval, receives goods into inventory. Prepares shipments for the Manager to approve. Requests cash for payments. | Approve own requisitions (CEO does), approve shipments (Manager does), hold cash |
| **Supervisor** | Primary | Logs production per shift, logs electricity usage, reports machine failures with reasons. Requests materials from inventory and confirms receipt when they arrive. | Approve anything, see money, hold stock, procure |
| **System (Audit Seal)** | Secondary | Hash-chains every security-relevant event into the tamper-evident audit log; shows a live "chain verified" banner. | — |
| **Biometric Device** | Secondary | Pushes fingerprint/face check-in events for registered workers (`api_biometric.php`, key-authenticated). | — |

Actor generalization: the **CEO inherits every use case** as owner override, except money custody — the float is still *held* by the Accountant only, and cash is still *disbursed* by the Accountant only.

## 2. Use Case Diagram

### Mermaid source (renders on GitHub, GitLab, VS Code, mermaid.live)

```mermaid
graph LR
    subgraph UEPMS["U EPMS - Enterprise Plant Monitoring System"]
        UC1(["Log In / Log Out"])
        UC2(["View Personal Dashboard"])
        UC3(["Read Notifications Panel"])
        UC4(["Ask Chatbot Assistant"])
        UC5(["Request a Correction"])

        subgraph Admin["Administration - CEO only"]
            UC10(["Manage User Accounts"])
            UC11(["Configure System Settings"])
            UC12(["Review Sealed Audit Trail"])
            UC13(["Approve Corrections"])
            UC14(["Manage Deputies"])
        end

        subgraph Prod["Production"]
            UC20(["Log Shift Production"])
            UC21(["Verify Production Logs"])
            UC22(["Log Electricity Usage"])
            UC23(["Report Machine Failure"])
            UC24(["Set Production Targets"])
            UC25(["View Production Overview"])
        end

        subgraph Inv["Inventory and Procurement"]
            UC30(["Control Inventory Items and Stock"])
            UC31(["Send Procurement Requisition to CEO"])
            UC32(["Approve Requisition - CEO"])
            UC33(["Procure Materials After Approval"])
            UC34(["Approve Procurement Record - Manager, final"])
            UC35(["Receive Goods into Inventory"])
            UC36(["Request Materials from Inventory"])
            UC37(["Approve Material Request - Manager"])
            UC38(["Release and Receive Materials"])
        end

        subgraph Cash["Cash and Petty Cash"]
            UC40(["Request Cash - Officer or Manager"])
            UC41(["Approve Cash Request - CEO"])
            UC42(["Disburse Approved Cash - Accountant"])
            UC43(["Confirm Cash Received"])
            UC44(["Issue Petty-Cash Float - CEO"])
            UC45(["Record and Confirm Expenses"])
            UC46(["Close and Reconcile Float"])
        end

        subgraph Ship["Shipments and Sales"]
            UC50(["Request Product Shipment - CEO"])
            UC51(["Prepare Shipment - Officer"])
            UC52(["Approve and Dispatch - Manager"])
            UC53(["Record Customer Dispatch"])
        end

        subgraph HR["Workers and Attendance"]
            UC60(["Register Worker"])
            UC61(["Enrol Fingerprint and Face"])
            UC62(["Check In / Check Out"])
            UC63(["View Attendance - CEO"])
        end

        UC70(["Generate Report - HTML / PDF / Excel"])
    end

    CEOA(["Actor: CEO Owner"])
    MGRA(["Actor: Manager"])
    ACCA(["Actor: Accountant"])
    POA(["Actor: Procurement Officer"])
    SUPA(["Actor: Supervisor"])
    SYS(["Actor: System - Audit Seal"])
    DEV(["Actor: Biometric Device"])

    CEOA --> UC1
    MGRA --> UC1
    ACCA --> UC1
    POA --> UC1
    SUPA --> UC1

    CEOA --> UC10
    CEOA --> UC11
    CEOA --> UC12
    CEOA --> UC13
    CEOA --> UC14
    CEOA --> UC32
    CEOA --> UC41
    CEOA --> UC44
    CEOA --> UC50
    CEOA --> UC60
    CEOA --> UC61
    CEOA --> UC63
    CEOA -.->|"owner override on every chain"| UC21
    CEOA -.-> UC34

    MGRA --> UC21
    MGRA --> UC24
    MGRA --> UC25
    MGRA --> UC34
    MGRA --> UC37
    MGRA --> UC40
    MGRA --> UC43
    MGRA --> UC45
    MGRA --> UC52

    ACCA --> UC42
    ACCA --> UC45
    ACCA --> UC46

    POA --> UC30
    POA --> UC31
    POA --> UC33
    POA --> UC35
    POA --> UC40
    POA --> UC51

    SUPA --> UC20
    SUPA --> UC22
    SUPA --> UC23
    SUPA --> UC36
    SUPA --> UC38

    UC1 --> UC2
    UC2 -.->|"include"| UC3
    UC2 -.->|"include"| UC4
    UC20 -.->|"include"| UC5
    UC22 -.->|"include"| UC5

    UC31 -.->|"wait for"| UC32
    UC32 -.->|"clears"| UC33
    UC33 -.->|"include"| UC34
    UC34 -.->|"include"| UC35
    UC36 -.->|"wait for"| UC37
    UC37 -.->|"include"| UC38
    UC40 -.->|"wait for"| UC41
    UC41 -.->|"routes to"| UC42
    UC42 -.->|"include"| UC43
    UC50 -.->|"wait for"| UC51
    UC51 -.->|"wait for"| UC52
    UC60 -.->|"include"| UC61
    UC61 -.->|"enable"| UC62

    SYS -.->|"seals every event"| UC1
    SYS -.-> UC10
    SYS -.-> UC32
    SYS -.-> UC41
    SYS -.-> UC70
    DEV -.->|"check-in events"| UC62
```

## 3. Key Flows (exactly as enforced in the code)

### Procurement — requisition first, money last
1. **Officer sends requisition** (`UC31`) → status `Pending CEO Approval`. The Officer is blocked from procuring until it is approved.
2. **CEO approves** (`UC32`) → the Officer is cleared to procure (`UC33`).
3. The record flows to the **Manager, whose approval is final** (`UC34`) — it locks the record.
4. **Officer receives the goods into inventory** (`UC35`) — guarded so it can only happen once.
5. Payment is **not** a procurement step: the Officer requests cash (`UC40`), the CEO approves (`UC41`), it routes automatically to the Accountant who disburses (`UC42`) and the receiver confirms (`UC43`).

### Cash requests — the only road to money
Officer or Manager requests → **CEO approves (the only approver)** → request appears on the Accountant's desk automatically → Accountant disburses → receiver confirms. The Accountant never approves the *need*, only moves the *money*.

### Production — the Supervisor logs, the Manager verifies
Every production entry lands as `Pending Verification`. The Manager verifies authenticity (`UC21`); only then does it count in KPIs and reports. The Supervisor also logs electricity (`UC22`) and reports machine failures with reasons (`UC23`), which the Manager verifies the same way.

### Materials — request, approve, release, confirm
Supervisor requests (`UC36`); if the request exceeds stock the system fires a shortage alert immediately. Manager approves (`UC37`). Officer releases stock and the Supervisor confirms receipt (`UC38`) — stock is deducted on release.

### Corrections — mistakes fixed under supervision
Any user selects the mistaken record and explains (`UC5`). The CEO approves (`UC13`); the requester may then change **only the field(s) they selected** — enforced server-side with a field whitelist — and every step is audit-sealed.

### Petty cash — one holder, hard bounds
The CEO issues the float to **an active Accountant only** (`UC44`), amount **800,000 – 7,000,000 TZS**. Expenses are recorded by the Accountant and **two-signature confirmed** (`UC45`). Floats close out with reconciliation (`UC46`).

### Shipments — requested from the top
The **CEO requests** a shipment of finished products (`UC50`) → the Officer prepares bundles/units and destination (`UC51`) → the **Manager approves and it dispatches** (`UC52`), deducting finished stock. Separate from this, customer dispatches/sales are recorded (`UC53`).

### Chatbot — role-scoped answers
The CEO's chatbot answers overview questions (money, production, inventory, shipments, attendance). For every other role the chatbot is a **reminder assistant only** — pending approvals and unread notifications; it refuses to reveal anything outside the asker's role (verified by tests).

## 4. Role → Use Case Matrix

| Use case | CEO | Manager | Accountant | Proc. Officer | Supervisor |
|---|:-:|:-:|:-:|:-:|:-:|
| Log in, dashboard, notifications, chatbot | ✔ | ✔ | ✔ | ✔ | ✔ |
| Request a correction | ✔ | ✔ | ✔ | ✔ | ✔ |
| Log production / electricity / failures | ✔* | ✘ | ✘ | ✘ | ✔ |
| Verify production logs | ✔ | ✔ | ✘ | ✘ | ✘ |
| Set production targets | ✔ | ✔ | ✘ | ✘ | ✘ |
| Inventory: add items, stock in/out | view-only | ✘ | ✘ | ✔ | ✘ |
| Send procurement requisition | ✘ | ✘ | ✘ | ✔ | ✘ |
| Approve requisition (before procuring) | ✔ | ✘ | ✘ | ✘ | ✘ |
| Approve procurement record (final) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Receive goods into inventory | ✘ | ✘ | ✘ | ✔ | ✘ |
| Request materials from inventory | ✘ | ✘ | ✘ | ✘ | ✔ |
| Approve material request | ✘ | ✔ | ✘ | ✘ | ✘ |
| Release / receive materials | ✘ | ✘ | ✘ | ✔ releases | ✔ receives |
| Request cash | ✘ | ✔ | ✘ | ✔ | ✘ |
| Approve cash request | ✔ | ✘ | ✘ | ✘ | ✘ |
| Disburse cash / hold float | ✘ | ✘ | ✔ | ✘ | ✘ |
| Confirm cash received | ✔ | ✔ | ✘ | ✔ | ✘ |
| Issue petty-cash float | ✔ | ✘ | ✘ | ✘ | ✘ |
| Record petty-cash expense | ✘ | ✘ | ✔ | ✘ | ✘ |
| Request shipment | ✔ | ✘ | ✘ | ✘ | ✘ |
| Prepare shipment | ✘ | ✘ | ✘ | ✔ | ✘ |
| Approve + dispatch shipment | ✘ | ✔ | ✘ | ✘ | ✘ |
| Register workers / enrol biometrics | ✔ | ✔ | ✘ | ✘ | ✘ |
| View attendance | ✔ | ✘ | ✘ | ✘ | ✘ |
| Users, settings, audit trail, corrections approval, deputies | ✔ | ✘ | ✘ | ✘ | ✘ |
| Reports (role-scoped) | ✔ all | ✔ prod + own finances | ✔ cash only | ✔ own + inventory | ✔ production |

\* CEO-entered production logs still await the Manager's verification — the Manager verifies, never logs.

Every ✔ is enforced twice: navigation (sidebar) and again per-action inside each page via `requireRole()` — hiding a link is never the security boundary.
