# Spec: Bookkeeping Group Consolidation

**Primary spec:** financial-reporting-accountability  
**Affected app:** shillinq  
**Tier:** T5 (specialized consolidation surface)

## Summary

Enable multi-organization consolidated financial reporting for Dutch Gemeenschappelijke Regelingen (GRs — joint venture municipal collaborations, inter-municipal service delivery, social housing consortiums). A consolidation group aggregates GL data from member organizations, applies configurable elimination rules to inter-company transactions, and materializes a frozen snapshot `ConsolidatedReport` showing group-level assets, liabilities, equity, revenue, and expenses per BBV compliance.

## Context

A Gemeenschappelijke Regeling is a legal structure under Dutch municipal law (Gemeentewet art. 8) where two or more municipalities or public bodies jointly deliver services. Each member maintains its own GL (via T1–T4 bookkeeping tiers); the GR as a legal entity must file consolidated financial statements. This spec defines the container, membership, elimination rules, and reporting surface.

### Assumptions

- All group members use the same `administrationNumber` / `businessYear` scope (same fiscal year).
- Members are Shillinq `Organization` entities linked by `parentOrganizationId` → GR's `Corporation`.
- GR operates in EUR; multi-currency translation is deferred to T4+ (not in this spec).
- Elimination rules are per-group and per-consolidation-method, not global.

## Features

### Group consolidation with real-time consolidated financial reporting
**demand: 134** | Category: financial reporting

A consolidation group links member organizations and triggers aggregated reporting. Real-time means the operator can run consolidation at any time (not just at period close); the snapshot is frozen for auditability.

## Requirements

### REQ-GC-001: ConsolidationGroup entity exists and can be created

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `Corporation`, `Organization`, `EliminationRule`

A consolidation group is a container entity linking one or more member organizations under a parent GR (`Corporation`). The group owns consolidation policy (method, elimination rules) applicable to all members.

#### Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | GR name (e.g., "Samenwerkingsverband Afvalverwerking"). Max 255 chars. |
| consolidationMethod | enum | Yes | One of: `full` (100% ownership), `proportional` (50–99.9%), `equity` (20–50% associates). Default: `full`. |
| parentOrganization | string (FK) | Yes | FK to the `Corporation` entity representing the GR itself. |
| status | enum | Yes | One of: `active`, `inactive`, `archived`. Default: `active`. |
| description | string | No | Free-text notes on the GR's purpose or scope. |

#### Scenario: Administrator creates a consolidation group for a municipal waste-management GR

```gherkin
GIVEN I am an admin user with `consolidation-officer` role
AND the GR entity "Samenwerkingsverband Afvalverwerking" exists as a Corporation
WHEN I create a new ConsolidationGroup with:
  - name: "Samenwerkingsverband Afvalverwerking"
  - consolidationMethod: "full"
  - parentOrganization: "gr-afval-001"
AND I save the record
THEN the ConsolidationGroup is persisted to the register
AND the audit trail records the creation with actor = current user
```

---

### REQ-GC-002: Group membership is managed via Organization.parentOrganizationId

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidationGroup`, `Organization`

Member organizations join the group by setting their `parentOrganizationId` FK to point to the GR's parent `Corporation`. The group itself does not maintain a separate membership list; membership is derived from the Organization → parent relation.

#### Properties

No new properties on ConsolidationGroup for this; Organization uses the existing `parentOrganizationId`.

#### Scenario: Two municipalities are assigned to a consolidation group

```gherkin
GIVEN a ConsolidationGroup "gr-afval-001" exists for the GR
AND two Organization records exist: "Gemeente Amsterdam" and "Gemeente Utrecht"
WHEN I set Organization."Gemeente Amsterdam".parentOrganizationId = GR parent corporation FK
AND I set Organization."Gemeente Utrecht".parentOrganizationId = GR parent corporation FK
THEN both organizations are members of the consolidation group
AND a consolidation run aggregates GL data from both members
```

---

### REQ-GC-003: ConsolidatedReport is materialized on consolidation run

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidationGroup`, `GLTransaction`, `IntercompanyTransaction`

When consolidation is triggered, a `ConsolidatedReport` is created as a frozen snapshot. The snapshot includes aggregated GL balances (assets, liabilities, equity, revenue, expenses) with inter-company eliminations applied.

#### Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consolidationGroupId | string (FK) | Yes | FK to the ConsolidationGroup. |
| reportDate | datetime | Yes | Snapshot date (typically fiscal year end). |
| consolidationMethod | enum | Yes | Echoes the group's method at the time of consolidation. |
| status | enum | Yes | One of: `draft`, `finalized`, `published`, `archived`. |
| eliminationsApplied | boolean | Yes | Whether inter-company elimination logic was executed. Default: false. |
| balanceSheetSummary | json | No | Denormalized JSON snapshot of assets/liabilities/equity. Structure: `{ assets: {...}, liabilities: {...}, equity: {...} }`. |
| incomeStatementSummary | json | No | Denormalized JSON snapshot of revenue/expenses. Structure: `{ revenue: {...}, expenses: {...} }`. |

#### Scenario: Administrator runs consolidation and materializes a report

```gherkin
GIVEN a ConsolidationGroup "gr-afval-001" with two members exists
AND both members have GL postings in the current fiscal year
WHEN I trigger "Consolidate Now" action on the group
THEN a ConsolidatedReport is created with:
  - consolidationGroupId: "gr-afval-001"
  - reportDate: end of current period
  - status: "draft"
  - eliminationsApplied: true
AND the balanceSheetSummary includes aggregated assets from both members
AND the incomeStatementSummary includes aggregated revenue/expenses
AND the audit trail records the consolidation run with timestamp + actor
```

---

### REQ-GC-004: Consolidation method determines aggregation logic

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidationGroup`

The consolidation method (full/proportional/equity) determines how member GL balances are aggregated and which elimination rules apply.

- **Full consolidation** (100% ownership): All member GL lines are summed line-by-line; inter-company transactions are fully eliminated.
- **Proportional consolidation** (50–99.9% ownership): Member GL lines are multiplied by the ownership percentage before summing.
- **Equity consolidation** (20–50% associates): Member GL lines are not summed; instead, the GR's investment in the associate is adjusted to reflect its share of the associate's retained earnings. (Deferred to follow-up spec if needed.)

#### Scenario: Full consolidation aggregates 100% of member GL

```gherkin
GIVEN a ConsolidationGroup with consolidationMethod = "full"
AND Member A has assets of €100,000
AND Member B has assets of €80,000
WHEN consolidation runs
THEN the ConsolidatedReport.balanceSheetSummary.assets = €180,000
```

#### Scenario: Proportional consolidation scales by ownership percentage

```gherkin
GIVEN a ConsolidationGroup with consolidationMethod = "proportional"
AND Member A (75% owned) has assets of €100,000
AND Member B (60% owned) has assets of €80,000
WHEN consolidation runs
THEN the ConsolidatedReport includes:
  - Member A contribution: €100,000 × 75% = €75,000
  - Member B contribution: €80,000 × 60% = €48,000
  - Total assets: €123,000
```

---

### REQ-GC-005: Elimination rules are matched and applied at consolidation time

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `EliminationRule`, `IntercompanyTransaction`

Elimination rules define which account pairs (e.g., Revenue 4000 ↔ Expenses 7000) are eliminated when paired transactions are found. Matching happens at consolidation time, and inter-company transactions marked `eliminationStatus = eliminated` are excluded from the consolidated totals.

#### Properties

No new properties on ConsolidationGroup; elimination rules are defined in the separate `EliminationRule` schema (see bookkeeping-intercompany-posting spec).

#### Scenario: Revenue/Expense inter-company pair is eliminated

```gherkin
GIVEN a ConsolidationGroup with two members
AND an EliminationRule matching (accountFrom: "4000", accountTo: "7000")
AND Member A posts €50,000 revenue (account 4000) to Member B
AND Member B posts €50,000 expense (account 7000) to Member A
WHEN consolidation runs and applies elimination rules
THEN both inter-company transactions are matched and eliminated
AND the ConsolidatedReport revenue does not include the €50,000
AND the ConsolidatedReport expenses do not include the €50,000
AND IntercompanyTransaction.eliminationStatus = "eliminated" for both
```

---

### REQ-GC-006: Consolidated reports can transition from draft → finalized → published

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidatedReport`

A consolidated report lifecycle allows an operator to draft, review, finalize, and publish without re-running the aggregation. Once `finalized`, the snapshot is immutable.

#### Properties

The `status` field on `ConsolidatedReport` transitions: `draft` → `finalized` → `published` → `archived`.

#### Scenario: Operator finalizes a consolidated report for review

```gherkin
GIVEN a ConsolidatedReport in status "draft"
WHEN I click "Finalize" 
THEN the report transitions to status "finalized"
AND no further editing of the snapshot is allowed
AND the audit trail records the finalization
```

---

### REQ-GC-007: Real-time consolidation snapshot reflects current GL state

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidationGroup`, `GLTransaction`

Consolidation can be triggered at any time (not just at period close) to produce a real-time snapshot. Each consolidation run queries the GL as of the report date and materializes fresh `balanceSheetSummary` and `incomeStatementSummary` JSON.

#### Scenario: Administrator runs consolidation mid-period to review group position

```gherkin
GIVEN a ConsolidationGroup with two members
AND it is 2026-05-15 (mid-month)
WHEN I click "Consolidate Now"
AND set reportDate = 2026-05-15 (today)
THEN a ConsolidatedReport is created reflecting GL state as of 2026-05-15
AND balanceSheetSummary includes all GL transactions posted up to 2026-05-15
AND I can review the group's interim financial position
AND I do NOT need to close the fiscal period to consolidate
```

---

### REQ-GC-008: Audit trail records every consolidation run and report state change

**Demand link:** Group consolidation with real-time consolidated financial reporting  
**Related entities:** `ConsolidatedReport`

Every consolidation run and every status transition on a `ConsolidatedReport` is logged to the audit trail with timestamp, actor, before/after snapshot, and SHA256 hash chain.

#### Scenario: Audit trail shows consolidation history

```gherkin
GIVEN a ConsolidatedReport that has been consolidated and finalized multiple times
WHEN I view the audit trail
THEN I see entries for:
  - Report creation (timestamp, actor)
  - Elimination rules applied (which rules, match count)
  - Status transition draft → finalized (timestamp, actor)
  - Status transition finalized → published (timestamp, actor)
AND each entry includes a hash chain for tamper-detection
```

---

## Out of Scope

- Proportional consolidation detailed logic (OPTIONAL in this spec; may be deferred).
- Subsidiary acquisition accounting (goodwill, step acquisition) — future spec.
- Multi-currency translation and FX revaluation — T4+ feature.
- Statutory filing formats (SBR/XBRL, UBL/Peppol) — separate rendering spec.
- Equity consolidation (associates, 20–50% ownership) — may be deferred to follow-up.

## Acceptance Criteria

- [ ] `ConsolidationGroup` schema is declared in `lib/Settings/shillinq_register.json` with all required properties.
- [ ] `ConsolidatedReport` schema is declared with status lifecycle (`draft` → `finalized` → `published` → `archived`).
- [ ] A consolidation run (trigger point TBD: on-demand, scheduled, or hybrid) materializes a new `ConsolidatedReport` with aggregated GL data.
- [ ] Elimination rules (from the separate spec) are applied and `IntercompanyTransaction.eliminationStatus` is set correctly.
- [ ] Audit trail entries are created for every consolidation run and status transition.
- [ ] Manifest navigation includes "Consolidation > Group Consolidation" index page showing all groups and their latest report.
- [ ] Spec is readable by a Dutch municipal finance officer without confusion.
