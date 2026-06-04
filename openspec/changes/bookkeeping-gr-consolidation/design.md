# Design — Gemeenschappelijke Regeling (GR) Consolidation

## Context

Shillinq's mission includes supporting Dutch public sector joint ventures and inter-municipal collaborations. A Gemeenschappelijke Regeling (GR) is a legal structure for municipalities and institutions to jointly deliver services — social housing, waste management, regional planning, public transport, etc. Each participating organization maintains its own financial records; the GR itself must publish consolidated financial statements per BBV (Besluit Begroting en Verantwoording) showing group-level assets, liabilities, revenue, and expenses with inter-company transactions eliminated.

Currently `lib/Settings/shillinq_register.json` ships only a placeholder `example` schema; `openspec/architecture/adr-000-data-model.md` enumerates `ConsolidationGroup`, `ConsolidatedReport`, and `IntercompanyTransaction` but none have landed as registers yet. GR consolidation lays the surface that lets multi-organization administrations close their period with accurate group-level financial statements.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire GR consolidation surface as **declarative metadata** — schemas + `x-openregister-lifecycle` rules + manifest entries — per ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for audit trail, RBAC, approval workflow — per ADR-022. No reimplementation in shillinq.
- Make the spec a **GR administrator–readable contract** — a Dutch municipal finance officer should recognize the model as a faithful multi-organization consolidation flow, BBV-conformant, with no surprises.
- Keep GR consolidation layered on top of T4 without requiring changes to earlier tiers.

## Non-Goals

- No subsidiary acquisition accounting, goodwill, or step acquisitions — those are future T5+ features.
- No multi-currency translation; assume all GR members operate in EUR.
- No statutory filing format (SBR/XBRL, UBL/Peppol) — rendering/publication are downstream specs.
- No PHP code authored in this change (spec-only intent is explicit; tasks reference where guards land *if* needed).

## Decisions

### D1 — Declarative-first, per ADR-031

Every GR consolidation behaviour expressible as schema metadata MUST be declared in `lib/Settings/shillinq_register.json`, not authored as a PHP service. Concretely:

| Behaviour | Declarative form |
|---|---|
| Consolidation group membership and method | `ConsolidationGroup` schema with `consolidationMethod` enum + `Organization` relations |
| Elimination rule definition | `EliminationRule` schema linked to `ConsolidationGroup` |
| Consolidated report generation | `x-openregister-lifecycle` action on `ConsolidationGroup.consolidate()` transition → materialises `ConsolidatedReport` |
| Inter-company transaction tracking | `IntercompanyTransaction` schema with from/to member + account refs |
| Elimination on consolidation | `x-openregister-lifecycle.requires` precondition matching and eliminating inter-company pairs |
| Audit trail of every consolidation run | OR's built-in audit-trail-immutable (no app config required) |

**Alternative considered**: Author a PHP `ConsolidationService` mirroring SAP / Exact. Rejected per ADR-031 — declarative lifecycle + relations capture the shape without service code.

### D2 — Consolidation group as a container entity

A `ConsolidationGroup` entity groups one or more `Organization` records by a `parentOrganization` FK (the GR entity itself). The GR is modeled as a `Corporation` record in shillinq (one `Administration` per fiscal year). Each `ConsolidationGroup` instance owns:

- `name` — GR name (e.g., "Samenwerkingsverband Afvalverwerking")
- `consolidationMethod` — enum: `full` (100% ownership), `proportional` (50%–99.9%), `equity` (20%–50% associates)
- `parentOrganization` — FK to the `Corporation` representing the GR itself
- `eliminationRules` — one-to-many relation to `EliminationRule` records

This allows a single GR (one `Corporation`) to manage multiple consolidation scopes in different periods or for different statutory reports.

**Alternative considered**: Flatten to `Organization.parentOrganizationId` directly without a `ConsolidationGroup` wrapper. Rejected — the elimination rules, consolidation method, and batch configuration are group-level policies, not per-member. Wrapping in a container entity allows reuse of the same member set with different elimination rules across periods.

### D3 — `IntercompanyTransaction` as explicit posting-level tracker

Every inter-company transaction is recorded as a header + GL posting. An `IntercompanyTransaction` record materialises when one group member posts a sale or service to another — it is the source reference for the GL posting, not a summary. Consolidation time — not posting time — is when elimination happens.

- `fromMemberId` — FK to the selling/providing member (`Organization`)
- `toMemberId` — FK to the buying/receiving member (`Organization`)
- `transactionDate` — posting date
- `amount` — EUR amount
- `accountFrom` — GL account on the selling member (e.g., 4000 Revenue)
- `accountTo` — GL account on the buying member (e.g., 7000 Expenses)
- `reference` — invoice/PO number for manual matching if needed
- `glTransactionId` — FK to the underlying `GLTransaction` once posted
- `eliminationStatus` — enum: `pending` (not yet consolidated), `eliminated` (matched and removed at group level), `excluded` (manual override)

**Alternative considered**: Collapse inter-company posting into the GL transaction flow with a flag `isIntercompany=true`. Rejected — inter-company rules and policies are group-scoped, not ledger-scoped. A separate schema lets different consolidation groups have different elimination logic without coupling to the GL layer.

### D4 — EliminationRule as declarative pattern matching

An `EliminationRule` defines which inter-company transaction pairs cancel out at consolidation time:

- `consolidationGroupId` — FK to the group this rule applies to
- `ruleType` — enum: `auto-match` (exact account + amount), `reference-match` (matching invoice numbers), `manual-review` (pause and ask operator)
- `accountPairFrom` — GL account code on the selling member
- `accountPairTo` — GL account code on the buying member (typically mirrored, e.g., 4000 ↔ 7000)
- `amountTolerance` — optional threshold for `auto-match` (default 0 = exact match)
- `isActive` — boolean, allows disabling a rule without deletion

Per-group elimination rules allow a GR where member A and member B use different chart-of-accounts structures (common when members are legacy systems) while the parent GR uses a unified chart. A rule maps `(memberA_accountCode, memberB_accountCode)` pairs to the GR's unified accounts.

**Alternative considered**: Hard-code elimination logic in PHP. Rejected — different GRs have different policies. One may auto-eliminate all inter-company; another may require manual review for amounts >€10k. Declarative rules fit the diversity.

### D5 — ConsolidatedReport as a materialized snapshot

A `ConsolidatedReport` is materialized on demand (or scheduled) when consolidation is run:

- `consolidationGroupId` — FK to the group
- `reportDate` — snapshot date (typically end of fiscal period)
- `consolidationMethod` — the method used (`full`, `proportional`, `equity`)
- `status` — enum: `draft`, `finalized`, `published`, `archived`
- `eliminationsApplied` — boolean, whether inter-company eliminations have been computed
- `balanceSheetSummary` — JSON snapshot of assets/liabilities/equity at group level (denormalized for performance)
- `incomeStatementSummary` — JSON snapshot of revenue/expenses at group level

The report itself is NOT the GL aggregation query — it is a frozen snapshot. This allows auditability (the 2026-05 consolidated report is immutable even if member GL data is later corrected) and performance (real-time aggregation queries are expensive; snapshots are cacheable).

**Alternative considered**: On-demand aggregation from member GL without materialization. Rejected — audit trail, auditability, and performance. Materialized reports are the canonical shape for financial statements.

## Reuse Analysis

| Capability needed | What already exists | GR consolidation reuse strategy |
|---|---|---|
| Organization hierarchy / membership | `adr-000-data-model.md` `Corporation`, `Organization`, `Entity` entries | GR's parent organization is a `Corporation`; members are `Organization` records with `parentOrganizationId` → GR's `Corporation`. |
| GL line entry shape | T1 `GLLine` (from bookkeeping-foundation) | Inter-company GL postings use the same `GLLine` schema; `IntercompanyTransaction` is the routing metadata. |
| Consolidation container | `adr-000-data-model.md` `ConsolidationGroup` | T5 spec aligns with the existing entry; schema adds FK relations and elimination-rule linkage. |
| Consolidation reporting | `adr-000-data-model.md` `ConsolidatedReport` | T5 spec materializes per the existing entry; JSON snapshots for balance sheet + income statement. |
| Inter-company transactions | `adr-000-data-model.md` `IntercompanyTransaction` | T5 spec declares the register; adds FK to GL transactions and elimination-status tracking. |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every consolidation run, every elimination decision logs an audit event. |
| RBAC | OR authorization | Per-schema role definitions in the register file. T5 grants `consolidation-officer` read/write on `ConsolidationGroup` + `IntercompanyTransaction`, `approver` the consolidation-run trigger, `auditor` read-only. |
| Approval workflow | OR approval-workflow (per ADR-022) | Consumed via `x-openregister-lifecycle.requires` on consolidation runs if configured. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | T5 adds 2 menu entries + 2 index pages + 2 detail pages, all consuming `type: index` / `type: detail` library renderers. |
| Period / fiscal year context | T3 `FiscalYear`, `FiscalPeriod` | Consolidation is scoped to a fiscal year; `ConsolidatedReport.reportDate` aligns with a `FiscalYear`. |

**Net new code in T5 implementation**: 3 schema declarations + 2 manifest pages + 1 seed JSON file. Possibly 1 short PHP elimination guard (~30 LOC, single method) if Risk 1 in `proposal.md` confirms account-pair matching needs custom logic.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Consolidation group membership | Declarative (`Organization.parentOrganizationId` self-FK) | Pure relationship, fits the schema model |
| Consolidation method (full/proportional/equity) | Declarative (`ConsolidationGroup.consolidationMethod` enum) | Simple state, no computation needed |
| Elimination rule definition | Declarative (`EliminationRule` schema) | Rules are metadata; no algorithm in the schema |
| Elimination matching (account-pair + amount) | Declarative if exact match; PHP guard if fuzzy needed | Resolution in opsx-ff; spec is neutral |
| Consolidated report materialization | Declarative — `x-openregister-lifecycle` action on `ConsolidationGroup.consolidate()` → creates `ConsolidatedReport` | Lifecycle trigger + relation |
| JSON snapshot aggregation (balance sheet, income statement) | PHP — OR's aggregation service queries GL + applies eliminations, materializes JSON | Aggregation is computational; outside lifecycle scope |
| Audit trail | Consumed from OR's audit-trail-immutable | ADR-022 |

No service class authored in this GR envelope. If elimination matching needs fuzzy logic, it is `lib/Lifecycle/EliminationMatcher.php`, single method, ~40 LOC — explicitly cited as an ADR-031 exception.

## Seed Data

T5 ships one GR consolidation group example template under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `gr-consolidation-examples.json` | Example GR consolidation group setup with three member organizations, elimination rules, and a sample inter-company transaction. Demonstrates full consolidation on a municipal waste-management GR. | ~15 objects (3 member Organization + 1 ConsolidationGroup + 3 EliminationRule + sample IntercompanyTransaction + metadata) |

Format: a JSON array of objects matching the schemas declared in `bookkeeping-group-consolidation/spec.md` and `bookkeeping-intercompany-posting/spec.md`. Loaded via `ConfigurationService::importFromApp()` in the repair step. The example is loaded on install if the operator chooses; after loading, group membership and rules are fully editable through normal OR object operations.

**Example seed objects (Dutch municipal GR — illustrative):**

```json
[
  {
    "@self": { "register": "shillinq_consolidation", "schema": "Organization", "slug": "gr-member-1" },
    "name": "Gemeente Amsterdam",
    "kvkNumber": "34087349",
    "businessType": "gemeente",
    "parentOrganizationId": "gr-afvalverwerking-001",
    "status": "active"
  },
  {
    "@self": { "register": "shillinq_consolidation", "schema": "Organization", "slug": "gr-member-2" },
    "name": "Gemeente Utrecht",
    "kvkNumber": "30217471",
    "businessType": "gemeente",
    "parentOrganizationId": "gr-afvalverwerking-001",
    "status": "active"
  },
  {
    "@self": { "register": "shillinq_consolidation", "schema": "ConsolidationGroup", "slug": "gr-afvalverwerking-001" },
    "name": "Samenwerkingsverband Afvalverwerking",
    "consolidationMethod": "full",
    "parentOrganization": "gr-parent-org",
    "status": "active"
  },
  {
    "@self": { "register": "shillinq_consolidation", "schema": "EliminationRule", "slug": "rule-revenue-expense" },
    "consolidationGroupId": "gr-afvalverwerking-001",
    "ruleType": "auto-match",
    "accountPairFrom": "4000",
    "accountPairTo": "7000",
    "amountTolerance": 0,
    "isActive": true
  }
]
```

No seed data for `ConsolidatedReport` or inter-company transactions — those accumulate through operation.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Elimination-rule ambiguity at scale (50+ member pairs) | Document in design.md; spec remains neutral. Implementation resolves in opsx-ff (exact match vs. manual review vs. fuzzy matching). |
| Proportional consolidation not critical for MVP | Mark as OPTIONAL in REQ-GC-005; defer if needed. Full + equity consolidation ship first. |
| Account-pair mapping brittle if T4 renames accounts | Add a validation check at consolidation time warning (not erroring) if an account no longer exists. |
| JSON snapshot denormalization causes drift if member GL corrected after consolidation | Document as audit limitation: consolidated report is frozen; member GL corrections do not retroactively alter published reports. Correction requires re-consolidation. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the two new schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with two new menu entries + two new index/detail page pairs (additive).
3. A new repair step (or extension of the existing one) imports the GR consolidation example into the register on first install (optional — operators may skip).
4. ADR-000 gains a one-paragraph annotation noting `ConsolidationGroup`, `ConsolidatedReport`, and `IntercompanyTransaction` are declared in bookkeeping-gr-consolidation spec.

Down-direction: registers are non-destructive — disabling the seed import + reverting the manifest leaves stranded but queryable records. No destructive rollback needed at the spec-acceptance gate; real rollback happens at the implementing PR's revert.

## Open Questions

1. **Elimination-rule matching strategy** — exact account-pair + amount match, or fuzzy/threshold matching? Resolved in `opsx-ff` discovery. Spec is shape-neutral.
2. **Proportional consolidation MVP scope** — required for initial deployment or deferred? Confirm with stakeholders.
3. **Scheduled consolidation runs** — on-demand trigger only, or calendar-triggered at period boundary? Answer drives ScheduledWorkflow need.
4. **Multi-currency support for GR members** — can members transact in currencies other than EUR, with translation at consolidation time? (Deferred to T4+ multi-currency spec if needed.)
