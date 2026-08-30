# Design: shillinq-accountant-portal-audience

## Context

`lib/Portal/PortalContributionProvider.php` is Shillinq's Wave-1 ADR-046
contribution: a plain, duck-typed, dependency-free class declaring read-only,
claim-scoped OpenRegister collections for the `customer` and `supplier`
audiences. This change adds a third audience, `accountant`, for the external
bookkeeper — the top-recurring competitor differentiator, and absent today.

## Decisions

### D1 — Scope by `administrationId`, not by a party row

Customer/supplier collections scope by a *party* reference on each row
(`customerReference` / `supplierId`) matched to `claims.shillinq.customerId` /
`supplierId`. An accountant is not a party to the transactions — they are
authorised over a whole **administration**. So the accountant collections scope
by the row's `administrationId` (the tenancy key every reviewed schema already
carries — verified on `ARInvoice`, `SupplierInvoice`, `APTransaction`,
`JournalEntry`, `GLTransaction`, `TrialBalance`, `VatReturn`,
`FinancialStatement`, `Contract`) against a new claim
`claims.shillinq.accountantAdministrationId`. An accountant authorised for two
client administrations carries both UUIDs; portaliq's claim matching (multi-value,
as it already does for the customer surface) returns only those administrations'
rows.

### D2 — Read-only, mirroring Wave-1

Portaliq is read-only this wave (ADR-046). The accountant manifest therefore
declares `actions: []` and `notifications: []`, exactly like the customer and
supplier manifests. Write-side accountant collaboration (posting adjustments,
correction requests) is a materially larger design (authz, dual-control, audit)
and is deliberately deferred — flagged as future work, not stubbed.

### D3 — Collections chosen for a books review

The seven baseline collections are what an external boekhouder opens to review
and file: sales invoices (AR), purchase invoices (AP), the journal, the general
ledger, the trial balance, the VAT returns, and the financial statements. All
seven are read surfaces the accountant already expects from Moneybird/Exact/Yuki.
`Contract` and bank-reconciliation surfaces are candidate additions but are left
out of the minimum set to keep the first accountant wave tight; they can be added
without a contract change (pure manifest data).

### D4 — Provider stays plain and inert

Per REQ-SPC-000 the class remains import-free, `implements`-free, and inert
without portaliq. Adding an audience is a one-line array change plus one private
manifest method plus one `getContribution()` branch — no new dependency, no
`info.xml` change, no registration in `Application.php`.

## Cross-app dependency (not authored here)

The `accountant` audience must exist in the shared ADR-046 audience vocabulary on
the portaliq/hydra side, and the `accountantAdministrationId` claim must be
provisioned for an external accountant (bridged from the internal
`accountant_extern` authorisation on `AdministrationMembership`). This change is
the **per-app requirements source** for that coordination (the same pattern
`first-time-setup` uses as the source for the central nc-vue setup change). Until
portaliq consumes it, the provider is inert for the accountant audience — no
behavioural risk.

## Non-goals

- No write/adjustment capability (deferred wave).
- No new schema, no `administrationId` additions (all reviewed schemas already
  carry it).
- No portaliq/hydra-side audience registration or claim provisioning (separate
  repo, separate change).
