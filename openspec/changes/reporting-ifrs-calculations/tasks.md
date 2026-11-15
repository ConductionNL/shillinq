# Tasks: reporting-ifrs-calculations

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Build

- [ ] 1.1 A `DualGaapDerivationService` that derives the other framework's entry and writes the bridge line on post (REQ-DGAAP-011). Verify: PHPUnit red first for the credit loss scenario.
- [x] 1.2 An approve action on `ActuarialValuation` that computes and posts the IAS 19 entry (REQ-PEN-011). Verify: PHPUnit for the scenario; the entry balances. — `ActuarialValuationApprovedListener` reacts to the `approve` transition and calls `PensionValuationPostingService::postForValuation()`: it reads the year's `PensionMovement` of the plan, computes net interest (discount rate × opening net liability) and the asset remeasurement (actual − expected return) when the movement does not carry them, writes the P&L and OCI totals and the entry back onto the movement, and posts one balanced `GLTransaction` (P&L and OCI against the net pension liability; accounts `pension_cost_account`, `pension_remeasurement_oci_account`, `pension_liability_account`, placeholder defaults 4130/0560/0810). Booked once per valuation.
- [x] 1.3 Call `LeaseDisclosureService::generateForPeriod` on period close and store the `LeaseDisclosureTable` row (REQ-LD-008). Verify: PHPUnit; the disclosures page lists the row. — `PeriodCloseService::closePeriod()` calls `LeaseDisclosureService::materialiseForPeriod()`, which stores one row per administration and period (a second close after a reopen replaces it). The disclosures page is the manifest index over `LeaseDisclosureTable`, so it lists the row; seeing it live is part of 1.4
- [ ] 1.4 English and Dutch strings, then live: close a seed year and read the three outputs.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
