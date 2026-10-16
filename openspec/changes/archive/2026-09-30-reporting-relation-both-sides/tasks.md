# Tasks: reporting-relation-both-sides

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Link

- [x] 1.1 Add `payeeId`, `payeeLink` and `dismissedPayeeSuggestions` to `CustomerMaster` in `lib/Settings/register.d/reporting-relation-both-sides.json` with a one-to-one guard (REQ-RRBS-001). Verify: `npm run check:registers`; PHPUnit for a second customer linking the same payee.
- [x] 1.2 Add `RelationLinkService` with normalised KvK and VAT matching, confirm and dismiss, and the link picker on `CustomerDetail` (REQ-RRBS-001). Verify: PHPUnit for a KvK match, a VAT match with different spacing, a dismissed pair.

## 2. Read

- [x] 2.1 Add `RelationBothSidesService` (ARInvoice, APTransaction, SupplierInvoice under the caller's RBAC, credit notes negative, five totals, paging) and `RelationController` with the administration check first (REQ-RRBS-002, REQ-RRBS-004). Verify: PHPUnit for the Reclamebureau Zuid totals and for a caller without AP read.
- [x] 2.2 Add `forAdministration()` and the CSV export (REQ-RRBS-003). Verify: PHPUnit for three relations and the CSV columns.

## 3. Pages

- [ ] 3.1 (Pages built and checked by check:manifest and vitest; Playwright written, not run: no live instance in this lane.) Add the Both sides section to `CustomerDetail` and `PayeeDetail`, and the Relations both ways card, report page and suggestions list (REQ-RRBS-002, REQ-RRBS-003). Verify: `npm run check:manifest`; Playwright on the seeded relation with a bookkeeper and with an ar-controller.

## 4. Docs

- [x] 4.1 User guide page on linked relations and the report. Verify: the page in `docs/` linked from the report's `documentationUrl`.
- [x] 4.2 Note in the PR body that `APInvoice.vendorId` points at a `VendorMaster` schema that does not exist, for the purchasing changes of this OpenSpec pass. Verify: the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/reporting-relation-both-sides/tasks.md#task-N` on every new method, English source strings with Dutch translations.
