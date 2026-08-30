# Change: shillinq-accountant-portal-audience

> Depends on: `portal-contribution` (active, Wave-1 ADR-046 provider — this
> change extends its `getAudiences()` from two audiences to three).
> Coordinates with: a portaliq/hydra-side registration of the `accountant`
> audience in the shared ADR-046 audience vocabulary (noted, not authored here).

## Why

An **external accountant / bookkeeper portal** is the single most-repeated
differentiator across every finance competitor Shillinq maps to. In the Specter
intelligence corpus the "accountant portal" feature recurs for Moneybird, Exact
Online, Yuki, Jortt, and Odoo Accounting — "accountant access portal with
separate login for external bookkeeper", "accountant sharing with dedicated
bookkeeper access portal", "accountant portal with read/write access to client
administrations" — and it is repeatedly tagged `core` / `differentiator`. For a
Dutch bookkeeping product this is table stakes: almost every ZZP'er and MKB uses
an external boekhouder who needs to see the books without being given a full
Nextcloud account.

Shillinq today serves **two** portaliq audiences — `customer` (AR side) and
`supplier` (AP side) — via `lib/Portal/PortalContributionProvider.php`
(`getAudiences()` returns exactly `['customer', 'supplier']`; `getContribution()`
fail-closes on any other audience). There is **no `accountant` audience**. An
external accountant has no read surface at all.

Note: an *internal* `accountant_extern` role already exists
(`lib/Settings/register.d/bookkeeping-multi-administratie.json:387`, consumed by
`AdministrationContextService`) for a Nextcloud-account holder with cross-
administratie access. That is **not** the same thing: the competitor feature —
and this change — is the *external, no-Nextcloud-account* accountant who reaches
the administration through portaliq, the shared external portal.

This change adds a third portaliq audience, `accountant`, with a **read-only**
manifest scoped to the administration(s) the accountant is authorised for —
respecting the Wave-1 ADR-046 posture (portaliq is read-only this wave; no write
actions) and the fleet abstraction model (portaliq owns the portal, OpenRegister
owns the scoping; Shillinq only declares the manifest). Write access —
posting adjustments, requesting corrections — is explicitly deferred to a later
collaboration wave.

## What Changes

- **ADDED** `REQ-SPC-010` — the provider SHALL declare a third audience
  `accountant` (extending Wave-1's `customer` + `supplier`), keeping the v1
  `getAudience()` fallback unchanged.
- **ADDED** `REQ-SPC-011` — `accountant` subjects SHALL receive a read-only
  manifest of the administration's financial-review collections, each scoped by
  `administrationId` (the row's tenancy key) against the accountant's authorised-
  administration claim.
- **ADDED** `REQ-SPC-012` — the accountant surface SHALL be read-only this wave
  (`actions: []`, `notifications: []`); no write/adjustment capability is
  contributed.
- `lib/Portal/PortalContributionProvider.php` — `getAudiences()` returns
  `['customer', 'supplier', 'accountant']`; a new `accountantManifest()` private
  method; `getContribution()` gains an `accountant` branch. Plain, dependency-
  free class unchanged in shape (no imports, no `implements`, inert without
  portaliq — per REQ-SPC-000).
- Unit test coverage for the accountant branch mirrors the existing
  customer/supplier tests.

## Impact

- Affected spec: `portal-contribution` (ADDED `REQ-SPC-010/011/012`).
- Affected code: one provider class (one array literal + one method + one
  branch) plus its unit test. No schema edits — the scoped schemas
  (`ARInvoice`, `APTransaction`/`SupplierInvoice`, `JournalEntry`,
  `GLTransaction`, `TrialBalance`, `VatReturn`, `FinancialStatement`, `Contract`)
  already carry `administrationId`.
- **Dependency (out of scope here):** portaliq/hydra must recognise the
  `accountant` audience in the shared ADR-046 vocabulary, and the claim
  `claims.shillinq.accountantAdministrationId` must be provisioned for an
  external accountant (derived from the internal `accountant_extern`
  authorisation). Until then the provider is inert for this audience — exactly
  like the Wave-1 customer/supplier surfaces before portaliq consumes them.
- Supersedes the Wave-1 "exactly two audiences" snapshot in the active
  `portal-contribution` change's REQ-SPC-002 once both are applied (audience
  list grows to three).
