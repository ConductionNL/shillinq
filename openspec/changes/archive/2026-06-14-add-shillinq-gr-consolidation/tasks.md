# Tasks — GR Consolidation

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-gr-consolidation` spec — recorded now so spec-review
> and dependency planning are visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `GRDeelnemer`, `GRVerdeelsleutel`, or `bookkeeping-gr-consolidation` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-gr-consolidation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-bbv-compliance, bookkeeping-financial-statements` header, `REQ-GRC-NNN` requirements using RFC 2119, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table; BBV-reviewer persona confirms the consolidation + doorbelasting shape matches actual GR-praktijk
- [x] Task 5: Declare the `GRDeelnemer` schema in `lib/Settings/shillinq_register.json` with `deelnemerType` (gemeente / provincie / waterschap), `deelnemerNaam`, optional `administrationId` FK, `aandeel` (0 ≤ x ≤ 1 with validation), `actief` boolean per REQ-GRC-001
- [x] Task 6: Declare the `GRVerdeelsleutel` schema with `sleutelNaam`, `costClusterAccountNumbers` (array of accountNumbers), `verdelingsType` enum (vast-percentage / inwoner-aantal / gewogen-oppervlak / custom-formula), `parameters` JSON validated against the chosen type, `lineNumber` for sleutel sequencing per REQ-GRC-002
- [x] Task 7: Add `eliminationFlag: boolean` field on `GLLine` (default `false`) per REQ-GRC-003; declare the consolidated trial-balance aggregation with `WHERE eliminationFlag = false` clause
- [x] Task 8: Declare the per-deelnemer doorbelasting aggregation grouped by resolved deelnemer per applicable `GRVerdeelsleutel`, producing per-cost-cluster bedrag per deelnemer per REQ-GRC-004
- [x] Task 9: Wire the cross-administration materialisation: when GR period closes (T3 `bookkeeping-period-close` lifecycle hook) and a deelnemer has `administrationId` set, materialise a balanced 2-line `GLTransaction` in that administration with `sourceReference` to the GR's doorbelasting-rapport per REQ-GRC-005; idempotency key `(grAdministrationId, deelnemerId, periodId)`
- [x] Task 10: Add aggregation invariant warning when sum of `aandeel` across `actief: true` deelnemers ≠ 1.0; warning surfaces in the Consolidated view (non-blocking)
- [x] Task 11: Add Gemeenschappelijke regeling navigation + 3 sub-pages to `src/manifest.json` (`featureFlags.gov-gr`, `Bookkeeping > Gemeenschappelijke regeling`, three `type: index` sub-pages for Deelnemers, Verdeelsleutels, Consolidated view) per REQ-GRC-006; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `GRDeelnemer` + `GRVerdeelsleutel` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. BBV-
reviewer persona confirms the deelnemer / verdeelsleutel / doorbelasting
shape matches real GR-praktijk and walks through a worked example
verifying the consolidated trial balance matches the deelnemer-side
doorbelastingen. Architecture reviewer confirms ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance (no app-local consolidation service;
cross-admin write audited in both audit-trails). No source code
changes outside `openspec/changes/add-shillinq-gr-consolidation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for verdeelsleutel-driven apportionment
across multiple deelnemers, eliminations-filtered aggregation,
balanced cross-admin GLTransaction materialisation, idempotency of
GR period-close re-run, aandeel-sum invariant warning (pre-declared
on Tasks 5–10); Playwright MCP browser tests for the three
sub-pages with the feature flag toggled (pre-declared on Task 11);
`composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/gov-gr/consolidation.md` per ADR-030
journeydoc convention and commits screenshots of all three sub-pages
to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Gemeenschappelijke regeling`, `Deelnemer`,
`Verdeelsleutel`, `Aandeel`, `Quotum`, `Doorbelasting`,
`Eliminatie`, `Geconsolideerd`, `Inwoner-aantal`, `Gewogen oppervlak`.
