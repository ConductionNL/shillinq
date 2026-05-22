# Design — Wet Markt en Overheid Compliance & Commercial Activity Bookkeeping

## Decisions

### D1 — CommercialActivity is the central register, not a GL-account dimension

Per ADR-031, each commercial activity is a first-class register record (not a free-form tag on GL lines). This allows governance: the activity record carries ACM meldingstatus, ABB reference, kostprijsmethode, kostenplaats/kostendrager coupling, marktcontext (marktsegment, concurrenten, afnemers, jaaromzet). Queries like "which activities have ABB > 2 years stale" become trivial; a free-form tag would require text-matching hacks.

**Alternative considered**: Store commercial-activity-ness as a GL-account flag or dimension tag. Rejected — no structured metdata, no auditability, no support for multi-deelnemer activities, no scoping for ACM reporting.

### D2 — Integral Cost Price is time-versioned and calculated monthly with definitive year-end closure

IKP is not stored as a single denormalized "latest" record. Instead, each period (month, quarter, year) gets a versioned `IntegralCostPrice` record with `status: voorlopig` or `definitief`. This supports:

- **Month-end provisional**: each month calculates `IKP-2026-03` in `voorlopig` state, posted to jaarrekening-bijlage-draft.
- **Year-end definitive**: 31 March of the following year, accountant locks `IKP-2026-YTD` in `definitief` state (audit control point).
- **Restatement auditability**: if prior-year overhead allocation is corrected, the historic `IKP-2025-Q4` remains immutable; a new `IKP-2025-Q4-restatement` can be issued with reason logged.

**Alternative considered**: Single mutable "current" IKP record, recalculated each month. Rejected — no audit trail if prior-year IKP must change, no support for provisional vs. definitief distinction per GAAP, hard to explain to ACM.

### D3 — OverheadDistributionRule is inherited from cost-centers-dimensions, not duplicated

Rather than author a parallel WMO-specific "overhead-allocation" schema, we reuse the `OverheadDistributionRule` from `bookkeeping-cost-centers-dimensions` (Phase 2 dependency). The WMO context (integrale-kostprijs-art-25i, BBV-compliance) is implicit: any rule tagged `basis: personele-fte` and `bronTaakvelden: ["0.4 Overhead"]` is the BBV-sleutel, which IKP-calculation uses. This eliminates duplication and guarantees WMO overhead = BBV overhead (a critical control).

**Alternative considered**: Author a separate `WMOOverheadRule` schema with identical structure. Rejected — double maintenance, risk of divergence, violates ADR-031 single-schema aggregation principle.

### D4 — ActivityCostAllocation is a reversible split record, not a mutation of GL lines

When a transaction (e.g. energy invoice, HR cost) touches a commercial activity, the system does NOT modify the journal entry itself. Instead, it creates an immutable `ActivityCostAllocation` record describing the split, with the GL line as the source and the allocation rule as the basis. The GL line remains single-debit/single-credit; the split is *derived* at query time (for reporting) or *materialized* as additional balanced GL lines (if the operator chooses to post the split to the ledger for consolidation reporting).

This design supports:

- **Audit trail**: the allocation record logs source, rule, ratio, and timestamp; recompute at any point by re-running the rule.
- **Override**: if the allocation is wrong (e.g. wrong rule applied), mark it `override: true`, log reason + 2-eye sign-off, and create a new allocation.
- **Reversal**: if a transaction must be reversed, the allocation is not deleted; it's marked `status: reversed` and a new allocation records the reversal.

**Alternative considered**: Mutate the GL line itself, adding a `commercialActivityId` field and storing the split ratio inline. Rejected — less auditible, harder to correct, violates GL immutability principle (per ADR-031).

### D5 — ABB lifecycle is workflow-driven, not a simple boolean flag

An `AlgemeenBelangBesluit` record progresses through states: concept → raadsvoorstel → raadsbesluit → publicatie gemeenteblad → kennisgeving ACM → bezwaartermijn → geldig → evaluatie → herziening/intrekking. Each state-change is an audit event. The system enforces preconditions (e.g. must be published before becoming geldig, must have ACM-kenmerk before geldig). Automatic evaluation-task generation on `volgendeEvaluatie` (per VNG handreiking, default 2 years) ensures no ABB is forgotten.

**Alternative considered**: Store ABB as a boolean `isExempted` + date `expiryDate`. Rejected — no visibility into process state, no accountability for workflow delays, no support for bezwaarschriften / herziening, no automatic evaluation scheduling.

### D6 — Cross-subsidy detection is a monthly scheduled batch process, not real-time

The `CrossSubsidyDetector` runs once per month (e.g. 1st of the month at 02:00 UTC), evaluates all commercial activities in the administration against 8 risk scenarios (loss-financing, overhead under-allocation, omzetgroei without IKP update, etc.), and emits alerts. Each alert is a record with `status: open`, assignedTo (concerncontroller), and a 4-week escalation threshold to gemeentesecretaris.

**Alternative considered**: Real-time alerts on every transaction. Rejected — alert fatigue (thousands of false-positives during month-end close), performance impact, hard to prioritize (monthly runner batches the alerts, allows thresholding).

### D7 — ACM reporting is a templated export, not a service that "submits" to ACM

This spec generates the ACM-standaardformulier (quarterly or annual) as a machine-leesbare JSON/XML document. The operator reviews, digitally signs, and submits to ACM via ACM's portal (or future API). Shillinq does not call ACM's API directly (to avoid auth/liability complexity). The export format is designed to be ACM-API-compatible (per open questions in proposal), so future direct-submission is a UI-only change.

**Alternative considered**: Shillinq maintains an ACM portal account and submits directly. Rejected — liability, auth scoping, divergent ACM portal versioning, operator loses audit visibility.

### D8 — Immutable audit trail is stored separately from mutable data, per ADR-031

All WMO-mutations (CommercialActivity save, IKP calculation, ActivityCostAllocation override, AlgemeenBelangBesluit state-change, ACMReport generation) log entries to a single `WMOAuditLog` register (per `bookkeeping-audit-trail` cross-cutting spec). The log is write-once: entries are never deleted, only marked `status: archived` after 7-year retention (Mededingingswet bewaartermijn). The audit export (for ACM) is generated from this log, not from mutable record history.

**Alternative considered**: Store audit info as JSON fields on each record (createdBy, updatedAt, updateReason, etc.). Rejected — easy to accidentally mutate, inconsistent across entities, hard to query across all WMO changes.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Commercial activity storage | New `CommercialActivity` register | Declared as OpenRegister schema in `shillinq_register.json`; inherits RBAC + audit from OR |
| Overhead allocation basis | `OverheadDistributionRule` from `bookkeeping-cost-centers-dimensions` | BBV-sleutel (taakveld 0.4) is reused as-is; WMO IKP overhead component = BBV overhead |
| Cost-price component storage | New `IntegralCostPrice` register, time-versioned | Keyed by (commercialActivityId, periode, status); versioned for audit |
| Transaction split derivation | New `ActivityCostAllocation` register | Reversible, immutable, linked to GL line and OverheadDistributionRule |
| ABB lifecycle management | New `AlgemeenBelangBesluit` register | Workflow-driven state machine, lifecycle actions for precondition checks |
| Cross-subsidy detection | New `CrossSubsidyDetector` scheduled workflow runner | Per ADR-031 ScheduledWorkflow; runs monthly batch |
| ACM reporting | New `ACMReport` register + templated export | JSON/XML machine-leesbaar, compatible with anticipated ACM API |
| Immutable audit trail | `bookkeeping-audit-trail` cross-cutting spec | All WMO mutations log to shared immutable log; 7-year retention |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 6 register entries + index/detail pages under `Bookkeeping > WMO Compliance` |
| Activity-transition workflow | Phase 3 enhancement; not in Phase 1 | Deferred |
| Governance coupling | `bookkeeping-governance` optional integration (Phase 3) | Raad-voorstel / raad-besluit linking; works standalone without it |
| Multi-bestuursorgaan support | Phase 3 enhancement | administrationId scoping + deelnemers[] array on CommercialActivity |

**Net new code in Phase 1 implementation cycle**: 6 schema declarations + `CommercialActivityService` (getter/lookup) + `IntegralCostPriceCalculator` (scheduled runner) + `ActivityCostAllocationSplitter` (event listener) + 4 manifest entry pairs. No bespoke Vue components.

## Seed Data

### Phase 1 Seed Data

Seeds live under `lib/Settings/seeds/commercial-activities/` — example commercial activities for testing and documentation:

| File | Purpose | Example values |
|---|---|---|
| `sportaccommodaties-gemeente.json` | Test gemeente with 3 sport facilities (zaalverhuur, parkeerexploitatie, kantine) | Gemeente X, bestuursorgaan = Gemeente X, kostenplaats K-SPORT-001, kostendrager D-MO-SPORT-001 |
| `waterschap-slibruimte.json` | Test waterschap with commercial sludge-processing service | Waterschap Y, bestuursorgaan = Waterschap Y, kostenplaats K-SBV-001, kostendrager D-MO-SBV-001 |
| `abb-example-gemeente.json` | Linked to above: AlgemeenBelangBesluit for one sport activity (reintegration rationale) | Raadsbesluit 2023-XXX, publicatie gmb-2023-YYYYY, ACM kenmerk ACM/IN/ZZZZ |

These ship in `lifecycleState: archived` (historical reference only). Operators create live activities through the UI.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "shillinq-documentation", "variant": "test-gemeente", "imported": "<iso-timestamp>" } }`).

### Phase 1 Seed: Integral Cost Price Example

For testing IKP calculation, one seed `integral-cost-price-example-q1-2026.json` carries:

- commercialActivityId = linked to sportaccommodaties-gemeente.json zaalverhuur activity
- periode = 2026-Q1
- status = voorlopig
- componenten with realistic Dutch values (e.g. 1.2 FTE zaalbeheer × €35k/fte = €42k, catering supplies €8.5k, AV-equipment depreciation €7k, overhead via BBV-sleutel 7.2% × corporate €365k = €26.3k, vermogenskosten €1.8k via SWACC 4.5% on €40k equipment, winstopslag 3% = €2.5k).
- verkochteEenheden = 312 dagdelen
- kostprijsPerEenheid = calculated by the IKP calculator (not manually entered in seed)
- gehanteerdTarief = €295 (example market price)
- marge = €295 - €285 = €10, margin 3.5% — compliant

These seeds are educational; operators' live data accumulates through operation.
