# Tasks: tax-vat-return-from-books

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 12. -->

## 1. Lines carry their box

- [ ] 1.1 Add `vatTariffCode`, `vatReturnBox` and `vatAmountKind` to `GLLine`, and `returnBox` to `VATDeclaration` and `VATLine` (REQ-VBTW-004). Verify: `npm run check:registers`; re-import with no failed schemas.
- [ ] 1.2 Stamp the three fields in the posting mappers from the line's tariff, including reverse charge to the owed box and 5b (REQ-VBTW-004). Verify: PHPUnit for a sale, a purchase, a split purchase and a reverse-charged purchase.
- [ ] 1.3 Add a backfill repair step for posted lines from the tariff code, else the account's VAT settings, logging lines it cannot resolve (REQ-VBTW-004). Verify: repair run twice on a local instance stamps the seed lines once.

## 2. One derivation

- [ ] 2.1 Rewrite `VATReturnService::scanRubrieken()` to sum stamped lines by box and kind as booked, writing declarations and lines with `returnBox` (REQ-VBTW-004). Verify: PHPUnit on the Korenbloem seed gives 1b EUR 10,000 and EUR 900, 5b EUR 1,020.
- [ ] 2.2 Make `VatReturnReportGenerator` render from the return's declarations and remove `deriveFromInvoices()`; point its `@spec` at `bookkeeping-vat-btw-filing` now that the code follows REQ-VBTW-004 (REQ-VBTW-004). Verify: PHPUnit that the file equals the snapshot; hydra gate spec-coverage passes on the file.
- [ ] 2.3 Add "Prepare return" to `VATReturns`, repoint the Taxes menu entry "BTW returns" to it, and remove `BtwAangiften` from the menu (REQ-VBTW-004). Verify: nav reachability gate passes; Playwright prepares Q3 2026.

## 3. Checks

- [ ] 3.1 Add `lib/Standards/Checks/VatReturnChecks.php` with the six checks and their severities, registered for `BtwAangifte` (REQ-TVRB-001). Verify: PHPUnit per check, pass and fail.
- [ ] 3.2 Add `VatReturnChecksGuard` as the `requires` of `BtwAangifte.submit`, registered in `Application.php` (REQ-TVRB-001). Verify: PHPUnit with the real guard interface; a live submit with a failing check is refused.
- [ ] 3.3 Add the Checks tab on `VATReturnDetail` (REQ-TVRB-001). Verify: Playwright shows a failed and a passed check on seed data.

## 4. Broken year

- [ ] 4.1 Group `BtwCorrecties` by fiscal year for administrations with `nonCalendarFiscalYear`, and add "Check fiscal year" running the detection for the year's accepted returns with the threshold on the total (REQ-TVRB-002). Verify: Playwright on Stichting Voorbeeld shows one 2025/2026 group with four returns.

## 5. Fiscal unity

- [ ] 5.1 Add the `VatFiscalUnity` schema with index and detail pages, and member scanning and the member refusal in `VATReturnService::createReturn()` (REQ-TVRB-003). Verify: PHPUnit for a representative, a member and a period outside the unity; Playwright shows three subtotals.

## 6. Docs

- [ ] 6.1 Release note: one derivation, totals may differ from before and why, the checks, the unity. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/tax-vat-return-from-books/tasks.md#task-N` on every new method, Dutch and English strings for every check message and label.
