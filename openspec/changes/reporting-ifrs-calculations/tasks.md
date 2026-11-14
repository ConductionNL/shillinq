# Tasks: reporting-ifrs-calculations

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Build

- [ ] 1.1 A `DualGaapDerivationService` that derives the other framework's entry and writes the bridge line on post (REQ-DGAAP-011). Verify: PHPUnit red first for the credit loss scenario.
- [ ] 1.2 An approve action on `ActuarialValuation` that computes and posts the IAS 19 entry (REQ-PEN-011). Verify: PHPUnit for the scenario; the entry balances.
- [x] 1.3 Call `LeaseDisclosureService::generateForPeriod` on period close and store the `LeaseDisclosureTable` row (REQ-LD-008). Verify: PHPUnit; the disclosures page lists the row. — `PeriodCloseService::closePeriod()` calls `LeaseDisclosureService::materialiseForPeriod()`, which stores one row per administration and period (a second close after a reopen replaces it). The disclosures page is the manifest index over `LeaseDisclosureTable`, so it lists the row; seeing it live is part of 1.4
- [ ] 1.4 English and Dutch strings, then live: close a seed year and read the three outputs.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
