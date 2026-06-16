# Design — Financial Statements

## Context

Balance Sheet, P&L, and Cash Flow are the legally-required output
for SMB administrations (RJ 270 / IFRS for SMEs NL/EU). T2's
financial-statements capability composes these from T2's trial-
balance aggregation + a presentation manifest mapping RJ 270 line
items to RGS account ranges.

Per ADR-031, statement assembly is declarative. The
`BalanceSheetReportService.php` etc. that shillinq could otherwise
grow into are the canonical anti-pattern. Per ADR-024, the renderer
is preferably a Tier-4 library component (`CnReportPage`) so any
report-bearing app in the fleet can adopt it.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire financial-statements surface as **declarative
  metadata** — composed aggregations + presentation manifests +
  calculation fields (XBRL) + manifest entries — per ADR-031.
- Consume the trial-balance aggregation (spec sibling) — per
  ADR-022. Zero PHP report builder in shillinq.
- Land the renderer in `@conduction/nextcloud-vue` per ADR-024
  Tier-4 so the fleet benefits; fallback to short bespoke Vue if
  the library doesn't ship in time.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the RJ 270 structure
  end-to-end (assets / liabilities / equity / revenue / cost / net
  result / operating-investing-financing cash flow).

## Non-Goals

- No PHP report-builder service.
- No BBV statements — T3.
- No full IFRS — T5.
- No group consolidation, intercompany eliminations, segment
  reporting — T5.
- No multi-currency translation, FX revaluation, CTA postings —
  T5.
- No direct-method cash flow — roadmap; T2 ships indirect.

## Decisions

### D1 — Statements are compositions of trial-balance aggregations

Balance Sheet, P&L, Cash Flow are compositions of trial-balance
aggregations grouped by a presentation manifest (RJ 270 / IFRS for
SMEs line items mapped to RGS account ranges). No PHP statement
builder.

**Alternative considered**: A `BalanceSheetService` /
`PLService` / `CashFlowService` per statement type. Rejected per
ADR-031 — explicit anti-pattern.

### D2 — Presentation manifest as JSON seed under `lib/Settings/statements/`

3 manifests ship: `rj270-balance-sheet.json`, `rj270-pl.json`,
`rj270-cash-flow.json`. Each maps RJ 270 line items to RGS account
ranges. Per-administration override allowed (operator may edit a
seeded manifest through normal OR object operations).

**Alternative considered**: Hard-code the presentation in the
schema. Rejected — RJ 270 evolves, BBV needs different manifests
(T3), per-administration tailoring is normal.

### D3 — Renderer preferred path is library component, sunset fallback

Preferred: `CnReportPage` in `@conduction/nextcloud-vue` takes a
presentation manifest + aggregation results and renders any
statement. ADR-024 Tier-4 — the whole fleet benefits.

Fallback: a short bespoke Vue per statement type (with sunset note
mandating migration to (a) once the library lands).

Decision resolved during `opsx-ff` discovery based on library
readiness.

### D4 — XBRL/PDF export are declarative

XBRL is an `x-openregister-calculations` field on each statement
output, emitting SBR-compatible XML. PDF is a manifest-driven action
invoking the existing `@conduction/nextcloud-vue` PDF utility (or
`wkhtmltopdf` adapter). No PHP exporter.

### D5 — Year-over-year comparatives are manifest-side

The report manifest declares N comparison periods; the aggregation
runs once per period. No bespoke comparative logic.

### D6 — BBV explicitly deferred to T3

BBV statement manifests (Dutch government / municipal) need
different account ranges and different line items. T2 ships RJ 270
only; BBV manifests ship in T3 against the same renderer.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Trial-balance aggregation | T2 `bookkeeping-trial-balance` (`x-openregister-aggregations`) | Composed by each statement |
| Statement composition | T2 trial-balance aggregations + presentation manifest | Manifest under `lib/Settings/statements/`; renderer composes |
| Balance Sheet / P&L / Cash Flow templates | RJ 270 / IFRS for SMEs (NL/EU) public standard | Ship JSON manifests; per-administration override allowed |
| XBRL export | OR `x-openregister-calculations` (ADR-031) | SBR-compatible XML calculation on statement output |
| PDF export | `@conduction/nextcloud-vue` PDF utility (or wkhtmltopdf adapter) | Manifest-driven action |
| Multi-period comparatives | OR aggregation per-period parameterisation | Manifest declares N comparison periods |
| Drill-through | Manifest-side URL templates | Line item → filtered trial balance → GL |
| Renderer | `CnReportPage` (preferred — `@conduction/nextcloud-vue`) or per-statement bespoke Vue (fallback) | Tier-4 library preferred; ADR-031 fallback documented |
| Audit trail on statement queries | OR audit-trail-immutable (consumed via `bookkeeping-audit-trail`) | Read operations logged automatically |
| Manifest navigation | T1 manifest pattern | 3 entries (Balance Sheet, P&L, Cash Flow Statement) + their pages |
| Seed import | T1 `ConfigurationService::importFromApp()` pattern | Extended for the 3 statement manifests |

**Net new code in implementation cycle**: 3 statement aggregation
declarations + 3 XBRL calculations + 3 manifest entries + 3 seed
files (~95 line items total). Possibly 0–3 bespoke Vue files
(sunset path).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Balance Sheet / P&L / Cash Flow assembly | Declarative — composition of trial-balance aggregations with presentation manifest | No report-builder service |
| Cash flow indirect-vs-direct method choice | Declarative — manifest variant | Engine evaluates per chosen manifest |
| Multi-period comparatives | Declarative — manifest declares N periods | Aggregation runs per period |
| XBRL export | Declarative (`x-openregister-calculations`) | Pure data → XML transformation |
| PDF export | Manifest action invoking library PDF utility | No shillinq PDF code |
| Drill-through | Declarative — manifest URL template | No app routing code |
| Renderer | Tier-4 library (preferred) or per-statement Vue (sunset fallback) | Both manifest-bound |

No service class authored in this envelope.

## Seed Data

3 RJ 270 / IFRS-for-SMEs presentation manifests:

| File | Purpose | Approximate row count |
|---|---|---|
| `rj270-balance-sheet.json` | Assets / liabilities / equity hierarchy mapped to RGS 3.5 SMB account ranges. | ~40 |
| `rj270-pl.json` | Revenue / cost of sales / operating expenses / financial result / tax / net result. | ~30 |
| `rj270-cash-flow.json` | Indirect-method cash flow: operating / investing / financing activities. | ~25 |

Each file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "RJ 270 (2026)",
  "variant": "smb", "imported": "<iso-timestamp>" } }`) so future
  migration (T3 BBV, T5 full IFRS) can identify template-sourced
  versus operator-authored records.

BBV-conformant manifests are explicitly T3.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| `CnReportPage` library not yet shipped | Bespoke Vue fallback per statement; sunset note mandates migration when library lands |
| Presentation-manifest format drift between RJ 270 versions | Versioned filename (`rj270-*`); coexistence with future versions trivial; bookkeeper persona reviews per release |
| XBRL taxonomy version drift | Pin SBR taxonomy version in the spec; coordinate with SBR governance on any uplift |
| Per-administration override loses on re-seed | Idempotent import — operator edits persist; resolved via `_meta.imported` timestamp comparison |
| Multi-period comparatives perform poorly | Default to current + 1 prior; expose period count as manifest parameter for knowledgeable extension |
| BBV needs the same renderer | Renderer is format-agnostic; T3 ships BBV manifests against same renderer; no T2 rework |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the 3
   statement aggregation declarations + 3 XBRL calculations
   (additive).
2. `lib/Settings/statements/rj270-{balance-sheet,pl,cash-flow}.json`
   ship as new files. Imported via
   `ConfigurationService::importFromApp()` in the repair step.
3. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
4. If `CnReportPage` is not yet shipped, 0–3 bespoke Vue files ship
   with sunset note.

Down-direction: registers + seed files are non-destructive —
reverting removes the navigation; statement manifests remain
queryable but unreferenced.

## Open Questions

1. **One-aggregation-vs-three buckets** — inherits trial-balance
   discovery resolution.
2. **`CnReportPage` library availability** — resolved in `opsx-ff`
   discovery; spec shape-neutral.
3. **Direct vs indirect cash flow** — T2 default indirect; direct
   on roadmap.
4. **XBRL taxonomy version pin** — resolved during implementing
   cycle.
5. **Bookkeeper-persona review** — required peer review before
   merging the implementing PR.
