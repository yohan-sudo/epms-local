# U EPMS — Technical Documentation (v2.3)

Documentation for the **Enterprise Plant Monitoring System** (broom-stick factory: Rounding → Sanding → P.V.C K/O Lines → Cups → Packaging/Sewing).

All diagrams are **pure Mermaid** — they render natively on GitHub, GitLab, VS Code preview (with a Mermaid extension), and at [mermaid.live](https://mermaid.live). Every diagram was extracted from the **live code and the deployed schema** (30 tables, MySQL `factory_db` with a SQLite fallback), not from memory.

| Doc | Contents |
|---|---|
| [01 — Use Case Diagram](01-use-case-diagram.md) | Six actors (CEO, Manager, Accountant, Procurement Officer, Supervisor + system/biometric device), full use-case diagram, every approval chain, role → use-case matrix |
| [02 — Data Flow Diagrams](02-data-flow-diagrams.md) | Context (Level 0), Level 1 subsystems, Level 2 drills: procurement requisition, cash requests, material requests, production verification, shipments, corrections, reporting, biometrics |
| [03 — Entity-Relationship Diagram](03-entity-relationship-diagram.md) | Full Mermaid ERD (relationships + attributes), narrative for every chain, status lifecycles, integrity rules |
| [04 — Table Reference](04-table-reference.md) | All 30 tables column-by-column: types, keys, defaults, FK cross-reference, business rules encoded in each table |

## The system in one paragraph

Five human roles work through 30 tables. **Procurement**: the Officer sends a requisition → the **CEO approves** → the Officer procures → the **Manager's approval is final** (locks the record) → goods are received into inventory once → payment happens *only* through a **cash request** (Officer/Manager → CEO approves → Accountant disburses → receiver confirms). **Inventory** is controlled end-to-end by the Officer; the Supervisor *requests* materials (shortage alerts fire automatically) and the Manager approves. **Production** is logged by the **Supervisor** (production, electricity, machine failures) and every entry is **verified authentic by the Manager** before it counts. **Petty cash** stays single-holder (an active Accountant, TZS 800,000–7,000,000) with two-signature expenses. **Shipments** of finished products start with a **CEO request**, are prepared by the Officer and approved/dispatched by the Manager. Mistakes are fixed through **supervised corrections** (CEO approves; the requester changes only the approved fields). Every security-relevant event lands in a **hash-chained, tamper-evident audit log** with a live "chain verified" banner.

## Viewing tips

- On GitHub/VS Code the diagrams render inline in preview — nothing to install.
- To edit diagrams visually: copy a Mermaid block into [mermaid.live](https://mermaid.live) or into draw.io via *Arrange ▸ Insert ▸ Advanced ▸ Mermaid* for fully editable shapes.
