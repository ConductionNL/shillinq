# Design: reporting-relation-both-sides

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**Two records for one organisation.** The customer side is `CustomerMaster`
(`lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json:215`) with
`kvkNumber`, `vatID`, `iban` and `administrationId`, typed
`schema:Organization`. The supplier side is `Payee`
(`register.d/bookkeeping-accounts-payable-core.json:11`) with `kvkNumber`,
`vatNumber`, `bankAccount`, `contactRef` and `configuration.implements`
`https://openregister.app/ns#Vendor` and `https://schema.org/Organization`.
Neither refers to the other. The matrix note holds.

**Invoices on each side.**

| Side | Schema | Key to the record | Pages |
|---|---|---|---|
| Sent | `ARInvoice` (`add-shillinq-bookkeeping-compliance.json:395`) | `customerId` | `AccountsReceivable`, `ARInvoiceDetail` |
| Received | `APTransaction` (`bookkeeping-accounts-payable-core.json:298`) | `vendorId` (the `Payee`) | `PayeeDetail` related list (`src/manifest.d/bookkeeping-accounts-payable-core.json:104-118`) |
| Received | `SupplierInvoice` (`bookkeeping-purchase-order-3way-01-schemas-and-registers.json:753`) | `supplierId` (`$ref Payee`) | `SupplierInvoices` (`src/manifest.json:13697`) |

A third received schema, `APInvoice` (`lib/Settings/shillinq_register.json:20925`),
keys on a `vendorId` described as "FK to VendorMaster UUID", a schema that does
not exist; it is left out, and the design names it so the purchasing changes
of this OpenSpec pass can decide its fate.

**Access.** `ARInvoice` RBAC grants read to `bookkeeper`, `ar-controller` and
`auditor`; `APTransaction` to `bookkeeper`, `controller` and `auditor`. An
`ar-controller` may therefore read the sent side and not the received side.

**Reports.** The Reports page (`src/manifest.json:2536`, ADR-112) lists cards
by category; read-only report endpoints bound to declarative `endpointSource`
widgets already exist (the provincies BBV dashboards, `appinfo/routes.php:428-440`).

## Goals / Non-Goals

**Goals**

- A bookkeeper opening a customer that is also a supplier sees both sides and the net position in one place.
- A controller lists every such relation with its totals for a period.

**Non-Goals**

- Booking an offset, merging records.

## Decisions

### D1. A one-to-one link on the customer

`CustomerMaster.payeeId` references the `Payee`; the reverse is read by
filtering customers on `payeeId`. One customer links to at most one payee per
administration, enforced by a guard on save.

Alternative considered: a `RelationLink` schema. Rejected: one field answers
the question, and a separate object adds a join to every read.

### D2. Suggest by identifier, apply by a person

`RelationLinkService::suggestions(administrationId)` lists customer and payee
pairs with equal normalised KvK numbers or equal normalised VAT numbers (upper
case, spaces removed), not yet linked and not dismissed. A user confirms (the
link is written with who and which number matched) or dismisses (the pair is
not suggested again). Linking by hand picks a payee on `CustomerDetail`.

### D3. One read service for both sides

`RelationBothSidesService::forCustomer(customerId, from, to)` reads, within the
caller's administration, the customer's `ARInvoice` rows and the linked payee's
`APTransaction` and `SupplierInvoice` rows through OpenRegister with RBAC on,
and returns the rows and five totals: invoiced sales, invoiced purchases, open
receivable, open payable, and net (open receivable minus open payable). A side
the caller may not read comes back as `restricted` instead of empty.
`forAdministration(from, to)` returns the same totals per linked relation.
Credit notes count negative on their side.

Alternative considered: declared `x-openregister-aggregations`. Rejected: an
aggregation runs over one schema, and this view joins three through a link.

### D4. Where it shows

A Both sides section on `CustomerDetail` and on `PayeeDetail`, reading
`GET /api/relations/{customerId}/both-sides` (the payee page resolves its
customer first), with the rows in two lists and the totals as KPI chips. A card
"Relations both ways" on the Reports page opens a report page bound to
`GET /api/relations/both-sides` with a period filter and CSV export. The
suggestions list sits on the report page.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The link | Declarative: `CustomerMaster.payeeId` with a one-to-one guard | Data. |
| Suggesting links | Imperative, `RelationLinkService` | Normalised identifier comparison across two schemas. |
| Joining and totalling both sides | Imperative, `RelationBothSidesService` | A cross-schema read under per-schema RBAC. |
| Showing it | Declarative manifest widgets bound to the endpoint | The page is data-driven. |

## Seed Data

`CustomerMaster` gains `payeeId`, `payeeLink` (`matchedOn`, `confirmedBy`,
`confirmedAt`) and `dismissedPayeeSuggestions`; no schema is added.

Seed objects for the administration "Drukkerij Van Wijk B.V.":

- `CustomerMaster` and `Payee` "Reclamebureau Zuid B.V." with KvK number 90000001, linked on KvK by user petra.
- Sent: invoices 2026-0310 (EUR 4,235.00, paid) and 2026-0355 (EUR 1,210.00, open).
- Received: `APTransaction` INK-2026-118 (EUR 2,662.00, open) for design work.
- Expected both-sides totals for 2026: sales EUR 5,445.00, purchases EUR 2,662.00, open receivable EUR 1,210.00, open payable EUR 2,662.00, net minus EUR 1,452.00.
- A suggested pair "Transport Noord" matched on VAT number, not yet confirmed.

## Risks / Trade-offs

- [Identifiers typed differently (NL0012 vs nl 0012)] → normalisation in D2; a pair still missed can be linked by hand.
- [Large relations make the section slow] → the endpoint pages rows (50 per side) and computes totals with aggregate queries per schema.

## Migration Plan

No data migration. Suggestions are computed on demand. Rollback is reverting
the PR.

## Open Questions

- Whether `APInvoice` should be read at all depends on the purchasing changes of this OpenSpec pass; it is left out here.

## Built (2026-09-30): where the code differs from the decisions above

- **Access (D3, REQ-RRBS-004).** `x-openregister-rbac` on `ARInvoice` and `APTransaction` is documentation: OpenRegister reads `Schema::getAuthorization()`, and no shillinq schema declares one (see `lib/Service/SpendAnalyticsService.php`). Reading "with the caller's RBAC" would therefore show every side to everyone. The sides follow the caller's role in the administration instead (`RelationController::SENT_ROLES` and `RECEIVED_ROLES`): `debiteurenadmin` sees the sales side only, `crediteurenadmin` the purchase side only, `salarisadministrateur` neither, every other role both. Linking and dismissing need a role that keeps customer or supplier records (`LINK_ROLES`).
- **Received invoices.** A `SupplierInvoice` with an `apTransactionId` is already counted as its `APTransaction`, so only supplier invoices not yet handed to payables count, and only while not paid or rejected.
- **One to one.** `RelationLinkService::link()` refuses a supplier another customer is linked to, and a customer already linked elsewhere; a write straight through the OpenRegister API is not guarded.
- **Pages.** `CustomerDetail` carries the Link to a supplier action and five widgets bound to `GET /api/relations/{customerId}/both-sides` (three amounts, invoices sent, invoices received). `PayeeDetail` renders fields, not a widget grid, so it gets a **Both sides** action that opens the linked customer. Relations both ways is a dashboard page with a date range, the linked relations and the suggested links (Link and Not the same row actions), and an Export CSV action that asks for the period.
- **Endpoints.** Also `GET /api/relations/payee/{payeeId}/both-sides`, `GET /api/relations/suggestions`, `POST /api/relations/links`, `POST /api/relations/suggestions/dismiss` and `DELETE /api/relations/links/{customerId}`.
