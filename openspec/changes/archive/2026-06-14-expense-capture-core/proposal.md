# Proposal: expense-capture-core

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`Receipt`, `MileageEntry`, `PerDiem`) +
`x-openregister-lifecycle` + approval workflow integration for
expense processing. No PHP expense-service classes are authored
(subject to ADR-031 exception: at most one single-method validation
guard if the engine cannot express multi-currency or photo validation
conditions).

## Summary

Introduce the **expense capture (core)** capability for Shillinq,
spanning receipt photo upload, manual expense entry, multi-currency
support, mileage tracking, per-diem allowances, and project tagging.
This change declares the `Receipt`, `MileageEntry`, `PerDiem`, and
`ExpenseClaimEntry` registers; the expense lifecycle consuming OR's
approval-workflow per ADR-022 (no app-local approval table);
multi-currency conversion at capture time; country-specific per-diem
rates; and mileage rate calculation per kilometre travelled. The
capability materialises a balanced ledger entry per posted expense
claim, linking to the general ledger for cost centre allocation.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(materialises GL transactions for expense posting),
[`add-shillinq-multi-currency`](../add-shillinq-multi-currency/proposal.md)
(FX conversion at time of capture).

## Motivation

Expense tracking is a P0 feature across 17 of 26 competitors per
market intelligence. Receipt photo upload + OCR, mileage tracking
with auto-calculated rates, and per-diem daily allowances are the
top three expense-capture vectors. Shillinq's bookkeeping remit
requires tying every reimbursable outlay back to a GL account and
cost centre for management reporting and VAT recovery audit trails.

The legacy expense-capture cluster from intelligence-db (`competitor_features`
with `app_slug=expense_management`) calls out receipt photo + OCR,
mileage auto-rate calculation, country-specific per-diem rules, and
project cost allocation as standard features. Per ADR-022,
approval routing comes from OR, not from an app-local table.

This is one of the core T2 capability changes; this proposal scopes
only the expense capture core (receipt, mileage, per-diem), deferring
OCR to a future T3 enhancement.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`expense-capture-core`); declares 4 new registers
  (`Receipt`, `MileageEntry`, `PerDiem`, `ExpenseClaimEntry`) with
  lifecycles, calculations, and aggregations; adds 3 manifest
  navigation entries (Receipts, Expense Claims, Mileage Log).
- [ ] Project: openregister — no source changes; consumes existing
  approval-workflow (ADR-022), `x-openregister-lifecycle`,
  `x-openregister-calculations`. Multi-currency conversion at
  capture time leverages existing `multi-currency` capability.
- [ ] Project: nc-vue — uses standard Upload and Photo components
  for receipt capture.

## Scope

### In Scope

- One new capability spec (`expense-capture-core`) — see
  the `specs/` folder.
- The `Receipt` register storing uploaded photos, extracted text
  (OCR placeholder), amount, date, and category.
- The `MileageEntry` register capturing distance, rate per km,
  and auto-calculated total per journey (from/to addresses).
- The `PerDiem` register for daily travel allowances using
  country-specific rates (NL, FI, etc. per competitor evidence).
- The `ExpenseClaimEntry` register grouping N receipts, mileage
  entries, and per-diem records into a submitted claim for approval.
- The expense lifecycle (`draft → submitted → approved → posted → reimbursed`)
  consuming OR's approval-workflow per ADR-022.
- Multi-currency capture: receipt amounts in foreign currency
  converted to base (EUR) at time of upload using multi-currency
  exchange rates.
- Materialisation: on `ExpenseClaimEntry.post`, a balanced GL entry
  with reimbursable-expense lines + cost-centre allocation.
- Aggregation: expense report by category, date range, and cost centre.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Receipt OCR / text extraction** — T3 enhancement. T2 stores the
  photo and placeholder fields; T3 invokes OCR on upload.
- **Bank statement matching** — T4 capability, requires bank
  connector state machine.
- **Advanced tax recovery analysis** — future phase; T2 carries VAT
  coding but does not automate VAT filing decisions.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`expense-capture-core`** — declares the four registers, the
lifecycle (consuming OR approval-workflow), multi-currency
conversion, country-specific per-diem rates, mileage auto-rate
calculation, and materialisation path to GL cost-centre posting.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-EC-*` for
traceability.

## New Dependencies

- **multi-currency capability** — for FX conversion at capture
  (existing, leveraged).
- **approval-workflow (ADR-022)** — for expense-claim approval
  routing (existing, leveraged).

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas
  (`Receipt`, `MileageEntry`, `PerDiem`, `ExpenseClaimEntry`);
  declares lifecycle on `ExpenseClaimEntry`, calculations on
  `MileageEntry.totalAmount`, `PerDiem.allowanceAmount`,
  aggregation on expense report.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: at most one
  single-method guard if the engine cannot express multi-currency
  conversion or photo validation conditions).
- Standard Vue upload components for receipt photo.

## Cross-Project Dependencies

- **OpenRegister** — depends on approval-workflow (ADR-022),
  `x-openregister-lifecycle` (ADR-031), `x-openregister-calculations`
  (ADR-031), `x-openregister-aggregations` (ADR-031).
- **multi-currency** — depends on exchange-rate lookup at time
  of receipt capture.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for the materialised GL posting pattern.

## Risks

### Risk 1: Multi-currency conversion requires live exchange rate data

**Severity**: Medium
**Mitigation**: The multi-currency capability already manages
exchange-rate snapshots per fiscal period. Receipt capture looks up
the rate for the upload date; if rate is unavailable, operator is
prompted to enter manual rate or use the prior day's rate. Spec
shape remains neutral to rate source.

### Risk 2: Per-diem rates are country-specific and change annually

**Severity**: Low
**Mitigation**: Per-diem rates (e.g., Netherlands €125/day,
Finland €45/day) are master-data administered per FiscalYear. T2
carries the rate in `PerDiem.rate`; T3 adds automatic rate-table
maintenance per IRS/government updates. Operator manually selects
country and nights; system applies the configured rate.

### Risk 3: Mileage rate calculation requires address geocoding

**Severity**: Low
**Mitigation**: T2 accepts manual distance entry (operator enters km
travelled or copy-pastes a route distance from maps); T3 adds
optional address-to-address geocoding integration. Spec carries both
`manualDistance` and `routeDistance` fields, neutral to integration
timing.

### Risk 4: Photo upload validation (file type, size) requires PHP guard

**Severity**: Low-Medium
**Mitigation**: If OR's calculations cannot express file-type and
size validation, ADR-031's exception path applies: a single-method
`OCA\Shillinq\Validation\PhotoValidator::validate(UploadedFile
$file): bool` ships, ~30 LOC, cited in spec.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — expense claims remain queryable but
unreferenced.

## Open Questions

1. **Multi-currency rate source** — see Risk 1; resolved in
   `opsx-ff` discovery against multi-currency capability's rate
   snapshots.
2. **Photo upload storage** — S3, local FS, or docudesk integration?
   Resolved during the implementing cycle's deployment-architecture
   review.
3. **Per-diem rate maintenance** — manual admin interface or
   government-data import? Resolved during implementing cycle.
4. **Mileage verification** — accept manual distance, require
   address-pairs for verification, or integrate maps API? Resolved
   in implementing cycle per compliance requirements.
