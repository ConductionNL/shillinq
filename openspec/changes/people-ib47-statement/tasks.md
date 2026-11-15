# Tasks: people-ib47-statement

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Batch

- [ ] 1.1 An `ib47Recipient` flag on `Payee` and an `Ib47BatchService` in `lib/Service/` that sums a year's paid supplier invoices per flagged payee into one `IB47Record` each, idempotent on payee and year (REQ-DPA-021). Verify: PHPUnit red first for both scenarios.
- [ ] 1.2 A Prepare action on `IB47Jaarbatch` that calls the service through a route with an admin or role check (REQ-DPA-021). Verify: route-auth and IDOR gates.

## 2. Statement

- [ ] 2.1 An `ib47` entry in `lib/Reporting/ReportCatalogue.php` with a generator that writes the batch's records as the statement file (REQ-DPA-021). Verify: PHPUnit on the generated file.
- [ ] 2.2 English and Dutch strings, then live: mark a freelancer, pay two invoices in 2026, prepare the 2026 batch and download the statement.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
