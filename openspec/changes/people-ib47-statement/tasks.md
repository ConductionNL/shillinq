# Tasks: people-ib47-statement

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Batch

- [x] 1.1 An `ib47Recipient` flag on `Payee` and an `Ib47BatchService` in `lib/Service/` that sums a year's paid supplier invoices per flagged payee into one `IB47Record` each, idempotent on payee and year (REQ-DPA-021). Verify: PHPUnit red first for both scenarios.
  - `lib/Settings/register.d/people-ib47-statement.json`: `Payee.ib47Recipient`, `Payee.bsn` (11-proef, masked, payroll officer only) and `Payee.ib47PaymentTypeCode`, which the statement needs; `IB47Record.payeeId`, which makes a rerun find its record.
  - `lib/Service/Ib47/Ib47BatchService.php`: paid means a payment run in `reconciled` (the bank confirmed it); the year is the line's requested execution date, else the run's. A recipient without BSN, address or payment type code is returned as `incomplete` and gets no record. A payee whose payments dropped to nothing keeps its record at zero.
  - `tests/Unit/Service/Ib47/Ib47BatchServiceTest.php`: both scenarios with their own numbers, plus confirmed-runs-only, other administration, incomplete recipient. Red when the update-in-place or the year filter is removed.
- [ ] 1.2 A Prepare action on `IB47Jaarbatch` that calls the service through a route with an admin or role check (REQ-DPA-021). Verify: route-auth and IDOR gates.

## 2. Statement

- [ ] 2.1 An `ib47` entry in `lib/Reporting/ReportCatalogue.php` with a generator that writes the batch's records as the statement file (REQ-DPA-021). Verify: PHPUnit on the generated file.
- [ ] 2.2 English and Dutch strings, then live: mark a freelancer, pay two invoices in 2026, prepare the 2026 batch and download the statement.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
