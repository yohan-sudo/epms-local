# U EPMS — Technical Documentation

Documentation for the **Enterprise Plant Monitoring System** (broom-stick factory: Rounding → Sanding → P.V.C K/O Lines → Cups → Packaging/Sewing).

All diagrams are **Mermaid** (renders natively on GitHub, GitLab, VS Code with a Mermaid extension, and at [mermaid.live](https://mermaid.live)); the use-case doc also ships a **PlantUML** variant for tools that require it (planttext.com, draw.io plugins). Every diagram was extracted from the **live code and the deployed `factory_db` schema**, not from memory.

| Doc | Contents |
|---|---|
| [01 — Use Case Diagram](01-use-case-diagram.md) | Actors (CEO, Manager, Accountant, Procurement Officer, System), full use-case diagram (Mermaid + PlantUML), include/extend relations, per-use-case specifications, role → use-case matrix |
| [02 — Data Flow Diagrams](02-data-flow-diagrams.md) | Context (Level 0), Level 1 subsystems, Level 2 drills: procurement two-gate approval, petty cash, production logging, reporting engine |
| [03 — Entity-Relationship Diagram](03-entity-relationship-diagram.md) | Full Mermaid ERD with attributes, relationship narrative for all 13 FKs, status lifecycle, integrity rules |
| [04 — Table Reference](04-table-reference.md) | Column-by-column reference for all 9 tables: types, keys, defaults, FK cross-reference, business rules encoded in each table |

## The system in one paragraph

Five roles (CEO/Owner, Manager, Accountant, Procurement Officer + the automated audit system) work through 9 MySQL tables. **Procurement** flows one way: Officer *submits* → Manager *approves (1st line)* → Accountant *final-approves (locks)* — the Officer never approves anything. **Production** logs units processed per machine/shift, auto-classifies Cups-stage units as *Completed Goods* versus earlier-stage *In-Process*, with derived accepted-units and a reject breakdown. **Petty cash** floats (TZS 800,000–7,000,000) are issued by the CEO to the *Accountant only* (sole holder) and consumed via receipted expenses. Every security-relevant event lands in an immutable `audit_logs` trail, which survives user deletion via `SET NULL` actorship.

## Viewing tips

- On GitHub/VS Code the diagrams render inline in preview — nothing to install.
- To edit diagrams visually: copy a Mermaid block into [mermaid.live](https://mermaid.live) (ERD → "ER diagram" syntax is supported).
- For formal submission documents: the PlantUML block in doc 01 renders to PNG/SVG at [planttext.com](https://planttext.com).
