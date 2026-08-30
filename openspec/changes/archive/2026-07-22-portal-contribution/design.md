# Design: portal-contribution

## Architecture Overview

Portaliq (hydra ADR-046) is the one shared external portal for people without
Nextcloud accounts. Domain apps contribute by shipping a single plain class at
a convention FQCN; portaliq's registry resolves
`OCA\{App}\Portal\PortalContributionProvider` per installed app and duck-types
it (`method_exists`, never `instanceof`). Shillinq adds exactly one new file
under `lib/Portal/` and touches nothing else in the runtime app:

```
portaliq (if installed)
  └─ registry resolves OCA\Shillinq\Portal\PortalContributionProvider (FQCN)
       └─ getAudiences() → ['customer', 'supplier']   (v2, preferred)
       └─ getAudience()  → 'customer'                 (v1 fallback, primary)
       └─ getContribution($subject) → manifest (pure data) or null
            ├─ audience 'customer' → Invoice / BillableInvoice / Quote /
            │    SalesOrder / Contract, scoped by the row's customer UUID ref
            │    matched against claims.shillinq.customerId
            └─ audience 'supplier' → PurchaseOrder / SupplierInvoice, scoped
                 by the row's supplierId (Payee UUID) matched against
                 claims.shillinq.supplierId
```

Without portaliq the class is never instantiated: inert dead weight of ~2 KB
by design (A1). Deliberately **no** DI registration in
`lib/AppInfo/Application.php` — discovery is entirely pull-based from
portaliq's side. The v1 fallback returns `'customer'` because a v1 registry
supports a single audience and the customer surface is the primary one; the
supplier surface then only exists on contract-v2 registries.

## Declarative-vs-imperative decision

The contribution is **declarative by nature**: `getContribution()` returns a
pure-data manifest (label, collections, actions, notifications) that portaliq
interprets — the same philosophy as the ADR-024 app manifest and ADR-031
declarative business logic. No behaviour, no I/O, no callbacks live in the
provider; the only imperative surface is the audience branch on the
server-derived `$subject`. A provider *class* (rather than a JSON file) is
used only because it is the delivery vehicle ADR-046 mandates: autoloadable
cross-app without file-path coupling, discoverable by FQCN, and able to branch
per audience without portaliq parsing app-private config. Everything portaliq
renders or enforces (scoping, claims, trust, RBAC) is data in the manifest,
evaluated portaliq-side.

## Verified scoping map

Every entry verified against `lib/Settings/shillinq_register.json` +
`lib/Settings/register.d/*.json` at HEAD (register slug `shillinq`; schema
slugs are the PascalCase values below, confirmed via each schema's `slug`
key). Quoted descriptions are verbatim from the defining fragment.

### Audience `customer` — scopeClaim `customerId` (bare name → `claims.shillinq.customerId`)

| Collection id | Schema slug | scopeField | Verified property description | Defining fragment |
|---|---|---|---|---|
| `invoices` | `Invoice` | `customerReference` | "FK (slug/uuid) to the customer (Nextcloud contact / AR customer master)" — required | `bookkeeping-quote-order-invoice.json` |
| `projectInvoices` | `BillableInvoice` | `customerId` | "FK to the customer (Nextcloud contact entity)" — required | `invoice-from-time-and-expense.json` |
| `quotes` | `Quote` | `customerReference` | "FK (slug/uuid) to the customer — a Nextcloud addressbook contact / AR customer master" — required | `bookkeeping-quote-order-invoice.json` |
| `salesOrders` | `SalesOrder` | `customerReference` | "FK (slug/uuid) to the customer (Nextcloud contact / AR customer master)" — required in the Q2C shape | `bookkeeping-quote-order-invoice.json` |
| `contracts` | `Contract` | `customerId` | "FK to the Nextcloud-synced contact/customer (NC addressbook entity per ADR-022); never an invented customer schema" — required | `bookkeeping-ifrs15-revenue.json` |

### Audience `supplier` — scopeClaim `supplierId` (bare name → `claims.shillinq.supplierId`)

| Collection id | Schema slug | scopeField | Verified property description | Defining fragment |
|---|---|---|---|---|
| `purchaseOrders` | `PurchaseOrder` | `supplierId` | "FK to the Vendor (supplier) the order is issued to" — Payee record per `openspec/specs/bookkeeping-purchase-order-3way/spec.md` ("FK to Payee (vendor organization)") — required | `bookkeeping-purchase-order-3way-01-schemas-and-registers.json` |
| `supplierInvoices` | `SupplierInvoice` | `supplierId` | "FK to the Vendor (supplier) that issued the invoice" — required; `statusCode` carries the 3-way-match/lifecycle outcome | `bookkeeping-purchase-order-3way-01-schemas-and-registers.json` |

No `via` joins ship in Wave 1: the only candidates terminated on a non-UUID
property (PaymentRequest, below) or required an array-valued hop
(GoodsReceiptNote, below).

## Claim-names contract

Portal subjects are people; `subjectRef` is the portal person's own UUID, not
a shillinq record UUID. The subject boundary inside shillinq's data is the
customer/vendor **domain record UUID** on each row, so every collection
declares a bare-name `scopeClaim` resolving in shillinq's own namespace:

- `claims.shillinq.customerId` MUST hold the UUID of the customer domain
  record — the Nextcloud contact / AR customer master referenced by
  `Invoice.customerReference`, `BillableInvoice.customerId`,
  `Quote.customerReference`, `SalesOrder.customerReference`, and
  `Contract.customerId`. It is NOT `CustomerMaster.customerId` (that field is
  an internal customer *code* — see Exclusions).
- `claims.shillinq.supplierId` MUST hold the UUID of the `Payee` (vendor)
  record referenced by `PurchaseOrder.supplierId` and
  `SupplierInvoice.supplierId`.

Issuing these claims (onboarding an external person and binding them to a
customer/vendor record) is portaliq-side work; a subject without the claim
matches no rows — fail-closed.

## Multi-administration note (administrationId ≠ organisation)

Shillinq is multi-administration: every row carries `administrationId`, an FK
to the owning Administration — **shillinq-internal tenancy**, scoping which
bookkeeping a row belongs to. It is not the portal subject boundary and must
not be conflated with portaliq's `organisation`:

- The subject boundary is the customer/vendor UUID scope property declared
  per collection above; a customer of two administrations legitimately sees
  their invoices from both.
- Portaliq's per-row `organisation` check only applies when rows carry an
  `organisation` property; shillinq rows do not, so that check is inert here.
- `administrationId` stays server-side data. It is not declared as a
  scopeField anywhere and portaliq never matches on it in Wave 1.

## Trust levels

All collections ship at the default trust (low): `minTrust` is omitted. These
are financial documents, so the documented Wave-2 posture is to raise the
invoice-bearing collections (`invoices`, `projectInvoices`,
`supplierInvoices`, `purchaseOrders`) to `minTrust: 'substantial'` once the
eHerkenning broker lands and `substantial` subjects actually exist — raising
it now would empty the portal for every subject. Read-only manifests plus
UUID+claim scoping bound the interim exposure.

## Exclusions (verified, with reasons)

- **`ARInvoice`** — its `customerId` is "FK to CustomerMaster.customerId",
  and `CustomerMaster.customerId` is an "Internal customer code"
  (`add-shillinq-bookkeeping-compliance.json`): a business code, not a UUID
  domain reference. The contract requires UUID scoping properties. Deferred
  to Wave 2: either AR gains a UUID customer reference or the contract gains
  verified code-valued claim matching.
- **`PaymentRequest`** (`ar-invoice-payment-links.json`) — carries no
  customer property; its only party linkage is `invoiceReference` ("FK to the
  ARInvoice (UUID or slug) being paid"), so the honest one-hop `via` join
  terminates on `ARInvoice.customerId` — the same non-UUID code as above. It
  also embeds `paymentLink` (a signed-token payment URL). Deferred with
  ARInvoice.
- **`DunningNotice`** (`bookkeeping-accounts-payable-core.json`) — verified
  **AP-side vendor dunning**, not customer dunning: `invoiceRef` is "FK to
  APTransaction UUID" and `acknowledgedAt` is "when the vendor acknowledged
  the notice". Listing it under `customer` would surface creditor/vendor
  data. AR-side dunning (`DunningRecord`, `DunningRun`) is also excluded:
  `DunningRecord.arInvoiceId` is "FK to ARInvoice.invoiceNumber" (a business
  number, not a UUID) and neither schema carries a customer scope property;
  `DunningRun` additionally embeds recipient PII and fully rendered letters
  (`renderedBody`, `ontvangerAdres`).
- **`GoodsReceipt`** (`inventory-mobile-scanner.json`) — no supplier
  reference at all: properties are `administrationId`, `sku`, `location`,
  `quantity`, `userId` (an NC uid), `occurredAt`, `transactionId`. An
  internal warehouse event; nothing to scope by.
- **`GoodsReceiptNote`** (3-way-match chain) — its only supplier linkage is
  `poIds`, an **array** of PurchaseOrder FKs (multi-PO receipts). The Wave-1
  contract's one-hop `via` join is scalar; an array-hop is unverified
  receiver behaviour. Deferred; suppliers still see receipt outcomes
  indirectly via `SupplierInvoice.statusCode` (match result).
- **Union-shape rows not surfaced (fail-closed, accepted):**
  bookings-deposit-flow `Invoice` rows carry `customerId` instead of
  `customerReference`; recurring-revenue `SalesOrder` rows
  (`shillinq_register.json` base shape) carry `klantId`; CLM-shape `Contract`
  rows (`contract-lifecycle-management.json`) carry `counterpartyReference`
  — including every `direction: inbound` (cost) contract, which is
  supplier-side data and *should* stay invisible to customers. Unsurfaced
  rows are invisible, never leaked. Harmonising the customer-reference
  property names across fragments is a Wave-2 schema change (out of scope
  here: this change edits no schemas).

## Deferred creates

None ship. The single clean candidate — quote acceptance — is modelled on the
existing `Quote` row as `acceptanceChannel` / `acceptedAt` /
`acceptanceEvidenceReference` (`bookkeeping-quote-order-invoice.json`): a
portal acceptance is an **update** to an existing row, not a create, and
update actions are not part of the Wave-1 contract. Inventing a parallel
"QuoteAcceptance" record purely for the portal would require a schema edit
(excluded from this change) and fork the acceptance write path
(single-write-path rule). Deferred to Wave 2 alongside endpoint actions.

## API Design

None. No routes, controllers, or endpoints. Reads go through OpenRegister's
existing object API, invoked by portaliq server-side with subject scoping
(ADR-022 — no app-local CRUD wrappers).

## Database Changes

None. Shillinq owns no tables (thin OR client) and this change edits no
register JSON. No `migration.md`: nothing to migrate, rollback is file
deletion.

## Nextcloud Integration

Controllers/Services/Mappers/Events: none. No `Application.php` registration
by design (see Architecture Overview).

## Security Considerations

- **Server-derived subject only** (ADR-005 / ADR-046): `$subject`
  (subjectRef, audience, organisation, trust) is built by portaliq's auth
  edge; the provider only reads `audience` to branch and never echoes subject
  data into the manifest.
- **Fail-closed audience filter**: unknown, absent, or empty audiences get
  `null`; the customer manifest never includes supplier collections and vice
  versa (a customer must not see PurchaseOrders; a supplier must not see
  customer invoices).
- **UUID domain scoping + per-app claims**: every scopeField is a verified
  UUID domain reference; matching runs against `claims.shillinq.*` values
  issued server-side. No NC uids anywhere (externals have no NC account by
  premise).
- **Read-only surface**: `actions: []` — an external can alter nothing in
  shillinq's books through this manifest. `notifications: []` — no inbox
  claims this wave.
- **Excluded-data review**: dunning letters (PII), payment links (signed
  tokens), AP/vendor records under the customer audience, and other parties'
  data are all excluded above with verified reasons.

## Seed Data

This change edits no schemas and seeds no objects — the register already
ships seed objects for the collections involved. For portal demos the
scoping properties must hold the UUID of the customer/vendor domain record;
existing seeds that predate portal use carry the **nil-UUID placeholder**
`00000000-0000-0000-0000-000000000000` in those fields (e.g. the shipped
`SalesOrder` seed's `klantId`). A demo environment replaces the placeholder
with the UUID of a real seeded contact/Payee at import time and issues the
matching `claims.shillinq.customerId` / `claims.shillinq.supplierId` to the
demo subject. Rows keeping the nil UUID match no real subject — fail-closed.

### Schema: `Quote` (illustrative portal-visible seed shape)

| Field | Object 1 | Object 2 |
|---|---|---|
| @self | register `shillinq`, schema `Quote` | register `shillinq`, schema `Quote` |
| quoteNumber | Q-2026-0101 | Q-2026-0102 |
| customerReference | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 |
| status | issued | accepted |

### Schema: `PurchaseOrder` (illustrative portal-visible seed shape)

| Field | Object 1 | Object 2 |
|---|---|---|
| @self | register `shillinq`, schema `PurchaseOrder` | register `shillinq`, schema `PurchaseOrder` |
| poNumber | PO-2026-0001 | PO-2026-0002 |
| supplierId | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 |
| statusCode | sent | acknowledged |

**Related items per object:** none — the portal only needs the scoped rows;
inbox/notification seeds are portaliq's own.

## File Structure

```
lib/
  Portal/
    PortalContributionProvider.php          (new — plain class, no deps)
tests/
  Unit/
    Portal/
      PortalContributionProviderTest.php    (new)
openspec/
  changes/portal-contribution/              (this change)
  specs/portal-contribution/spec.md         (capability stub, in-progress)
```

## Trade-offs

- **scopeClaim on every collection vs default subjectRef** — subjectRef
  matching would only work if portal subjects were literally shillinq
  customer records; they are not (people ≠ ledger parties, and one person
  can represent both a customer and a supplier). Bare-name claims cost one
  key per collection and keep the binding explicit.
- **Two audiences in one provider vs customer-only** — supplier data
  (PurchaseOrder/SupplierInvoice) is already cleanly UUID-scoped; shipping it
  now avoids a second Wave for two collections. The v1 fallback demotes to
  customer-only, which is the acceptable degraded mode.
- **Excluding ARInvoice vs bending the UUID rule** — ARInvoice is the richest
  AR surface, but scoping by an internal customer code would violate the
  contract and risk ambiguous/spoofable matches. The Q2C `Invoice` +
  `BillableInvoice` collections cover the customer-facing invoice need until
  AR gets a UUID reference.
- **`actions: []` vs one speculative create** — no clean create-shaped record
  exists (see Deferred creates); shipping none keeps Wave 1 provably
  read-only.
