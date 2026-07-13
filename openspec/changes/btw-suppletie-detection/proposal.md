---
kind: code
depends_on: []
---

# Proposal: btw-suppletie-detection

## Summary

Build the missing engine behind REQ-VBTW-009: a `VatSuppletieDetectionService`
that detects when a filed `VatReturn`'s underlying GL ledger has changed since
filing, computes the correction per BTW-rubriek, decides against the statutory
€1.000 grens whether the correction may ride the next regular return or
requires a formal suppletie-aangifte, and compiles a `VatCorrection` record
(per-rubriek deltas + a draft GL correction posting) with an auditable
before/after snapshot. Today the `VatCorrection` register and REQ-VBTW-009 are
landed but **no code ever creates a `VatCorrection`** — `grep -ril
VatCorrection lib/` returns only register JSON.

## Motivation

Dutch VAT law (Wet OB 1968 art. 14a) requires operators to correct a filed
return once the underlying ledger diverges from what was declared — and,
since 1 January 2025, requires the correction be filed **within 8 weeks of
discovery** once it exceeds the statutory threshold, with a penalty
("vergrijpboete", up to 100% of the underpaid amount) for late or missed
filings. Below the threshold, the correction may simply ride the next
regular return. Today shillinq has no way to even detect that a filed
return has drifted from its ledger, so operators have no signal to act on
and no compiled draft to review — the compliance obligation exists but the
tooling does not. This is flagged as the cheapest high-value gap on the
bookkeeping capability list: the register, lifecycle, and RBAC are already
landed (`add-shillinq-bookkeeping-operations` change); only the detection +
compilation logic is missing.

**Threshold source (WebSearch-verified against belastingdienst.nl,
2026-07-13):** "Hebt u bij uw btw-aangifte een bedrag van maximaal €1000 te
veel of te weinig ingevuld? Geef dit dan aan in uw eerstvolgende
btw-aangifte." Corrections above €1.000 require the suppletie form, and
(per the Belastingdienst's 2025 update) must be filed within 8 weeks of
discovery to avoid a "vergrijpboete". Source:
https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/btw/btw_aangifte_doen_en_betalen/aangifte_corrigeren/

## Affected Projects

- [x] Project: `shillinq` — new `VatSuppletieDetectionService` (imperative,
  justified below) that diffs a filed `VatReturn`'s rubrieken against the
  live GL, decides suppletie-eligibility against the €1.000 grens, compiles a
  `VatCorrection` draft with per-rubriek deltas + a draft GL correction
  posting, and stamps an 8-week filing deadline. Additive schema extension on
  the already-landed `VatCorrection` register (new fields only, no breaking
  changes) to hold the filed/current snapshot and detection metadata.

## Scope

### In Scope

- Detect drift between a `VatReturn`'s originally-filed per-rubriek totals
  (as computed by the existing `VATReturnService::deriveVATLines` /
  `VATDeclaration` grouping engine) and what the same GL data resolves to
  today.
- Compute the per-rubriek delta (type × taxRate buckets) and the net
  correction amount.
- Decide suppletie-eligibility against the €1.000 grens (informational only
  — the operator makes the final call per REQ-VBTW-009's existing
  `thresholdWarning` precondition; this service surfaces the computed
  decision, it does not silently auto-file).
- Compile a `VatCorrection` draft: per-rubriek deltas, net `correctionAmount`
  / `adjustmentAmount`, a filed-figures snapshot for audit, an 8-week filing
  deadline stamp, and a link back to the original return.
- Compile a companion **draft** GL correction posting (`GLTransaction` +
  balanced `GLLine`s) that books the delta — left in `draft` state for
  bookkeeper review/posting, never auto-posted.
- Status lifecycle across `detected` (record created, deltas not yet
  reviewed) → `prepared` (deltas computed + reviewed, ready to file) →
  `filed` (existing `submit` transition on `VatCorrection`, already
  declared) — layered on the already-landed `draft`/`submitted`/`accepted`/
  `rejected` state machine via a `preparedAt` timestamp rather than new
  states (see design.md).
- Unit tests for the diff/threshold/compilation logic.

### Out of Scope

- The actual SBR/Digipoort submission of the suppletie (REQ-VBTW-010 —
  already scoped as an OR `ScheduledWorkflow`, out of this change).
- Auto-triggering detection as a background job / cron (this change ships
  the detection + compilation engine and a callable entry point; wiring it
  to a scheduled sweep across all administrations is deferred — noted as a
  follow-up in Open Questions).
- Reconciling the two competing legacy VAT schemas (`VATReturn`/
  `VATDeclaration`/`VATLine` all-caps vs. `VatReturn`/`VatCorrection`
  mixed-case) — a pre-existing duplication documented in design.md and
  worked around, not fixed, here.
- Any change to the `VatCorrection` lifecycle's existing state enum,
  transitions, or RBAC — only additive fields are introduced.

## Approach

A new imperative `VatSuppletieDetectionService` (justified per ADR-031: this
is detection + compilation logic — a cross-schema diff against live GL data
plus a decision function — not expressible as a single declarative
aggregation) re-uses `VATReturnService`'s existing, working GL-derivation
engine to recompute "what the return would look like today", compares it
against the `VATDeclaration` rows persisted at filing time (which are stable
because nothing re-derives them outside of an explicit `rebase`), and
persists both snapshots plus the diff onto a new `VatCorrection` object via
OpenRegister's `ObjectService`. See design.md for the full architecture,
the dual-schema bridging decision, and the declarative-vs-imperative
rationale.

## New Dependencies

None.

## Impact

- New file `lib/Service/VatSuppletieDetectionService.php`.
- New file `lib/Settings/register.d/btw-suppletie-detection.json` (additive
  `VatCorrection` field extension: filed/current snapshots, detection
  metadata, GL correction posting link, filing-deadline stamp).
- New unit test `tests/Unit/Service/VatSuppletieDetectionServiceTest.php`.
- No changes to existing `VATReturnService`, `VatReturn`/`VatCorrection`
  lifecycle states, or manifest navigation (the `VatCorrection` index +
  detail pages are already wired).

## Cross-Project Dependencies

None — consumes only OpenRegister's `ObjectService` (already a shillinq
dependency) per ADR-022.

## Risks

### Risk 1: Two divergent VAT schema families already exist in the codebase
**Severity:** Medium — **Mitigation:** documented explicitly in design.md;
this change bridges pragmatically by reading GL-derived totals from the
working `VATReturn`/`VATDeclaration` engine while persisting the correction
under the spec-mandated `VatCorrection` register, and does not attempt to
unify the two families (that is a separate, larger cleanup).

### Risk 2: The landed `VatCorrection` register itself has two conflicting
field sets across `lib/Settings/shillinq_register.json` (base) and
`lib/Settings/register.d/add-shillinq-bookkeeping-operations.json`
(fragment), which the runtime deep-merge unions into a single object with
both field names present (e.g. both `correctionAmount` and
`adjustmentAmount`).
**Severity:** Medium — **Mitigation:** the service writes both field-name
variants with identical values so the record is valid and consistent
regardless of which name a UI or later cleanup reads; not attempting to
resolve the duplication in this change (out of scope, flagged for a
follow-up).

### Risk 3: No seeded "BTW te betalen" / VAT-payable GL account exists
**Severity:** Low — **Mitigation:** the correction-posting account number is
resolved from the accounts already used in the affected rubriek's original
`VATLine` postings (so it always targets a real, already-VAT-applicable
account); a configurable clearing-account fallback is documented in
design.md for the case where no such account can be resolved.

## Rollback Strategy

Revert the PR. The new service and register fragment are purely additive;
no existing schema fields, lifecycle states, or service methods are
modified, so a revert leaves the pre-existing (non-functional) `VatCorrection`
register exactly as it was.

## Open Questions

- Should detection run on a scheduled sweep (all `accepted` returns, all
  administrations) rather than being invoked per-return? Deferred — this
  change ships the callable engine; scheduling is a follow-up once a
  concrete trigger (nightly job vs. GL-post-time hook) is chosen.
