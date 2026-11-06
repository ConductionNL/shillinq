# Tasks: tax-digipoort-filing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Instances

- [ ] 1.1 Add `filingType` and `sourceReturnId` to `XbrlInstance`, `xbrlInstanceId` to `BtwAangifte`, and a seed `XBRLTaxonomy` record with the OB and KvK entry points (REQ-TDF-001, REQ-TDF-002). Verify: `npm run check:registers`; re-import with no failed schemas.
- [ ] 1.2 Add `lib/Service/Sbr/VatReturnXbrlBuilder.php` from the return's declarations (REQ-TDF-001). Verify: PHPUnit validates the output against the OB entry point schema kept in the repository.
- [ ] 1.3 Add `lib/Service/Sbr/AnnualAccountsXbrlBuilder.php` and `lib/Reporting/Generator/SbrXbrlReportGenerator.php` answering `sbr-xbrl` (REQ-TDF-002). Verify: PHPUnit on the seed statement; `ReportGenerationService` no longer logs "no generator" for `sbr-xbrl`.

## 2. Validation

- [ ] 2.1 Add `XbrlInstanceValidationGuard` as the `requires` of `validate` (REQ-TDF-003). Verify: PHPUnit for each failing item and a clean instance.

## 3. Hand-off

- [ ] 3.1 Add `IntegriqSbrHandoffAdapter` implementing `DigipoortSbrAdapterInterface`, emitting `nl.conduction.sbr.filing.requested`, and bind it when integriq is installed (REQ-TDF-004). Verify: PHPUnit asserts the event payload; the new classes use no `IClientService`, curl or hard-coded Digipoort host, as REQ-VBTW-010 asks.
- [ ] 3.2 Add `HandToDigipoortAction`, registered under `hand-to-digipoort` and declared on `XbrlInstance.submit`, refusing when the log adapter is bound (REQ-TDF-004). Verify: PHPUnit for integriq present and absent.
- [ ] 3.3 Add `SbrFilingStatusListener` for `nl.conduction.sbr.filing.status` requesting completion, `accept` or `reject` (REQ-TDF-004). Verify: PHPUnit with delivered, accepted and rejected payloads.
- [ ] 3.4 Make Submit on the VAT return build, validate and submit its instance, and mirror the instance state on the return (REQ-TDF-001). Verify: Playwright against integriq's log provider shows submitted and accepted.

## 4. Connection and pages

- [ ] 4.1 Change `digipoort-sbr` in `lib/Settings/connections.json` to `reportedOnly: true` and report its status daily (REQ-TDF-004). Verify: the external connections page reads simulated on a local instance with integriq and no connector.
- [ ] 4.2 Show filing type, Digipoort reference, errors and a download action on `SbrXbrlFilingDetail` (REQ-TDF-002, REQ-TDF-004). Verify: Playwright downloads the seed instance.

## 5. Platform request

- [ ] 5.1 Open an issue on ConductionNL/integriq for a Digipoort/SBR connector under `digipoort-sbr` with the proposed event contract, linking this change. Verify: the issue link is in the PR body; the text is shown to Ruben before it is posted.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/tax-digipoort-filing/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.
