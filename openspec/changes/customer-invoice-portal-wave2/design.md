# Design: customer-invoice-portal-wave2

## Architecture Overview

Shillinq contributes to **portaliq** (hydra ADR-046), the one shared external
portal for people without Nextcloud accounts. It does NOT build its own portal.
The whole contribution is the single plain class
`OCA\Shillinq\Portal\PortalContributionProvider`, duck-typed by portaliq via
FQCN; Wave 2 only appends two collections to the `customer` manifest:

```
portaliq (if installed)
  └─ resolves OCA\Shillinq\Portal\PortalContributionProvider (FQCN)
       └─ getContribution({audience: 'customer', ...}) → manifest (pure data)
            ├─ (Wave 1) Invoice / BillableInvoice / Quote / SalesOrder / Contract
            ├─ salesInvoices   → ARInvoice,       scopeField customerId,
            │                     scopeClaim customerMasterId  (direct UUID scope)
            └─ paymentRequests → PaymentRequest,  scopeField invoiceReference,
                                  scopeClaim customerMasterId,
                                  via { ARInvoice.customerId, targetField id,
                                        match scopeField }      (reverse one-hop)
```

Reads are executed by portaliq's `PortalObjectReader` straight against
OpenRegister (ADR-022 — no app-local CRUD wrapper), subject-scoped and per-row
re-verified. Shillinq declares; portaliq enforces.

## Declarative-vs-imperative decision (ADR-031)

The contribution stays **declarative** (ADR-031 declarative business logic /
ADR-024 manifest philosophy): `getContribution()` returns a pure-data manifest
(label, collections, actions, notifications) with no behaviour, no I/O, no
callbacks. The two new collections add only declarative keys the portaliq
contract already interprets: `scopeField`, `scopeClaim`, `via` (reverse join),
`fields` (projection whitelist), and the v3 presentation vocabulary (`columns`,
`detail`, `defaultSort`). No imperative surface is introduced; the pay-now link
is the schema's own `x-openregister-calculations` `paymentLink` field
(OpenConnector-resolved) — the app constructs no payment URL.

## Why the Wave-1 exclusion is lifted

Wave-1 `portal-contribution/design.md` excluded `ARInvoice` because
"`ARInvoice.customerId` is FK to `CustomerMaster.customerId`, an internal
customer code". That description comes from the additive fragment
`add-shillinq-bookkeeping-compliance.json`. The **canonical base** schema in
`lib/Settings/shillinq_register.json` declares:

```json
"customerId": { "type": "string", "format": "uuid", "$ref": "CustomerMaster",
                "inversedBy": "invoices",
                "description": "Reference to the CustomerMaster ... (holds its object UUID)." }
```

i.e. `customerId` holds the CustomerMaster **object UUID** (a relation, with the
`CustomerMaster.invoices` inverse). Scoping by that UUID is:

- **UUID-based** — satisfies the ADR-046 contract's UUID-scope requirement;
- **globally unique** — a CustomerMaster object UUID never collides across
  administrations, so (unlike the per-administration `customerNumber` *code*)
  there is no cross-administration leak vector;
- **server-issued** — the value comes from `claims.shillinq.customerMasterId`,
  resolved by portaliq from the subject's own portalAccount, never client input.

The distinct claim name `customerMasterId` (not the Wave-1 `customerId` claim,
which is the NC-contact/Q2C identity) keeps the two identity spaces explicit and
avoids widening the meaning of an existing claim.

## Scoping map (Wave 2 additions)

Audience `customer` — additions to the existing manifest.

| Collection id | Schema | scopeField | scopeClaim | Scope mechanism |
|---|---|---|---|---|
| `salesInvoices` | `ARInvoice` | `customerId` | `customerMasterId` | direct: `verifyScope(row.customerId === claim)` |
| `paymentRequests` | `PaymentRequest` | `invoiceReference` | `customerMasterId` | reverse `via` through `ARInvoice.customerId` |

Reverse `via` (contract v2.2, verified against portaliq
`Service/PortalObjectReader.php`): the join pre-pass reads `ARInvoice` where
`customerId == customerMasterId`, unions each matched invoice's `id`
(`targetField`) into a verified set; the outer `PaymentRequest` rows are then
kept when their own `scopeField` (`invoiceReference`, dot-path) is in that set
(`match: 'scopeField'`), per-row re-verified. An empty set yields zero rows — it
can never widen to "all payment requests".

## Claim-names contract

- `claims.shillinq.customerMasterId` MUST hold the **object UUID of the
  CustomerMaster** the portal subject is bound to — the record referenced by
  `ARInvoice.customerId`. Issuing it (binding an external person to a
  CustomerMaster) is portaliq-side onboarding work. A fully-onboarded debtor
  typically holds BOTH `customerId` (Wave-1 NC-contact/Q2C identity) and
  `customerMasterId` (AR sub-ledger identity); the AR surfaces match only the
  latter. A subject without `customerMasterId` sees no AR invoices or payment
  requests — fail-closed.

## Field projection (security)

`salesInvoices.fields` is a customer-safe whitelist — invoice header
(`invoiceNumber`, `invoiceType`, `invoiceDate`, `dueDate`, `currency`,
`totalAmount`, `taxAmount`), `lines`, `state`, the artefact URIs
(`sourceDocumentUri` PDF, `ublXml`), and the read-only `dunning` summary group.
It deliberately OMITS internal accounting fields (`glTransactionId`,
`matchedBankLineId`, `settlementReference`, `paymentEvidenceRef`, the
`writeOff` bad-debt/bankruptcy group, `administrationId`). Projection runs in
portaliq's reader AFTER per-row verification, so it shapes what a verified row
shows, never which rows return; identifiers are always preserved for detail
links. `paymentRequests.fields` exposes only the customer-relevant payment
fields incl. the computed `paymentLink`, and never `settlementReference` /
`gatewayFeeAmount` (operator/reconciliation data).

## Pay-now flow

1. An operator / recurring job creates a `PaymentRequest` (state `pending`)
   against an issued `ARInvoice` — unchanged existing flow.
2. The portal lists the debtor's `paymentRequests`; the computed `paymentLink`
   resolves (state=pending) to OpenConnector's hosted payment UI with a
   short-lived signed token.
3. The debtor pays (Mollie/iDEAL). OpenConnector's webhook drives
   `PaymentRequestWebhookController` → `PaymentReconciliationService`, which
   fires the `capture` transition; the linked `ARInvoice` settles through its
   existing `matchPaid` lifecycle (AR core owns the GL posting).
4. Status reflects back through `PaymentRequest.state` (pending → captured) and
   `ARInvoice.state` (issued/overdue → paid), both visible in the portal on the
   next read. No shillinq code runs in the portal request path.

## API Design

None. No routes, controllers, or endpoints are added. Reads go through
OpenRegister's object API invoked by portaliq server-side; the pay-now link is
the schema's computed field resolved by the OpenConnector adapter.

## Database Changes

None. No register JSON is edited; `ARInvoice.customerId` already is the
CustomerMaster UUID reference. No `migration.md` — nothing to migrate; rollback
is reverting the two appended collections.

## Nextcloud Integration

Controllers/Services/Mappers/Events/routes/`Application.php`: none. Discovery is
pull-based from portaliq (no DI registration, per ADR-046 A1).

## Security Considerations

- **Server-derived subject only (ADR-005/ADR-046):** the provider reads only
  `$subject['audience']` to branch; scope VALUES come from the server-resolved
  `customerMasterId` claim, never client input.
- **IDOR boundary (the headline):** no customer collection scopes by
  `administrationId` (which would surface every debtor in the administration)
  or by a raw/client-supplied id. AR invoices scope by the globally-unique
  CustomerMaster object UUID; PaymentRequest is reachable ONLY through the
  reverse `via` join on `ARInvoice.customerId`, so a payment request whose
  invoice belongs to a different CustomerMaster can never enter the result set.
  Enforcement is portaliq's per-row `verifyScope` + reverse-`via` membership
  (tested in portaliq); the mandatory shillinq-side test pins the declaration
  that feeds it.
- **Least exposure:** the `fields` whitelists hide internal accounting and
  credit-control fields from the debtor; the `writeOff` bad-debt group (which
  can carry bankruptcy/insolvency declarations) is never projected.
- **Read-only:** `actions: []`, `notifications: []` — an external can alter
  nothing in shillinq's books; pay-now is a click on a signed OpenConnector
  link, not a shillinq write.

## Seed Data

This change edits no schemas and seeds no new objects; the register already
ships `ARInvoice`, `CustomerMaster`, and `PaymentRequest` seed objects. Per the
Wave-1 pattern, portal scoping resolves against the CustomerMaster **object
UUID**: for a demo, an ARInvoice's `customerId` must hold the UUID of a seeded
CustomerMaster and the subject must be issued
`claims.shillinq.customerMasterId` with that same UUID (the demo import wires
the placeholder to the real UUID). A `PaymentRequest` is portal-visible when its
`invoiceReference` holds the linked ARInvoice's `id`. Rows whose `customerId`
carries a legacy code/slug (e.g. the shipped `DEB-0001` seeds) match no subject
— fail-closed, invisible, never leaked.

### Schema: `ARInvoice` (illustrative portal-visible seed shape)

| Field | Object 1 | Object 2 |
|---|---|---|
| @self | register `shillinq`, schema `ARInvoice` | register `shillinq`, schema `ARInvoice` |
| invoiceNumber | INV-C-2026-0042 | INV-C-2026-0039 |
| customerId | `<CustomerMaster object UUID>` | `<CustomerMaster object UUID>` |
| totalAmount | 1210.0 | 847.0 |
| state | issued | paid |

### Schema: `PaymentRequest` (illustrative portal-visible seed shape)

| Field | Object 1 | Object 2 |
|---|---|---|
| @self | register `shillinq`, schema `PaymentRequest` | register `shillinq`, schema `PaymentRequest` |
| invoiceReference | `<ARInvoice id of INV-C-2026-0042>` | `<ARInvoice id of INV-C-2026-0039>` |
| amount | 1210.0 | 847.0 |
| state | pending | captured |
| paymentLink | *(computed, pending → hosted UI)* | *(null, not pending)* |

**Related items per object:** none — the portal needs only the scoped rows;
inbox/notification seeds are portaliq's own.

## File Structure

```
lib/
  Portal/
    PortalContributionProvider.php          (edited — customer manifest +2 collections)
tests/
  Unit/
    Portal/
      PortalContributionProviderTest.php    (edited — exclusions lifted, IDOR + shape tests)
openspec/
  changes/customer-invoice-portal-wave2/    (this change)
  specs/portal-contribution/spec.md         (synced on archive)
```

## Trade-offs

- **Distinct `customerMasterId` claim vs reusing `customerId`** — the Wave-1
  `customerId` claim is the NC-contact/Q2C identity; ARInvoice keys on the
  CustomerMaster object UUID. A distinct claim keeps the two identity spaces
  explicit and avoids silently widening an existing claim's meaning. Cost: one
  extra claim to issue at onboarding.
- **Reverse `via` for PaymentRequest vs a denormalised customer field** —
  adding a `customerRef` UUID to PaymentRequest would simplify scoping but is a
  schema change + backfill; the reverse `via` is pure wiring the contract
  already supports, and fails closed.
- **Read-only vs portal-initiated link generation** — creating a PaymentRequest
  from the portal (a write action) is deferrable and higher-risk; Wave 2 keeps
  the surface provably read-only. Operators/recurring jobs create the links; the
  debtor clicks the already-resolved one.
- **`fields` projection vs full rows** — the whitelist costs one array per
  collection but guarantees internal accounting / bad-debt fields never reach a
  debtor, and fails closed narrow on a malformed declaration (portaliq reader).
- **`minTrust: low` vs `substantial`** — raising trust now empties the portal
  (no `substantial` subjects until eHerkenning lands); the UUID+claim scope is
  the real boundary. Documented upgrade path, not shipped now.
