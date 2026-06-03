# Tasks — Deferred Tax

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-deferred-tax` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm that `temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, and `tax-provision` schemas do not already exist (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)

- [x] Task 2: Author `specs/bookkeeping-deferred-tax/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (financial reporting)` / `Depends on: bookkeeping-general-ledger (T1), bookkeeping-vpb-mkb (T3)` header, REQ-DT-001 through REQ-DT-010 requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN; finalize language per Dutch/English GAAP nuance

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects (shillinq primary, openregister + downstream consumers), Scope (5 tax schemas, Account/FiscalPeriod extensions, 10 requirements), Risks (fiscal-regime complexity, recoverability subjectivity, rate-change edge cases, permanent-vs-temporary classification), Rollback (spec-only, safe), Open Questions (Pillar-2 aggregation scope, intercompany saldering, historical adjustments) per hydra configuration

- [x] Task 4: Author `design.md` with Decisions (D1 detection at balansdatum not incremental, D2 calculations schema-declared not service, D3 loss regime jurisdiction-scoped, D4 recoverability documented, D5 rate changes per-reversal-year, D6 permanent diffs in ETR only, D7 saldering per-jurisdiction gated by legal right) and Reuse Analysis table per ADR-031 patterns

- [x] Task 5: Declare five `tax` register schemas in `lib/Settings/shillinq_register.json`:
  - `temporary-difference` with fields: `period` (FK), `jurisdiction`, `account` (FK), `category` (enum: depreciation/provision/receivable-impairment/inventory-valuation/development-cost/fair-value-adjustment/lease-ifrs16/pension/other), `commercialCarryingAmount`, `taxCarryingAmount`, `temporaryDifference` (computed), `type` (taxable/deductible), `reversalPattern` (short-term/long-term/indefinite), `expectedReversalYear`, `taxRate`, `deferredTaxBalance` (computed), `notes`
  - `tax-loss-carry-forward` with fields: `jurisdiction`, `originatingYear`, `originalAmount`, `utilisedAmount`, `remainingAmount` (computed), `expirationYear`, `applicableRegime` (pre-2019/2019-2021-transition/2022-onwards), `dtaRecognised`, `dtaRecoverabilityRationale`, `recoverabilityHorizon`, `linkedProjections`
  - `tax-rate-reconciliation` with fields: `period` (FK), `jurisdiction`, `profitBeforeTax`, `statutoryRate`, `statutoryTaxExpense` (computed), `reconciliationItems[]` (array of {description, type, amount, taxEffect}), `effectiveTaxExpense` (computed), `effectiveTaxRate` (computed), `disclosureNarrative`
  - `deferred-tax-movement` with fields: `period` (FK), `jurisdiction`, `category`, `openingBalance`, `originatedInPeriod`, `reversedInPeriod`, `rateChangeAdjustment`, `acquiredViaBusinessCombination`, `translationAdjustment`, `recognisedInPL` (computed), `recognisedInOCI`, `closingBalance` (computed), `linkedJournalEntries[]`
  - `tax-provision` with fields: `period` (FK), `jurisdiction`, `currentTaxPayable`, `currentTaxPrepaid`, `dtaTotal`, `dtlTotal`, `netDtaDtlPosition` (computed), `presentationOnBalanceSheet` (gross/net), `linkedVpbReturn` (FK)

- [x] Task 6: Extend T1 `Account` schema additively with: `taxBasisDifferenceCategory` (optional enum: depreciation/provision/receivable-impairment/inventory-valuation/development-cost/fair-value-adjustment/lease-ifrs16/pension/other) — an optional hint for GL detection logic

- [x] Task 7: Extend `FiscalPeriod` schema additively with: `enactedTaxRates` (object/map: `{jurisdiction: {rate: decimal, effectiveDate: date}}`) to store tax rates effective on or after balansdatum for rate-change adjustment per REQ-DT-005

- [x] Task 8: Author `lib/Services/TaxCalculationService.php` with methods:
  - `detectTemporaryDifferences(FiscalPeriod, Administration): void` — reads GL balances per Account, applies category hints or manual category, detects and stores `temporary-difference` records
  - `compensateLosses(FiscalPeriod, Administration): void` — reads `tax-loss-carry-forward` records, applies jurisdiction-specific regime rules, generates compensation entries, updates `utilisedAmount` and `remainingAmount`
  - `assessRecoverability(FiscalPeriod, Administration): void` — reads DTA loss component, queries linked projections, validates `dtaRecoverabilityRationale` is present, adjusts DTA recognition percentage
  - `applyRateChanges(FiscalPeriod, Administration): void` — reads `enactedTaxRates`, re-measures deferred positions per reversal-year, records `rateChangeAdjustment`
  - `calculateTaxRateReconciliation(FiscalPeriod, Administration, jurisdiction): TaxRateReconciliation` — builds `tax-rate-reconciliation` record from temporary/permanent/rate-change components
  - `calculateMovement(FiscalPeriod, Administration, jurisdiction, category): DeferredTaxMovement` — computes opening/origination/reversal/rate/M&A/FX/closing per category
  - `calculateTaxProvision(FiscalPeriod, Administration, jurisdiction): TaxProvision` — aggregates all components, decides saldering, links to Vpb

- [x] Task 9: Wire the GL close process (likely in `bookkeeping-general-ledger` close / post hook) to invoke `TaxCalculationService::calculateAllPeriodEnd(FiscalPeriod, Administration)` after GL validation per REQ-DT-001 through REQ-DT-010

- [x] Task 10: Implement `tax-loss-carry-forward` compensation logic per Wet Vpb articles 8, 20, 20a:
  - Pre-2019 regime: 6-year expiration, 100% utilisation
  - 2019–2021 transition: hybrid rules (coordinate with tax specialist + law firm)
  - 2022+: unlimited with 50% cap above EUR 1M threshold
  - Test all three regime paths in PHPUnit with real Wet Vpb scenarios

- [x] Task 11: Link `tax-provision` to Vpb-aangifte via `linkedVpbReturn` (FK to `bookkeeping-vpb-mkb` record) for current-tax reconciliation per REQ-DT-010

- [x] Task 12: Ensure all deferred-tax records inherit OR's audit-trail-immutable; all changes (manual edits, system recalculation, rate adjustment) appear in `auditTrail` with user, timestamp, change description

- [x] Task 13: Add manifest navigation entry `Accounting > Taxes > Deferred Taxes` surfacing:
  - Index page for `temporary-difference` (filtered by period + jurisdiction)
  - Detail page for `tax-provision` (per-jurisdiction summary)
  - Index page for `tax-loss-carry-forward` (showing open losses per jurisdiction)
  - `type: index` / `type: detail` pages using generic `CnIndexPage` / `CnDetailPage` (no bespoke Vue)

- [x] Task 14: Declare `tax-rate-reconciliation` as `x-openregister-calculations` output (per ADR-031) so it is produced from schema metadata, not a PHP report service

- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with reconciliation notes introducing the five `tax` register schemas and extensions on `Account` / `FiscalPeriod`

## Verification

`openspec validate` must exit clean on the change folder. Financial-reporting domain expert (CFO or belastingadviseur persona) peer-reviews the requirement scenarios against IAS 12 and RJ 272. Architecture reviewer confirms ADR-031 compliance (calculations schema-declared; no parallel PHP service; manifest navigation generic rendering).

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

### PHPUnit (bookkeeping-focused)

- REQ-DT-001 scenarios: detect temporary differences on T1 GL balances with correct category hints
- REQ-DT-002 scenarios: permanent vs. temporary classification (dividend exemption, provision, depreciation)
- REQ-DT-003 scenarios: loss compensation per regime (pre-2019 6-year, transition, 2022+ 50%-cap); loss origination year, used, remaining balance
- REQ-DT-004 scenario: DTA recoverability percentage based on projection link
- REQ-DT-005 scenario: rate-change adjustment on expected-reversal-year diffs
- REQ-DT-006 scenario: ETR reconciliation from temporary + permanent + rate components
- REQ-DT-007 scenario: per-jurisdiction segregation (NL + DE); no cross-jurisdiction saldering
- REQ-DT-008 scenario: DTA/DTL netting decision per jurisdiction (gross vs. net presentation)
- REQ-DT-009 scenario: roll-forward opening + origination + reversal + rate + M&A + FX = closing
- REQ-DT-010 scenario: reconciliation of P&L tax expense vs. Vpb return

All scenarios SHALL be accompanied by Dutch values (EUR amounts, dates per 2026 calendar, actual Vpb tariffs per Belastingplan 2026).

### Playwright Browser Tests

No bespoke Vue components — manifest index/detail pages use generic rendering. Playwright tests focus on:
- Navigation to `Accounting > Taxes > Deferred Taxes` appears and is clickable
- `temporary-difference` index page loads, filters by period, renders table
- `tax-provision` detail page loads, displays DTA/DTL summary, shows saldering option
- `tax-loss-carry-forward` index filters by jurisdiction, shows regime label

### CI Gate

- `composer test` (PHPUnit + code coverage)
- `openspec validate` on change folder
- No new dependencies introduced (package.json, composer.json unchanged)

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/deferred-tax.md` (journeydoc per ADR-030) with sections:
  - Deferred tax basics (IAS 12 / RJ 272 overview)
  - Temporary difference categories (depreciation, provision, etc.)
  - Loss-compensation regimes (pre-2019, transition, 2022+)
  - Recoverability assessment (DTA on losses)
  - ETR reconciliation (permanent vs. temporary adjustments)
  - Multi-jurisdiction tracking
  - DTA/DTL presentation (gross vs. net)
  - Workflow (GL close → automatic deferred-tax calculation → manual review → disclosure in jaarrekening)
- Screenshot of `temporary-difference` list, `tax-provision` detail, ETR reconciliation
- Example walkthrough: a 2026 year-end with provision, depreciation, loss carry-forward, and rate change

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds:

- Dutch (`nl_NL`) and English (`en_US`) translation strings for:
  - `Deferred Tax`, `Uitgestelde belasting`
  - `Temporary Difference`, `Tijdelijk verschil`
  - `Tax-loss Carry-forward`, `Compensabele verliezen`
  - `Tax Rate Reconciliation`, `ETR-aansluiting`
  - `Deferred Tax Asset`, `Uitgestelde belastingvordering`
  - `Deferred Tax Liability`, `Uitgestelde belastingverplichting`
  - `Depreciation` (category), `Afschrijving`
  - `Provision`, `Voorziening`
  - `Receivable Impairment`, `Waardevermindering vorderingen`
  - `Fair Value Adjustment`, `Herwaardering`
  - `Applicability Regime` (pre-2019, transition, 2022+) with Dutch equivalents
  - `Recoverability Rationale`, `Toelichting terugwinbaarheid`
  - All UI labels, form fields, table headers
