# Design: btw-suppletie-detection

## Context

REQ-VBTW-009 (`openspec/specs/bookkeeping-vat-btw-filing/spec.md`) mandates
that material corrections to a filed BTW return be modelled as a
`VatCorrection` linked to the original `VatReturn`. The register is landed
(`lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` +
a second, more complete definition directly in the compiled monolith
`lib/Settings/shillinq_register.json`), the lifecycle (`draft → submitted →
accepted`/`rejected`) is declared, RBAC is wired, and the manifest already
has index + detail pages for `VatCorrection` (`src/manifest.json` lines
~1124-1167). **No code path ever creates one.** `grep -ril VatCorrection
lib/` returns only the register JSON.

Two additional pre-existing facts constrain the design:

1. **Two parallel VAT schema families exist.** `VATReturn` /
   `VATDeclaration` / `VATLine` (all-caps, `bookkeeping-vat-btw-filing.json`,
   requirement series `REQ-VAT-*` which is **not** present in
   `openspec/specs/` — i.e. an orphaned/superseded requirement series) is
   the schema `VATReturnService::deriveVATLines()` actually computes
   real per-rubriek totals against. `VatReturn` / `VatCorrection` /
   `VatTariff` (mixed-case, `add-shillinq-bookkeeping-operations.json`,
   requirement series `REQ-VBTW-*` which **is** the canonical spec) is the
   schema REQ-VBTW-009 talks about, but its declared `x-openregister-
   aggregations.rubrieken` sources `GLLine.vatRate` / `GLLine.reverseCharge`
   — fields that do not exist on `GLLine` (verified: `grep -n
   '"vatRate"\|"reverseChargeApplicable"'` across every register.d file
   shows both fields live only on `VATLine`, all-caps). The mixed-case
   `VatReturn`'s rubrieken aggregation is therefore currently
   unimplementable as declared, and no PHP service backs its `submit`
   transition's `OCA\Shillinq\Guard\VatSubmissionGuard::requireApproval`
   guard class (the class does not exist in `lib/`).
2. **The landed `VatCorrection` register itself has two conflicting field
   sets.** The compiled monolith's copy (`shillinq_register.json`, ~line
   17747) uses `originalVatReturnId` / `correctionAmount` / `reason` (states
   `draft/submitted/accepted/rejected`) — this exactly matches the spec
   prose and has a `reject`/`reopen` transition pair the fragment's copy
   lacks. The fragment's copy (`add-shillinq-bookkeeping-operations.json`,
   ~line 394) uses `originalReturnId` / `adjustmentAmount` /
   `correctionReason` (states `draft/submitted/accepted`, no `reject`).
   `SettingsService::deepMergeConfig()` deep-merges the fragment onto the
   monolith at runtime (base = monolith, overlay = fragment,
   `lib/Service/SettingsService.php` ~line 1333), and for a plain object key
   collision it recursively unions properties — so the **runtime-effective**
   `VatCorrection` schema carries **both** field-name variants, and its
   `required` array is the concatenation of both fragments' `required`
   lists (list values are `array_merge`d, not deduped). This is real,
   pre-existing behavior, not a bug this change introduces.

**Correction during research:** an earlier draft of this design believed
`VatReturn`/`VatCorrection` lacked audit coverage (REQ-VBTW-012). That was
wrong — `lib/Settings/register.d/add-shillinq-audit-trail.json` already
declares `x-openregister-audit-trail: { enabled: true, ... }` (the actual
canonical key/shape, confirmed against `tests/validate-registers.js`'s
`x-openregister-audit-trail.enabled` check and its 214/445-compliant
baseline) on both schemas. This fragment therefore does **not** touch the
audit flag at all — adding a second, differently-shaped declaration would
have been redundant at best and confusing at worst. The "audit trail of the
original filed figures" requirement is satisfied by the `filedSnapshot`
field this change adds (the data payload) plus OR's already-active
audit-trail on the object (the access/change log) — two complementary
concerns, both already covered.

## Goals / Non-Goals

**Goals:**
- Detect that a filed `VatReturn`'s GL-derived totals have drifted from
  what was filed.
- Compute the delta per rubriek (type × taxRate bucket).
- Decide against the €1.000 statutory grens whether the correction may ride
  the next return or needs a formal suppletie (informational — the operator
  decides, per REQ-VBTW-009's existing non-auto-decide requirement).
- Compile a `VatCorrection` draft with the diff, a filed-figures snapshot
  for audit, an 8-week filing-deadline stamp, and a **draft** GL correction
  posting.

**Non-Goals:**
- Unifying the two VAT schema families (flagged, not fixed).
- Building `VatSubmissionGuard` or otherwise wiring the mixed-case
  `VatReturn`'s `submit` transition (pre-existing gap, out of scope).
- SBR/Digipoort submission (REQ-VBTW-010, separate change).
- Auto-posting the GL correction (bookkeeper reviews and posts manually —
  `GLTransaction.post` already enforces the balance guard).
- Scheduling/cron-driving detection across all administrations (this change
  ships the callable engine only).

## Decisions

### Decision 1: Bridge the two schema families by reading from `VATReturn` (all-caps), writing to `VatCorrection` (mixed-case)

The only engine that computes real per-rubriek totals from GL data today is
`VATReturnService` against `VATReturn`/`VATDeclaration`/`VATLine`
(all-caps). The mixed-case `VatReturn`'s declared aggregation cannot run
(sources non-existent `GLLine` fields). Rather than duplicate GL-derivation
logic against the broken aggregation, `VatSuppletieDetectionService` takes
an all-caps `VATReturn.id` as input, re-uses `VATReturnService`'s
GL-scanning logic (via a new non-mutating `computeCurrentDeclarations()`
method added to `VATReturnService`, mirroring `deriveVATLines()` but
returning grouped totals in-memory instead of persisting), and persists the
result as a `VatCorrection` with `originalVatReturnId` pointing at the
`VATReturn.id`. This satisfies REQ-VBTW-009's literal requirement ("a
`VatCorrection`... link to the original return") using the return schema
that actually has computed data, and is documented here rather than
silently assumed. **Alternative considered:** implement the mixed-case
`VatReturn`'s rubrieken aggregation for real (add `vatRate`/`reverseCharge`
to `GLLine`) — rejected as materially larger scope (a GLLine schema change
ripples through every GL-consuming aggregation) than this change's mandate.

### Decision 2: Detection is a snapshot diff, not a live recomputation replacing the filed record

`VATReturnService::deriveVATLines()` only runs on `createReturn()` and
`rebaseReturn()` — nothing else touches `VATDeclaration` rows after
submission. This means the persisted `VATDeclaration` rows **are** the
"as filed" snapshot by construction; no separate snapshot-capture hook is
needed on submit. Detection recomputes the same grouping **without
persisting** and diffs bucket-by-bucket (key = `type:taxRate`) against the
persisted rows. **Alternative considered:** snapshot on submit into a new
field — rejected as redundant given the existing persistence already acts
as the snapshot, and simpler is less to get wrong.

### Decision 3: Status lifecycle layered on the existing states, not a new enum

The brief calls for `detected → prepared → filed`. The landed
`VatCorrection` lifecycle already has `draft → submitted → accepted/
rejected` with `submit`/`accept`/`reject`/`reopen` transitions and RBAC
gates. Adding new states would require editing the already-landed
lifecycle (risk of breaking the existing `thresholdWarning` precondition
and `onTransition.dispatchWorkflow`). Instead:
- **detected**: `VatSuppletieDetectionService::detect()` creates the
  `VatCorrection` in `draft` state with `filedSnapshot`/`currentSnapshot`
  populated but `preparedAt` null.
- **prepared**: `VatSuppletieDetectionService::prepare()` computes
  `rubriekDeltas`, `thresholdExceeded`, the GL correction posting, and sets
  `preparedAt` — still `draft`, now operator-reviewable and ready to file.
- **filed**: the operator uses the *existing, already-declared* `submit`
  transition (`draft → submitted`) — no new code needed, it is OR's
  lifecycle engine.
This reuses 100% of the already-landed lifecycle/RBAC/notification wiring
and only adds the two sub-states as data (a nullable timestamp), which is
the smallest change that satisfies the brief's status model.

### Decision 4: Imperative service, not declarative aggregation (ADR-031 exception)

Per ADR-031 the default is a declarative `x-openregister-{aggregations,
calculations}` block. This is explicitly the kind of logic ADR-031 carves
out for an imperative service: it (a) diffs two independently-queried
schemas (`VATDeclaration` snapshot vs. live GL recomputation) bucket by
bucket, (b) applies a business decision (€1.000 grens) that must remain
operator-overridable, and (c) writes a *new* record (`VatCorrection`) with
a *derived* GL posting (`GLTransaction`+`GLLine`) — cross-schema
compilation logic, not a single aggregation. This mirrors the precedent set
by `VATReturnService` itself (declarative aggregation declared for
documentation, imperative service actually computes it) and
`PeriodCloseAssistantService` (deterministic cross-schema detection,
imperative, glue-only).

### Decision 5: GL correction posting targets the rubriek's original account

The correction `GLTransaction` (created `draft`, never auto-posted) books
one `GLLine` per non-zero rubriek delta against the **same account** the
original `VATLine` for that rubriek posted to (resolved by re-running the
account lookup for the bucket), balanced by an offsetting line against a
configurable clearing account (`IAppConfig` key
`vatCorrectionClearingAccount`, default `1699` — no seeded "BTW te betalen"
account exists in the codebase today, documented as Risk 3 in the
proposal). This keeps the posting traceable to real accounts rather than
inventing a single suspense line for the whole correction.

## Risks / Trade-offs

- [Risk] The bridging decision (Decision 1) means `VatCorrection.
  originalVatReturnId` points at an all-caps `VATReturn`, while the schema's
  own description text says "VatReturn" (mixed-case) → Mitigation:
  documented explicitly here and in the proposal; the field is a plain
  string FK with no schema-level `$ref` enforcement, so this is not a
  validation-breaking choice, only a documentation-vs-reality gap that
  already existed before this change.
- [Risk] Writing both field-name variants (Decision on the dual-field
  schema) means a future cleanup that removes one variant will need to
  update this service too → Mitigation: both writes are confined to
  `VatSuppletieDetectionService::detect()` and `::prepare()` (the only two
  places a `VatCorrection` is constructed by this service), so the fix is
  two known call sites, not scattered ones.
- [Risk] The clearing-account default (`1699`) is a guess in the absence of
  seed data → Mitigation: configurable via `IAppConfig`, logged as a
  warning when the default is used, and documented as Risk 3.

## Migration Plan

Purely additive: new PHP service, new register fragment (additive fields
on `VatCorrection` only), new unit tests. No data migration. Deploy = merge
+ existing `SettingsService` re-import
picks up the fragment on next configuration load (same mechanism every
other `register.d/*.json` fragment uses). Rollback = revert the PR; no
existing field, state, or transition is altered.

## Seed Data

### Schema: `VatCorrection` (additive fields only — base seed objects already exist from `add-shillinq-bookkeeping-operations`)

| Field | Correction 1 | Correction 2 |
|---|---|---|
| `@self.register` | shillinq | shillinq |
| `@self.schema` | VatCorrection | VatCorrection |
| `originalVatReturnId` | (seeded Q1-2026 standard `VATReturn` id) | (seeded Q2-2026 reverse-charge `VATReturn` id) |
| `correctionAmount` / `adjustmentAmount` | 1450.00 | -320.00 |
| `reason` / `correctionReason` | "Voorbelasting Q1 2026 onderrapporteerd door invoerfout" / `underreporting` | "Dubbele boeking gecrediteerd" / `overreporting` |
| `thresholdExceeded` | true (above €1.000 grens — suppletie required) | false (below grens — may ride next return) |
| `preparedAt` | set (prepared, ready to file) | null (just detected, not yet reviewed) |
| `state` | draft | draft |

**Related items per object:** none (no Files/Notes/Tasks relations declared
on `VatCorrection`).

## Trade-offs

Considered building this purely declaratively by fixing the mixed-case
`VatReturn`'s aggregation (adding `vatRate`/`reverseCharge` to `GLLine`) and
letting OR's engine own the diff. Rejected for this change: it would touch
a T1 schema (`GLLine`) consumed by many other aggregations across the app,
is a materially larger and riskier change than "detect + compile a
suppletie", and the brief explicitly anticipates an imperative service
here. Documented as a follow-up rather than silently worked around.
