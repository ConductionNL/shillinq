# Design — SBR/XBRL Reporting

**status: pr-created**

## Decisions

### D1 — XBRL is a transformation on top of `FinancialStatement`, not a re-aggregation

The XBRL instance MUST consume T3's already-balanced
`FinancialStatement` object and map each line to the configured
NL-taxonomie concept via a `Mapping` record. The alternative —
re-aggregating ledger lines per XBRL concept — would risk drift
between the XBRL filing and the operator-visible financial statement.
Single source of truth.

**Alternative considered**: Re-aggregate from `GLLine` per XBRL
concept. Rejected — duplicates the T3 aggregation, creates drift, and
makes the filing path unverifiable against the statement the operator
already saw and signed off.

### D2 — Digipoort submission lives in openconnector, not shillinq

Submission of the XBRL instance to Digipoort is consumed from
openconnector by source slug (per ADR-022). No Digipoort HTTP client,
no WS-Security certificate handling, no SMPP/SMP protocol code in
shillinq.

**Alternative considered**: Embed a Digipoort SOAP client and
WS-Security stack in shillinq for direct submission. Rejected — that
duplicates openconnector's reason for existing and forces shillinq to
track Digipoort protocol changes. Per ADR-022, every cross-system
integration that a sibling app can provide MUST be consumed from that
sibling.

### D3 — Declarative state machine, no `XbrlReportService`

The draft → validated → submitted → accepted / rejected state machine
is expressed entirely as `x-openregister-lifecycle` on `XbrlInstance`.
No PHP `XbrlReportService` orchestrates the transitions. This is
ADR-031's anti-pattern path 1 (state machines as schema metadata).

**Alternative considered**: Author `XbrlReportService` mirroring
Exact / Twinfield style. Rejected per ADR-031 — same anti-pattern as
decidesk's `MotionService` / `VotingService` / `QuorumService` mid-
migration away.

### D4 — Per-administration mapping overrides

NL-taxonomie seed mappings are templates; operators may edit them for
company-specific extension concepts. `Mapping` records are
per-administration scoped so a single shillinq install can serve
multiple administrations with divergent filings.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| XBRL instance state machine | `x-openregister-lifecycle` (ADR-031) | Declarative — no PHP state machine |
| Digipoort submission HTTP path | openconnector `Source` registry | Consumed by slug (ADR-022); no embedded client |
| NL-taxonomie line → concept mapping | OR `Mapping` register + Mappings abstraction (ADR-022) | One `Mapping` record per (entry point, taxonomy version) |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config) |
| RBAC | OR authorization | Per-schema role definitions |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 1 menu entry + 1 index/detail page pair |
| Seed data import | `ConfigurationService::importFromApp()` | Repair-step pattern from T1 |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair + N seed JSON files (NL-taxonomie mappings per
entry point and version). No new PHP service.

## Seed Data

Seeds live under `lib/Settings/seeds/sbr-mappings/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `sbr-mappings/kvk-jaarrekening-nt17.json` | KvK deponering jaarrekening — NL-taxonomie nt17 | ~200 |
| `sbr-mappings/kvk-jaarrekening-nt18.json` | KvK deponering jaarrekening — NL-taxonomie nt18 | ~210 |
| `sbr-mappings/belastingdienst-vpb-nt17.json` | Aangifte vennootschapsbelasting — nt17 | ~150 |
| `sbr-mappings/belastingdienst-vpb-nt18.json` | Aangifte vennootschapsbelasting — nt18 | ~160 |
| `sbr-mappings/belastingdienst-ib-nt17.json` | Aangifte inkomstenbelasting — nt17 | ~100 |
| `sbr-mappings/sbr-banken-kredietrapportage-nt17.json` | SBR-banken kredietrapportage — nt17 | ~80 |
| `sbr-mappings/sbr-wonen-nt17.json` | SBR-Wonen (housing-corp) — nt17 | ~120 |

Format: a JSON array of mapping records each carrying
`{financialStatementLineId, taxonomyConcept, contextRef, unitRef}`.
Loaded via `ConfigurationService::importFromApp()` in the repair step.
Per-administration override is allowed: operators may edit mappings to
reflect company-specific extension concepts.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "NL-taxonomie", "variant":
  "<entry-point>", "taxonomyVersion": "nt18", "imported":
  "<iso-timestamp>" } }`).

No seed data for `XbrlInstance` itself — instances accumulate through
operation.
