# Design — TenderNed Integratie (Procurement Integration)

## Context

Dutch gemeenten, provincies, and MKB suppliers operate in a highly regulated procurement environment. The centerpiece is TenderNed (Logius platform, https://www.tenderned.nl), where all public tenders are published and winners announced. Current workflow: an inkoper exports PDF, finance re-enters by hand, and the obligation is created days later with risk of error and stale budget data.

The integration bridges TenderNed (external source of record) and Shillinq's Verplichting (internal obligation) via real-time polling and status sync. Goal: within 60 seconds of award, finance sees the commitment locked into the budget-impact widget.

## Goals

- **Eliminate transcription error** — import contract details programmatically, not by hand
- **Real-time budget visibility** — award triggers automatic obligation creation and budget updates within SLA
- **Audit-trail preservation** — ENSIA auditors can reconstruct the full lineage: TenderNed dossier → winning award → Shillinq obligation → milestone tracking → payment
- **Vendor cashflow planning** — MKB suppliers see their won contracts and forecast payment milestones
- **Bidirectional transparency** — aanbestedende dienst publishes final outcomes back to TenderNed's public dossier

## Non-Goals

- Outbound eForms-NL generation (EU standard) — TenderNed already handles compliance; we consume TenderNed's API as-is
- Interactive RFQ / bid evaluation (those are T0 procurement operations) — this change assumes the award decision has already been made in TenderNed
- Intercompany consolidation or group-wide procurement reporting — those are T5 finance operations
- AI-powered milestone forecasting — milestones are user-entered or generated from contract templates; no ML

## Decisions

### D1 — Two-way polling: TenderNed → Shillinq (polling) + Shillinq → TenderNed (status sync)

OpenConnector already polls TenderNed every 5 minutes. This change consumes that stream and reacts (REQ-002). For outbound, only the aanbestedende dienst role is allowed to write back to TenderNed's public dossier (REQ-006); inschrijvers are read-only on their own awards (REQ-008). The polling delay (5 min) is acceptable because internal notifications (contractmanager alert) are synchronous (REQ-002 says "detect that status changed" within the polling job).

**Alternative considered**: Event-driven subscription to TenderNed via webhook. Rejected — TenderNed's Logius API does not yet offer webhook push; polling is the stable path.

### D2 — Concept → Active workflow for contractmanager enrichment

REQ-001 mandates that a contractmanager confirm the contract before it locks budget. The workflow is:

1. **Manual import** (REQ-001) or **auto-detect** (REQ-002) creates a Verplichting with `status: concept` — requires contractmanager action to proceed
2. Contractmanager enriches: adds kostenplaats (cost centre), grootboekrekening (GL account), internal reference
3. Contractmanager clicks "Activate" → status → `active`
4. Budget-impact widget is notified; launchpad re-renders

This is a deliberate compliance gate: a finance medewerker or controller cannot be surprised by a budget commitment created by an external system without review. The enrichment step is recorded in the audit-trail (REQ-005).

**Alternative considered**: Auto-activate on award (skip concept). Rejected — breaches principle of least surprise and gives no opportunity to correct parsed data (e.g., if contract value was read incorrectly due to PDF parsing anomaly).

### D3 — Milestones are templates, not auto-generated from TenderNed dossier

TenderNed publishes contract start/end dates and (sometimes) payment schedule. But detailed milestone definitions (deeloplevering, eindoplevering, tussentijdse rapportage) are operational details that belong to the contract execution team, not the procurement dossier.

REQ-003 says: on activation, generate an initial milestone plan (e.g., quarterly deelfacturen for a 2-year dienstverlening). That template is user-editable. The system does not try to infer milestones from TenderNed's sparse data.

**Alternative considered**: Parse all TenderNed metadata and auto-populate milestones. Rejected — too fragile (TenderNed format varies; many contracts don't specify milestones); the contractmanager's review + edit is faster than debugging a parser.

### D4 — Bewijsstuk enforcement gates milestone closure (REQ-004)

A milestone cannot move to `status: afgerond` (completed) unless at least one bewijsstuk (proof of delivery) is attached. The bewijsstuk is stored in docudesk via OR's file-attachment abstraction (per ADR-022). The gate is declarative via `x-openregister-lifecycle.requires`.

**Alternative considered**: Soft requirement (warning if missing, but allow closure). Rejected — non-compliant with ENSIA standards and buyer audit practices. Hard gate is standard in SMB accounting software (Exact, AFAS, Snelstart).

### D5 — OpdrachtUitvoering is the materialized delivery log, not the contract itself

The contract (Verplichting) is the financial obligation. The OpdrachtUitvoering is the execution ledger — a log of completed milestones with proof. This separation allows:

- A Verplichting to be amended (price, scope) without rewriting the execution history
- Multiple OpdrachtUitvoering records (e.g., one per deelfactuur) to be tracked independently

The relationship is: Verplichting → [1..N OpdrachtUitvoering] where each OpdrachtUitvoering.verplichtingId points back to its parent obligation.

**Alternative considered**: Flatten OpdrachtUitvoering into Verplichting.mijlpalen. Rejected — opdrachtuitvoering.goedkeurder and opdrachtuitvoering.bewijsstukken are operational metadata that don't belong on the contract header. The separation also matches industry reference (contract vs. execution log in Exact / Twinfield).

### D6 — Vendor role sees only their own awards (inschrijver visibility filtering)

An MKB supplier's Shillinq tenant should only display `TenderNedAanbesteding` records where `gegundeLeverancier.kvkNumber == organization.kvkNumber`. This is enforced via OR's RBAC (per ADR-022).

The contract (Verplichting) and milestones are automatically created as private/internal records (not published externally). The supplier sees:
- Their awarded contracts in the "Mijn Contracten" view
- Milestone status and payment forecast in the cashflow-planning dashboard (REQ-008)
- Linked bewijsstukken (proofs they submitted)

**Alternative considered**: Suppliers see all TenderNed tenders, filtered client-side. Rejected — data-leakage risk; filtering must be enforced server-side in the register query.

### D7 — CPV codes are stored as-is, not reclassified

TenderNed publishes procurement categories (Common Procurement Vocabulary, CPV 2008) on every tender. This change stores `TenderNedAanbesteding.cpvCodes` as an array of strings. No reclassification or mapping to internal procurement categories happens here.

Future work (T+1): a data-enrichment layer could infer supplier type, commodity type, or risk profile from CPV codes; that is out of scope.

**Alternative considered**: Parse CPV, infer risk category. Rejected — NEN 2748 terminology is context-specific; automatic inference is premature.

## Reuse Analysis

| Entity | From ADR-000 | From This Change | Notes |
|--------|--------------|------------------|-------|
| Verplichting | ✅ existing | extended with `bron`, `bronReferentie`, `mijlpalen` | Core obligation entity; only additive extensions |
| TenderNedAanbesteding | ❌ new | ✅ declared here | Wraps TenderNed metadata; not in previous specs |
| OpdrachtUitvoering | ❌ new | ✅ declared here | Execution ledger for milestone tracking; not in previous specs |
| Account | ✅ from T1 bookkeeping-foundation | not touched | Contractmanager enriches Verplichting with GL account FK; Account schema unchanged |
| CostCenter | ✅ from cost-accounting-allocation spec | not touched | Contractmanager enriches Verplichting with cost centre FK; CostCenter schema unchanged |
| DigitalDocument / file attachments | ✅ from OR | consumed via ADR-022 | Bewijsstukken stored in docudesk; no local file table |

## Seed Data

### 1. Milestone Templates (lib/Settings/seeds/milestone-templates.json)

JSON array of milestone-plan templates. Each template is keyed by opdrachttype (contract type from context-brief.md):

```json
{
  "templates": [
    {
      "opdrachttype": "levering-in-fases",
      "naam": "Phased Delivery — Quarterly",
      "beschrijving": "4 quarterly deliveries over 1-year contract",
      "mijlpalen": [
        { "percentage": 25, "label": "Q1 Delivery", "defaultDays": 90 },
        { "percentage": 25, "label": "Q2 Delivery", "defaultDays": 180 },
        { "percentage": 25, "label": "Q3 Delivery", "defaultDays": 270 },
        { "percentage": 25, "label": "Q4 Delivery", "defaultDays": 360 }
      ]
    },
    {
      "opdrachttype": "dienstverlening-doorlopend",
      "naam": "Recurring Service — Monthly",
      "beschrijving": "12 monthly invoices over 1-year contract",
      "mijlpalen": [
        { "percentage": 8.33, "label": "Month 1", "defaultDays": 30 },
        { "percentage": 8.33, "label": "Month 2", "defaultDays": 60 },
        // ... months 3–12
      ]
    }
  ]
}
```

Repair step imports templates idempotently; per-administration override allowed.

### 2. Sample TenderNedAanbesteding seed (lib/Settings/seeds/sample-tenderned-aanbiedingen.json)

For testing: 3–5 sample TenderNed tenders with realistic Dutch values. *Not* auto-imported; test helpers use these to mock TenderNed API responses.

## Validation Rules & Declarative Constraints

### Verplichting Extensions

All new fields on Verplichting are added via `x-openregister-lifecycle` and conditional display in manifest:

```yaml
bron:
  type: enum
  enum: [ "tenderned", "manual", "inkooporder" ]
  default: "manual"
  description: "Source of this obligation"

bronReferentie:
  type: string
  description: "TenderNed aanbestedingId if bron=tenderned, else null"
  x-openregister-conditional-required: "bron == 'tenderned'"

mijlpalen:
  type: array
  items:
    type: object
    properties:
      datum: { type: date }
      omschrijving: { type: string }
      percentage: { type: number, minimum: 0, maximum: 100 }
      status: { type: enum, enum: ["planned", "in-progress", "completed", "cancelled"] }
      factuurnummer: { type: string, nullable: true }
```

### OpdrachtUitvoering Constraints

- `goedgekeurd` can only transition from `false` → `true` if `bewijsstukken.length > 0` (enforced via `x-openregister-lifecycle.requires`)
- `opleveringsType: eindoplevering` → `status: completed` unlocks the final milestone invoice (contractmanager sets payment-due flag in GL)

## Open Questions & Future Work

### Q1: Partial milestone matching without bewijsstuk

*Current spec*: bewijsstuk is mandatory; no dunning auto-escalation. *Future option*: auto-send 3-day reminder email to supplier if `status: in-progress` and `bewijsstuk.length == 0`.

*Decision deferred to*: implementation cycle, post-review by contractmanager personas.

### Q2: Concurrent supplier-specific contracts in omzet-prognose

*Current spec*: launchpad widget aggregates `Verplichting.contractWaarde` across all `bron: tenderned` records for the supplier. *Assumption*: no double-counting (each TenderNed award maps to exactly one Verplichting).

*Test needed*: create 5 overlapping TenderNed awards for the same supplier in rapid succession; verify widget shows sum without duplication. *Owner*: launchpad team during implementation.

### Q3: Cross-tenant / multi-kostenplaats visibility

*Assumption*: OR's RBAC naturally partitions TenderNed data by kostenplaats (cost centre owner) via the Verplichting.kostenplaats field. *Design*: contractmanager with role `verplichting:view` can see all obligations in their cost centre.

*Test needed*: verify permission model under the test-persona-mark / test-persona-janwillem personas (gemeente vs. MKB contexts). *Owner*: security-review gate during PR.

### Q4: Future — EForms-NL outbound generation (T+1)

*Out of scope for T0*. TenderNed status sync (REQ-006) uses TenderNed-API JSON; it does not generate full EU-standard eForms-NL XML. *Future work*: if Dutch regulations mandate EForms-NL outbound publication, implement an `opsx-ff` feature flag to toggle EForms-NL generation.

## Integration Points with Cross-Apps

### openconnector

- **Consumes**: TenderNed source polling (5-min cadence)
- **How**: openconnector's polling job emits CloudEvents (per ADR-031 background-job guidance) with TenderNed dossier updates. Shillinq listens on the event stream.
- **Example**: `TenderNedAwardNotification` event carries aanbestedingId, status, gegundeLeverancier, contractWaarde → triggers REQ-002 logic

### openregister

- **Consumes**: Audit-trail immutable, RBAC, file-attachment abstractions (ADR-022)
- **How**: `x-openregister-lifecycle` rules on Verplichting + OpdrachtUitvoering schemas
- **No app-local reimplementation**

### launchpad

- **Publishes**: Budget-impact events (contractWaarde, period, kostenplaats)
- **How**: Every TenderNedAanbesteding → Verplichting transition emits a CloudEvent; launchpad listener updates the budget-widget
- **SLA**: Widget reflects new obligation within 60 seconds (tested with 10 concurrent imports)

### docudesk

- **Stores**: Bewijsstukken (OpdrachtUitvoering.bewijsstukken)
- **How**: File references are URIs (`app: docudesk`, `documentId: <uuid>`); no local blob storage
- **Access control**: Supplier can upload to their own contract's bewijsstukken; buyer can view + comment

## Seed Data & Migrations

### Migration Script

The repair step (lib/Migration/TenderNedIntegrationRepairStep.php) does the following idempotently:

1. Check if shillinq_register.json already declares `TenderNedAanbesteding` and `OpdrachtUitvoering` — if yes, skip
2. Load milestone templates from `lib/Settings/seeds/milestone-templates.json` — offer to user during onboarding
3. Do NOT auto-import real TenderNed tenders (too noisy); wait for contractmanager to manually trigger (REQ-001) or for polling job to fire (REQ-002)

### Test Fixtures

Three sample TenderNed tenders (lib/Settings/seeds/sample-tenderned-aanbiedingen.json):

1. **Gemeente Utrecht, Schoonmaak Gemeentehuis** — €50,000, 1-year, gemeente-role test
2. **ProvincieBrabant, IT Services** — €250,000, 2-year, multi-milestone test
3. **MKB Supplier Test** — €15,000, supplier-as-inschrijver test

These are used by Newman/Postman tests and persona-test suites; not loaded into production databases.

## Performance & Scalability

### Polling Impact

- **Cadence**: 5 minutes (controlled by openconnector)
- **Payload**: ~100 bytes per dossier update (JSON event)
- **Expected load** (gemeente with 500 annual tenders): 500 / (365 * 24 * 60 / 5) ≈ 1 event per 4 hours during award season, 0 events in off-season
- **No performance concern** — polling is asynchronous; no blocking

### Budget-Widget Update SLA (REQ-007)

- **Target**: 60 seconds from award to widget re-render
- **Path**: TenderNed API → openconnector polling (5 min max) → CloudEvent → launchpad listener → widget cache invalidation → React re-render
- **Bottleneck**: openconnector's 5-minute cadence. Can be tightened to 1-minute if needed (configurable in openconnector admin).
- **Testing**: 10 concurrent manual imports trigger mycash widget updates in parallel; measure wall-clock time

### Audit-Trail Volume

- High-volume gemeente (100 obligations/month × 10 state transitions each = 1000 log entries/month)
- OR's audit table is indexed by entity type + entity ID + timestamp → no sequential scan
- No anticipated performance regression

## Security Considerations (ADR-005)

1. **TenderNed API authentication**: Managed by openconnector; shillinq doesn't store credentials
2. **Vendor data isolation**: RBAC enforces that suppliers see only their own awards (filtration at query layer)
3. **Bewijsstuk access control**: File URIs point to docudesk; docudesk enforces view/edit permissions per document owner
4. **Audit-trail immutability**: Consumed from OR; all state transitions logged and tamper-proof
5. **Status-sync authorization (REQ-006)**: Only `role: inkoper` (aanbestedende dienst) can write back to TenderNed; vendora (inschrijvers) cannot modify public dossier

## Rollback Plan

1. Stop openconnector's TenderNed polling job (one-line config in openconnector admin)
2. Set `TenderNedAanbesteding.status = archived` for all active records
3. Delete manifest navigation entries for "TenderNed Aanbestedingen" and "Mijn Contracten" (vendor list)
4. Archive `OpdrachtUitvoering` register (remove from shillinq_register.json)
5. Revert Verplichting schema to pre-integration state (drop `bron`, `bronReferentie`, `mijlpalen` fields) — migrations move data to external archive if needed
6. All obligations created via manual-import remain as `bron: manual`; no data loss

Full rollback takes <5 minutes and is safe (no breaking change to active obligations).
