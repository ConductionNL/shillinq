---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — Wave-1 ADR-046 provider class (customer + supplier audiences) + unit tests (kind: code)

## Purpose

Shillinq contributes customer and supplier sections to portaliq, the shared
external portal for people without Nextcloud accounts (hydra ADR-046 +
2026-07-06 amendment, contribution contract v2). The contribution is one
plain, dependency-free provider class
(`OCA\Shillinq\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) declaring read-only OpenRegister collections scoped by
verified UUID domain references and matched against per-app claims
(`claims.shillinq.customerId` / `claims.shillinq.supplierId`). Wave 1 of the
ADR-046 fleet rollout (tracking: Conduction/shillinq#365).
## Requirements

Detailed requirements (REQ-SPC-001 … REQ-SPC-005) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
verified scoping map, claim-names contract, administrationId tenancy note,
and all exclusions (ARInvoice, PaymentRequest, dunning, goods receipts) live
in the change's
[`design.md`](../../changes/portal-contribution/design.md).

### Requirement: Shillinq contributes to portaliq via one plain duck-typed provider (REQ-SPC-000)

Shillinq MUST expose its entire portal contribution through the single plain
class `OCA\Shillinq\Portal\PortalContributionProvider` — duck-typed by
portaliq, dependency-free, inert without portaliq — declaring read-only,
UUID+claim-scoped collections for the `customer` and `supplier` audiences and
nothing else (no portal UI, routes, or endpoints inside shillinq). Normative
detail: REQ-SPC-001 … REQ-SPC-005 in the active change's delta spec.

#### Scenario: Provider is the only portal surface

- GIVEN the shillinq codebase at this capability's HEAD
- WHEN the portal contribution is inspected
- THEN it consists solely of `lib/Portal/PortalContributionProvider.php` (plus its unit tests) with no portaliq import, no info.xml dependency, and no shillinq-side portal route or UI
- @e2e exclude backend-only contract class; the external portal surface is rendered and e2e-tested in portaliq, not in shillinq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

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

