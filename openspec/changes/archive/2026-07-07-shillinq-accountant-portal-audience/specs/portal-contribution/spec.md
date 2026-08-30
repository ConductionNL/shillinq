# Spec: portal-contribution (delta)

## ADDED Requirements

### Requirement: Provider declares the accountant audience (REQ-SPC-010)

The provider MUST declare `accountant` as a third audience, so
`getAudiences()` returns `['customer', 'supplier', 'accountant']`. The v1
`getAudience()` fallback MUST remain `'customer'` (v1 registries serve a single
audience; the primary customer surface is unchanged). Adding the accountant
audience MUST NOT alter the customer or supplier manifests, and the class MUST
stay plain and dependency-free (no imports from portaliq, no `implements`, inert
without portaliq — REQ-SPC-000 is preserved).

#### Scenario: getAudiences includes accountant

- **WHEN** `getAudiences()` is called on a constructed provider
- **THEN** it returns exactly `['customer', 'supplier', 'accountant']`
- **AND** `getAudience()` still returns `'customer'`, which is contained in that list
- @e2e exclude backend-only contract method with no shillinq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Adding the audience does not change existing manifests

- **WHEN** `getContribution()` is called for the `customer` audience and for the `supplier` audience
- **THEN** both return the same manifests as before this change (same collections, scopeFields, and scopeClaims)
- @e2e exclude backend-only contract behaviour — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Accountant subjects receive the read-only administration-review manifest (REQ-SPC-011)

`getContribution(array $subject)` MUST, for `$subject['audience'] === 'accountant'`,
return a declarative manifest labelled `'Shillinq'` whose collections are the
financial-review surfaces an external bookkeeper needs, all register `shillinq`,
all `listable`, all scoped by `scopeField: 'administrationId'` (the row's
tenancy key) against `scopeClaim: 'accountantAdministrationId'` (the UUID of an
administration the accountant is authorised for). The manifest MUST be pure data
(no callbacks, no I/O). Every collection's schema MUST actually declare an
`administrationId` property, so the scope resolves to a real field. The manifest
MUST include at minimum:

- `salesInvoices` — schema `ARInvoice`;
- `purchaseInvoices` — schema `SupplierInvoice` (or `APTransaction`);
- `journalEntries` — schema `JournalEntry`;
- `generalLedger` — schema `GLTransaction`;
- `trialBalance` — schema `TrialBalance`;
- `vatReturns` — schema `VatReturn`;
- `financialStatements` — schema `FinancialStatement`.

It MUST NOT include any collection that lacks an `administrationId` scope field
(no cross-administration leakage), and MUST NOT include a customer- or supplier-
scoped collection whose only scope is `customerReference` / `supplierId`.

#### Scenario: Accountant subject receives the administration-scoped manifest

- **WHEN** `getContribution($subject)` is called with `$subject['audience']` `'accountant'`, a `subjectRef` UUID, an organisation, and a trust level
- **THEN** it returns a manifest labelled `'Shillinq'` whose collections include `salesInvoices` (`ARInvoice`), `purchaseInvoices` (`SupplierInvoice`), `journalEntries` (`JournalEntry`), `generalLedger` (`GLTransaction`), `trialBalance` (`TrialBalance`), `vatReturns` (`VatReturn`), and `financialStatements` (`FinancialStatement`)
- **AND** every collection carries `register` `'shillinq'`, `scopeField` `'administrationId'`, and `scopeClaim` `'accountantAdministrationId'`
- @e2e exclude manifest is consumed and rendered by portaliq, not by any shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: An accountant only sees administrations they are authorised for

- **WHEN** portaliq resolves the accountant manifest for a subject whose `claims.shillinq.accountantAdministrationId` lists administration `A` but not administration `B`
- **THEN** only rows whose `administrationId` equals `A` are returned, and no row belonging to administration `B` is visible
- @e2e exclude scope enforcement is portaliq/OpenRegister claim-matching, not a shillinq UI surface — covered by PHPUnit at the manifest-declaration level (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: The accountant surface is read-only this wave (REQ-SPC-012)

The accountant manifest MUST declare `actions: []` and `notifications: []` — no
write, adjustment, correction-request, or posting capability is contributed in
this ADR-046 Wave. Any accountant collaboration that requires write access
(posting adjustments, requesting corrections) is out of scope and MUST NOT be
introduced through the portaliq read manifest. An unknown audience (anything
other than `customer`, `supplier`, `accountant`) MUST still fail closed
(`getContribution()` returns `null`).

#### Scenario: Accountant manifest exposes no write actions

- **WHEN** the accountant manifest is returned
- **THEN** its `actions` array is empty and its `notifications` array is empty
- @e2e exclude backend-only manifest data — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Unknown audience still fails closed

- **WHEN** `getContribution()` is called with an audience that is not `customer`, `supplier`, or `accountant`
- **THEN** it returns `null` (no manifest, no leakage)
- @e2e exclude fail-closed backend contract — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
