# Tasks — Vpb Corporate Tax

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-vpb-corporate-tax` spec — recorded now so spec-review
> and dependency planning are visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `vpbPligtig` flag on Account, no `VpbBalansLink` overlay, and no `bookkeeping-vpb-corporate-tax` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-vpb-corporate-tax/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation — Vpb)` / `Depends on: bookkeeping-bbv-compliance, bookkeeping-market-government-separation` header, `REQ-VPB-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table; Vpb-belastingadviseur reviewer persona confirms the Vpb-balans shape matches Wet modernisering Vpb-plicht
- [x] Task 5: Add `vpbPligtig: boolean` flag on `Account` in `lib/Settings/shillinq_register.json` (default `false`) per REQ-VPB-001
- [x] Task 6: Declare the `VpbBalansLink` overlay register with `costCenterId` FK (to `CostCenter` with `ondernemingsActiviteit: true`), `accountNumbers` array of `Account.accountNumber` strings, `vpbPligtigVanaf` date per REQ-VPB-002
- [x] Task 7: Declare the Vpb-balans aggregation (output `VpbBalansFiltered` with `schema:Dataset` annotation) filtering `GLLine` on `accountNumber IN VpbBalansLink.accountNumbers` AND `periodId IN fiscalYearPeriods`, grouped per `costCenterId`, producing Activa/Passiva/Resultaat per ondernemingsactiviteit per REQ-VPB-003
- [x] Task 8: Honour the T1 balance invariant (REQ-GL-005) per cost-center in the Vpb-balans; surface unbalanced ondernemingsactiviteiten as warnings
- [x] Task 9: Register the Vpb-aangifte voorbereiding docudesk template in `lib/Settings/docudesk-templates.json` populated from the Vpb-balans aggregation per REQ-VPB-004; SBR payload binding validates against the Belastingdienst Vpb XSD
- [x] Task 10: Wire the SBR aangifte transmission to ride the T4-base `bookkeeping-sbr-xbrl-reporting` SBR endpoint; no new SBR client per ADR-019
- [x] Task 11: Add aggregation invariant warning for orphaned Vpb-pligt (a Vpb-pligtige account not referenced by any `VpbBalansLink`) in the Vpb menu detail view
- [x] Task 12: Add Vennootschapsbelasting navigation + pages to `src/manifest.json` (`featureFlags.mkb-vpb`, `Bookkeeping > Vennootschapsbelasting`, `type: index` for Vpb-pligtige cost-centers/accounts + `type: detail` for Vpb-balans + aangifte voorbereiding per ondernemingsactiviteit) per REQ-VPB-005; `node tests/validate-manifest.js` exits 0
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `vpbPligtig` + `VpbBalansLink` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. Vpb-
belastingadviseur reviewer persona walks through a worked example
(5 Vpb-pligtige accounts on one ondernemingsactiviteit, €50k net
result; Vpb-balans shows €50k with balanced Activa/Passiva).
Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032
compliance (no app-local Vpb-service; SBR transmission rides T4-base
path). No source code changes outside
`openspec/changes/add-shillinq-vpb-corporate-tax/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for Vpb-balans filter correctness,
balance invariant per cost-center, SBR payload XSD validation,
orphaned-Vpb-pligt warning (pre-declared on Tasks 5–11); Playwright
MCP browser tests for the Vpb pages (pre-declared on Task 12);
`composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors `docs/user-guide/bookkeeping/mkb/vpb/vpb-administratie.md`
per ADR-030 journeydoc convention and commits screenshots of the
Vpb-balans + aangifte voorbereiding to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Vennootschapsbelasting`, `Vpb-pligtig`,
`Vpb-balans`, `Aangifte`, `Aangifte voorbereiding`, `Activa`,
`Passiva`, `Resultaat`, `Vpb-pligtig vanaf`.
