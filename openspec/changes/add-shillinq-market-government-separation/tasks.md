# Tasks — Markt en Overheid Separation

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-market-government-separation` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `ondernemingsActiviteit` flag, `algemeenBelangBesluit` overlay, or `bookkeeping-market-government-separation` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [ ] Task 2: Author `specs/bookkeeping-market-government-separation/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-cost-centers-dimensions` header, `REQ-MGS-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table; Mededingingswet-reviewer persona confirms the kostprijs + transparantie flow matches praktijk
- [ ] Task 5: Add `ondernemingsActiviteit: boolean` flag on `CostCenter` in `lib/Settings/shillinq_register.json` (default `false`) per REQ-MGS-001; views materialise with `schema:Service` type annotation
- [ ] Task 6: Declare the integrale-kostprijs `x-openregister-calculations` block on `CostCenter` summing direct costs + allocated overhead via configurable verdeelsleutel + equity compensation (configurable percentage on deployed equity) per REQ-MGS-002
- [ ] Task 7: Declare the tarieven-vs-kostprijs aggregation comparing realised revenue per ondernemingsactiviteit with integrale kostprijs; under-cost-recovery results surface a warning per REQ-MGS-003
- [ ] Task 8: Declare the `algemeenBelangBesluit` overlay schema with `besluitNummer`, `besluitDatum`, `geldigheidsperiode`, `motivering` (docudesk attachment URI), `getrokkenBedrag` per REQ-MGS-004; valid besluiten suppress the under-cost-recovery warning
- [ ] Task 9: Wire the warning-suppression logic declaratively — when an `algemeenBelangBesluit` with covering `geldigheidsperiode` exists, the warning is suppressed and an informational banner cites the besluit number; both events log to audit-trail-immutable
- [ ] Task 10: Add Markt en Overheid navigation + pages to `src/manifest.json` (`featureFlags.gov-markt-overheid`, `Bookkeeping > Markt en Overheid`, `type: index` per ondernemingsactiviteit showing direct costs / overhead / equity comp / integrale kostprijs / revenue / margin / status + `type: detail` per cost-center) per REQ-MGS-005; `node tests/validate-manifest.js` exits 0
- [ ] Task 11: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `ondernemingsActiviteit` + `algemeenBelangBesluit` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder.
Mededingingswet-reviewer persona walks through a worked example —
ondernemingsactiviteit with €100k direct costs, €20k overhead, 4%
equity comp on €50k → kostprijs €122k; realised revenue €100k →
warning €22k under-cost-recovery; valid algemeen-belang-besluit
suppresses warning. Architecture reviewer confirms ADR-022 + ADR-024
+ ADR-031 + ADR-032 compliance. No source code changes outside
`openspec/changes/add-shillinq-market-government-separation/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for integrale-kostprijs calculation
correctness, under-cost-recovery warning trigger, besluit
suppression behaviour (pre-declared on Tasks 6–9); Playwright MCP
browser tests for the transparantieadministratie view (pre-declared
on Task 10); `composer test` green at the implementing PR's CI
gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/gov-markt-overheid/transparantie.md`
per ADR-030 journeydoc convention and commits a screenshot of the
transparantieadministratie view to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Markt en Overheid`,
`Ondernemingsactiviteit`, `Integrale kostprijs`, `Tariefdekking`,
`Algemeen-belang-besluit`, `Vergoeding eigen vermogen`,
`Overheadverdeling`, `Transparantieadministratie`.
