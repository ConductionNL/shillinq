# Tasks: tax-vat-return-from-books

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12. -->

## 1. Lines carry their box

- [x] 1.1 Add `vatTariffCode`, `vatReturnBox` and `vatAmountKind` to `GLLine`, and `returnBox` to `VATDeclaration` and `VATLine` (REQ-VBTW-004). Verify: `npm run check:registers`; re-import with no failed schemas.
      DONE (b6fdad347): `lib/Settings/register.d/tax-vat-return-from-books.json` (GLLine, VATDeclaration and
      VATLine at 0.2.0); `check:registers` passes. The live re-import is not re-run here.
- [ ] 1.2 (sale, purchase and split purchase done and tested; the reverse-charged purchase is half done) Stamp the three fields in the posting mappers from the line's tariff, including reverse charge to the owed box and 5b (REQ-VBTW-004). Verify: PHPUnit for a sale, a purchase, a split purchase and a reverse-charged purchase.
      State 2026-11-13: `VatLineStamper` plus `MaterialiseGlTransactionAction`, tested by
      `testASaleAtTwoRatesBooksVatPerTariffWithItsBox`, `testAPurchaseInvoiceBooksExpenseVatAndPayables` and
      `testASplitPurchaseBooksInputVatPerTariffInBox5b`. A reverse-charged purchase books its base in 2a, 4a or 4b
      (`testAReverseChargedPurchasePutsItsBaseInTheBoxOfItsTariff`), but not the self-assessed VAT in the owed box
      and 5b that design D1 asks for. That needs a rate: every reverse-charge tariff in
      `lib/Settings/seeds/btw-tariffs-2026.json` has rate 0, and a purchase line carries only its tax code. Open
      decision: where the rate of reverse-charged VAT comes from (a rate on the line, or a paired rate tariff).
- [x] 1.3 Add a backfill repair step for posted lines from the tariff code, else the account's VAT settings, logging lines it cannot resolve (REQ-VBTW-004). Verify: repair run twice on a local instance stamps the seed lines once.
      DONE (185ccd289): `lib/Repair/StampVatReturnBoxes.php` over `VatLineBackfill`, registered in info.xml;
      `VatLineBackfillTest::testLinesWithATariffGetTheirBoxOnceAndASecondRunWritesNothing` covers the second run.
      The run on a live instance is not repeated here.

## 2. One derivation

- [x] 2.1 Rewrite `VATReturnService::scanRubrieken()` to sum stamped lines by box and kind as booked, writing declarations and lines with `returnBox` (REQ-VBTW-004). Verify: PHPUnit on the Korenbloem seed gives 1b EUR 10,000 and EUR 900, 5b EUR 1,020.
- [x] 2.2 Make `VatReturnReportGenerator` render from the return's declarations and remove `deriveFromInvoices()`; point its `@spec` at `bookkeeping-vat-btw-filing` now that the code follows REQ-VBTW-004 (REQ-VBTW-004). Verify: PHPUnit that the file equals the snapshot; hydra gate spec-coverage passes on the file.
- [ ] 2.3 Add "Prepare return" to `VATReturns`, repoint the Taxes menu entry "BTW returns" to it, and remove `BtwAangiften` from the menu (REQ-VBTW-004). Verify: nav reachability gate passes; Playwright prepares Q3 2026.

## 3. Checks

- [x] 3.1 Add `lib/Standards/Checks/VatReturnChecks.php` with the six checks and their severities, registered for `BtwAangifte` (REQ-TVRB-001). Verify: PHPUnit per check, pass and fail.
      DONE 2026-11-14: a ledger-reading service rather than a RuleEngine provider, because the engine's checks see one
      object and these read the period's lines, the VAT accounts and the previous return. `VatReturnChecks::run()`
      answers id, severity, passed and message for `vat-line-has-box` (blocking), `vat-equals-base-times-rate`
      (warning), `vat-accounts-match-return` (blocking), `no-unposted-documents` (warning), `previous-return-filed`
      (warning) and `reverse-charge-owed-and-deductible` (blocking); books that cannot be read fail every check.
      Messages in English and Dutch. Tests: `tests/Unit/Standards/Checks/VatReturnChecksTest.php` (8).
- [x] 3.2 Add `VatReturnChecksGuard` as the `requires` of `BtwAangifte.submit`, registered in `Application.php` (REQ-TVRB-001). Verify: PHPUnit with the real guard interface; a live submit with a failing check is refused.
      DONE 2026-11-14 (code and PHPUnit; the live submit is not run here): `VatReturnChecksGuard` implements
      `LifecycleGuardInterface` and denies naming the first failing blocking check, or when the checks cannot run;
      `BtwAangifte.submit` declares `requires: OCA\\Shillinq\\Lifecycle\\VatReturnChecksGuard::check`, bound by
      `VatReturnGuardServices` through `GuardTagServices` (called from `Application::register()`); BtwAangifte 0.1.1.
      Tests: `tests/Unit/Lifecycle/VatReturnChecksGuardTest.php` (4).
- [ ] 3.3 Add the Checks tab on `VATReturnDetail` (REQ-TVRB-001). Verify: Playwright shows a failed and a passed check on seed data.

## 4. Broken year

- [ ] 4.1 Group `BtwCorrecties` by fiscal year for administrations with `nonCalendarFiscalYear`, and add "Check fiscal year" running the detection for the year's accepted returns with the threshold on the total (REQ-TVRB-002). Verify: Playwright on Stichting Voorbeeld shows one 2025/2026 group with four returns.

## 5. Fiscal unity

- [ ] 5.1 Add the `VatFiscalUnity` schema with index and detail pages, and member scanning and the member refusal in `VATReturnService::createReturn()` (REQ-TVRB-003). Verify: PHPUnit for a representative, a member and a period outside the unity; Playwright shows three subtotals.

## 6. Docs

- [ ] 6.1 Release note: one derivation, totals may differ from before and why, the checks, the unity. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/tax-vat-return-from-books/tasks.md#task-N` on every new method, Dutch and English strings for every check message and label.
