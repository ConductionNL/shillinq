# Tasks: shillinq-accountant-portal-audience

## 1. Declare the audience
- [x] 1.1 In `lib/Portal/PortalContributionProvider.php`, change `getAudiences()`
      to return `['customer', 'supplier', 'accountant']`. Leave `getAudience()`
      returning `'customer'`.

## 2. Accountant manifest
- [x] 2.1 Add a private `accountantManifest(): array` returning label `'Shillinq'`
      with the seven read-only collections (`salesInvoices`→`ARInvoice`,
      `purchaseInvoices`→`SupplierInvoice`, `journalEntries`→`JournalEntry`,
      `generalLedger`→`GLTransaction`, `trialBalance`→`TrialBalance`,
      `vatReturns`→`VatReturn`, `financialStatements`→`FinancialStatement`), each
      `register: 'shillinq'`, `scopeField: 'administrationId'`,
      `scopeClaim: 'accountantAdministrationId'`, `listable: true`, plus
      `actions: []` and `notifications: []`.
- [x] 2.2 Add the `accountant` branch to `getContribution()` before the
      fail-closed `return null`.
- [x] 2.3 Verify each collection's schema actually declares `administrationId`
      (grep `lib/Settings/register.d` + `shillinq_register.json`); drop any that
      does not rather than emit a dead scope.

## 3. Tests
- [x] 3.1 Extend `tests/Unit/Portal/PortalContributionProviderTest.php`:
      `getAudiences()` returns the three-element list; the accountant manifest has
      the expected collection ids/schemas with `administrationId` /
      `accountantAdministrationId` scoping; `actions`/`notifications` are empty;
      an unknown audience still returns `null`; customer/supplier manifests are
      byte-for-byte unchanged.

## 4. Verify
- [x] 4.1 `composer test:unit` green.
- [x] 4.2 Confirm the class still constructs standalone (no portaliq import, no
      `implements`, no constructor) — REQ-SPC-000 preserved.
