# Tasks: hours-to-humaniq

## 1. Decide the split

- [ ] 1.1 Write `design.md` choosing between the derived-cost-line shape and
      the opaque-allocation shape described in `proposal.md`. Record why.
- [ ] 1.2 Confirm ADR-107 is promoted from Proposed to Accepted, or record
      that this change proceeds on a Proposed ADR and why that is safe.

## 2. Prove there is nothing to migrate

- [ ] 2.1 Add an `occ` command that counts `UrenRegistratie` rows with a
      non-null `subjectApp`. Report per administration.
- [ ] 2.2 Run it on the dev instance and on every reachable install. Record
      the counts in `design.md`. A non-zero count reopens task 1.
- [ ] 2.3 Only when every count is zero: proceed. Otherwise write a data
      migration first.

## 3. Move the hour

- [ ] 3.1 Humaniq writes case-scoped hours: `TimeEntry.domainObjectRef` +
      `domainObjectType` are stamped on create from the logging app.
- [ ] 3.2 Correct humaniq's `x-notes` example from `procest:case` to
      `dossiq:case`. The app id moved and nothing writes the field yet, so
      this costs nothing now and is unrecoverable once hours exist.
- [ ] 3.3 Shillinq reads hours from humaniq for the ledger, in the shape
      task 1.1 chose.

## 4. Repoint each consumer, statutory ones last

- [ ] 4.1 `SubjectCostAggregator` (1 reference). Smallest, and it proves the
      read path.
- [ ] 4.2 `FinancialDashboardService` (3 references). Reporting only, no tax
      position.
- [ ] 4.3 `InvoiceGenerationService` (2 references) and
      `TimeIntakeService` (10). Invoicing.
- [ ] 4.4 `WBSOExportValidationGuard` (5 references). Subsidy. Run old and
      new side by side over the same period and assert identical output
      before cutting over.
- [ ] 4.5 `UrencriteriumGuard` (2 references). A wrong 1225-hour count costs
      a real person a real deduction. Same side-by-side proof as 4.4.

## 5. Retire the dead fields

- [ ] 5.1 Delete `lib/Settings/register.d/uren-domain-subject-link.json`.
- [ ] 5.2 Delete `tests/Unit/Settings/UrenDomainSubjectLinkTest.php`.
- [ ] 5.3 Update the four affected specs: `invoice-from-time-and-expense`,
      `time-expense-invoice-intake`, `wbso-uren-tagging-and-export`,
      `zzp-urencriterium-tracker`.

## 6. Degrade honestly without humaniq

- [ ] 6.1 Every hours surface shows a named empty state when humaniq is
      absent. Never a zero. The dossiq KPI reporting 0 hours on every case
      is the bug this change exists to stop repeating.
- [ ] 6.2 Add a test that asserts the empty state, not the zero.
