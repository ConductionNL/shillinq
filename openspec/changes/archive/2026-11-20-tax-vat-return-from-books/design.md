# Design: tax-vat-return-from-books

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**Three derivations, three schemas.**

1. `lib/Reporting/Generator/VatReturnReportGenerator.php` (the "BTW-aangifte"
   report card on `ReportingComplianceOverview`, reached through
   `POST /api/reporting/generate`) prefers a stored `VatReturnFiling`
   (`register.d/checks-remaining-vat.json`) and otherwise derives rubrieken
   from the period's `ARInvoice` rows in `deriveFromInvoices()` (line 179).
   Input tax (5b) only ever comes from the filing record (line 119). Its
   docblock says it contradicts REQ-VBTW-004 and points at #525.
2. `lib/Service/VATReturnService.php` (`VATReturnController`,
   `/api/vat-returns*`, `appinfo/routes.php:164` to `:182`) writes a
   `BtwAangifte` (`register.d/bookkeeping-vat-btw-filing.json:11`) with
   `VATDeclaration` and `VATLine` records. `scanRubrieken()` (line 335)
   walks posted GL lines on accounts with `vatApplicable`, classifies
   revenue accounts as collected and everything else as paid, and
   recalculates VAT as base times the account's `vatRate` (line 374). So it
   does derive input tax, which the matrix note misses, but from rates,
   not from the VAT actually posted, and with no rubriek. No Vue component
   calls `/api/vat-returns`; the `VATReturns` page
   (`src/manifest.d/bookkeeping-vat-btw-filing.json`, route `/vat-returns`)
   lists `BtwAangifte` but creating one there skips the derivation.
   `VatSuppletieDetectionService` (REQ-VBTW-013) builds on this service and
   writes `VatCorrection` records with filed and current snapshots.
3. `VatReturn` (`lib/Settings/shillinq_register.json:17830`) declares
   `x-openregister-aggregations.rubrieken` joining `GLLine` through
   `Account.vatTariffCode` to `VatTariff.rubriek` and filtering `GLLine`
   on `periodStart`/`periodEnd`. `Account` has no `vatTariffCode`,
   `VatTariff` has no `rubriek` and `GLLine` has neither period field, so
   the aggregation cannot produce a value. The `BtwAangiften` page
   (`/belastingen/btw-aangiften`) in the Taxes menu reads this schema.

REQ-VBTW-004 requires path 3 and forbids a service walking the GL; paths 1
and 2 are services, and only path 2 is close to the books.

**Checks.** `lib/Lifecycle/VatPreconditionGuard.php` checks invoices at
issue. `lib/Standards/Checks/VatChecks.php` registers invoice-content
checks with `RuleEngine::evaluate()` (`lib/Standards/RuleEngine.php:372`)
and says VAT-return rules are "intentionally not registered". The
`BtwAangifte.submit` transition has no `requires`.

**Broken years and unity.** `Administration.nonCalendarFiscalYear`
(`register.d/bookkeeping-multi-administratie.json:145`) marks a broken
year; `FiscalYear` records hold the actual start and end. Corrections are
listed on `BtwCorrecties` (`/belastingen/btw-correcties`) with no grouping.
`FiscaleEenheid` (`register.d/bookkeeping-vpb-mkb.json:928`) is the Vpb
unity, with the 95 percent ownership condition checked by
`VpbAangifteGuard::canVoegen` (`lib/Lifecycle/VpbAangifteGuard.php:185`).

## Goals / Non-Goals

**Goals**

- One return, prepared from what was booked, with output and input tax, that the page, the file and the correction check all agree on.
- A check list that says what is wrong before filing and blocks what must block.
- Corrections and their threshold per fiscal year when the year is broken.
- One return for a VAT fiscal unity.

**Non-Goals**

- Transport to Digipoort.
- Merging `VatReturn` into `BtwAangifte`.

## Decisions

### D1. The box is stamped on the line at posting

When the posting mappers write a line whose source line has a VAT tariff,
they set `GLLine.vatTariffCode`, `GLLine.vatReturnBox` from the tariff's
`section`, and `GLLine.vatAmountKind` base or vat: the revenue or cost line is the base,
the line on the output or input VAT account is the VAT. Input VAT lines go
to 5b. A reverse-charged purchase writes its base to 2a, 4a or 4b and the
same VAT amount to both the owed box and 5b. A backfill does the same for
posted lines from their tariff code, else from the account's VAT settings.

Alternative considered: resolve the box on read by joining through the
account, as the `VatReturn` aggregation tries. Rejected: one account
carries lines of several tariffs and both base and VAT, and the box at
posting time is what was declared.

### D2. `VATReturnService` prepares, and the snapshot is the truth

`scanRubrieken()` is rewritten to sum posted lines in the period by
`vatReturnBox` and `vatAmountKind`, as booked, and to write one
`VATDeclaration` per box and one `VATLine` per line, both carrying
`returnBox`. No rate is recalculated. The report generator renders from the
return's declarations and drops `deriveFromInvoices()`; the return page
shows the declarations; `VatSuppletieDetectionService` compares against
them as it already does. The `VATReturns` page gets "Prepare return",
which calls `createReturn`, and the Taxes menu entry "BTW returns" points
at it; the `BtwAangiften` page leaves the menu.

Alternative considered: keep REQ-VBTW-004 as written and make the
declarative aggregation work. Rejected: a filed return needs a stored,
per-line snapshot that does not move when the ledger does, because the
correction check (REQ-VBTW-013) compares against it; a live aggregation is
the wrong shape for that. REQ-VBTW-004 is modified accordingly.

### D3. Checks are a rule provider and a submit guard

`VatReturnChecks` registers checks for the object type `BtwAangifte` with
`RuleEngine`, each with an id, a severity (blocking or warning) and a
message: every VAT line has a box; posted VAT equals base times rate per
line within one cent; the output and input VAT accounts' period movement
equals the return totals; no draft or unposted document is dated in the
period; the previous period's return is filed; reverse-charge VAT appears
both owed and deductible. A "Checks" tab on `VATReturnDetail` runs them
and shows pass or fail. `VatReturnChecksGuard` becomes the `requires` of
`BtwAangifte.submit` and denies while a blocking check fails, naming it.

Alternative considered: run checks inside `submitReturn()`. Rejected: the
lifecycle transition can be requested without the service, and a guard is
where the transition's precondition belongs.

### D4. A broken year groups corrections by fiscal year

For an administration with `nonCalendarFiscalYear`, `BtwCorrecties` groups
corrections by the `FiscalYear` whose dates contain the corrected return's
period, and a "Check fiscal year" action runs the drift detection for every
accepted return in that year and totals the deltas. The EUR 1,000
threshold of REQ-VBTW-014 applies to that fiscal-year total, as the
Belastingdienst allows a correction per boekjaar when it is not the
calendar year.

### D5. A separate VAT fiscal unity

A new `VatFiscalUnity` schema holds the representative administration,
the member administrations, the unity's VAT number and its dates.
Preparing a return for the representative administration in a period the
unity covers scans the lines of every member, and each `VATLine` carries
its administration so the page shows a subtotal per member. Preparing a
return for a member in such a period is refused. The file uses the unity's
VAT number.

Alternative considered: reuse `FiscaleEenheid`. Rejected: that is the Vpb
unity, with a 95 percent ownership test and its own number; a VAT unity
rests on financial, organisational and economic ties and has a different
number, so one record cannot serve both.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which box a line belongs to | Declarative tariff `section`, stamped by the posting mapper | The tariff register holds the mapping. |
| Preparing the return snapshot | Imperative, `VATReturnService` | A stored per-line snapshot is a write, not an aggregation; REQ-VBTW-004 is modified to say so. |
| Checks before filing | Imperative check provider plus a declarative `requires` on `submit` | The engine exists; the lifecycle gates. |
| Fiscal unity membership | Declarative, `VatFiscalUnity` schema | Plain data. |
| Grouping corrections by fiscal year | Declarative manifest grouping plus the existing detection service | No new engine. |

## Seed Data

Adds `GLLine.vatTariffCode`, `GLLine.vatReturnBox`, `GLLine.vatAmountKind`, `returnBox` on
`VATDeclaration` and `VATLine`, and the `VatFiscalUnity` schema. Test data:
"Bakkerij De Korenbloem" in Q3 2026 with sales of EUR 10,000 at 9 percent
(EUR 900 VAT), purchases of EUR 4,000 at 21 percent (EUR 840 input VAT) and
EUR 2,000 at 9 percent (EUR 180 input VAT); "Holding Voorbeeld B.V." with
members "Voorbeeld Bouw B.V." and "Voorbeeld Installatie B.V." in a VAT
fiscal unity from 2026-01-01; "Stichting Voorbeeld" with a fiscal year from
1 July to 30 June.

## Risks / Trade-offs

- [Two return schemas remain] → the menu points at one; the other is named for the schema consolidation work.
- [Backfill cannot resolve old lines] → those lines fail the first check by name, instead of silently missing from the return.

## Migration Plan

A backfill stamps posted lines. Existing `BtwAangifte` records are left as
filed; a draft can be prepared again.

## Open Questions

None.
