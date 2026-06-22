# Order/Contract consolidation — schema analytics & plan

## 1. What "order-like" schemas actually exist

shillinq already has **seven** order-like schemas, all sharing a core (a numbered
agreement with a counterparty, currency, dates, status, lines, payment terms) but
each carrying a domain tail:

| Schema | File | Domain tail (beyond the common core) |
|---|---|---|
| `Quote` | bookkeeping-quote-order-invoice | validUntil, version, supersedesQuote, acceptance* |
| `SalesOrder` | bookkeeping-quote-order-invoice | sourceQuoteReference, delivery/shipping, invoicingMode, creditCheckResult |
| `BlanketOrder` | bookkeeping-quote-order-invoice | committedQty, calledOffQty, call-off refs, validFrom/Until |
| `Order` (booking) | bookings-deposit-to-invoice | bookingTypeId, basePrice, taxRate, **depositAmount/depositPaymentId** |
| `PurchaseOrder` | purchase-order-3way | supplierId, 3-way match, peppol*, approvalChain |
| `Subsidie` (×3 dup) | shillinq_register + 2 | regeling/beschikking/vaststelling, 5 state-amounts, prestatieverantwoording |
| DBA engagement | dba-* | modelovereenkomst, risicoklasse |

**OpenRegister supports JSON-Schema composition** (`allOf` + `$ref`, the standards
replacement of the old `extend` column — `lib/Service/.../CompositionHandler.php`).
So the right model is a **base `Order` schema** that each of these **extends via
`allOf: [{$ref: Order}]`**, instead of a fat schema or seven siblings.

## 2. The model: base `Order` + extensions + related objects

### Base `Order` (the abstract core every order-like object IS)
`orderNumber, orderType (sales|purchase|grant|booking|engagement|quote|blanket),
direction (incoming|outgoing), counterpartyId, counterpartyName, currency, orderDate,
endDate, status, totalAmount, description, lines[], paymentTerms, projectReference,
costCenter, administrationId`.

### Extensions (each `allOf` the base + its own tail)
- `SalesOrder` → delivery/shipping/invoicingMode/creditCheck/sourceQuote
- `PurchaseOrder` → supplier/3-way/peppol/approvalChain
- `Booking` → bookingTypeId/basePrice/taxRate/completedAt
- `Grant` → regeling{Naam,Artikel}/beschikkingDate/vaststellingDate
- `Engagement` → modelovereenkomst/risicoklasse/dbaBeoordeling
- `Quote`, `BlanketOrder` → pre-order states (or keep as their own extensions)

### Related objects (attached to the Order, NOT fields on it — render as widgets on the order detail)
Answering *"what could be abstract objects attached to an order instead of fields?"*:

- **`Payment`** (the big one): `orderId, type (deposit|installment|disbursement|reclaim|final), amount, date, method, status, linkedInvoiceId`.
  - Booking's `depositAmount`/`depositPaymentId` → a `Payment(type=deposit)`. ✅ (your point 1 — a **Payments widget** on the order detail.)
  - Grant's `uitbetaaldBedrag` → `Payment(type=disbursement)`, `teruggevorderdBedrag` → `Payment(type=reclaim)`. So **3 of the Grant's 5 state-amounts are just Payments.**
- **`SubsidieVerantwoording`** (performance accountability) + **`AuditorStatement`** — already separate; stay as related objects on a Grant order.
- **Documents** (`beschikkingUri`, `vaststellingUri`, attachments) → docudesk file relations, not Order fields.
- `paymentTerms` is universal enough to **stay a base field** (not worth an object).
- `OrderLine` — unify the existing `QuoteLine`/`SalesOrderLine` into one related `OrderLine`.

**Net for Grant:** its genuinely-Grant-only fields shrink to `regeling{Naam,Artikel}`,
`beschikkingDate`, `vaststellingDate`, `awardAmount`(→`Order.totalAmount`) + the
lifecycle status. Everything else is a Payment, a Verantwoording, or a document.

## 3. Consolidating the duplicates
- **`Order` (booking)**: rename → `Booking` extension; pull `deposit*` out to `Payment`.
- **`Subsidie` ×3 → one `Grant`** (English, rich): union the Dutch (`shillinq_register`,
  the richer set) + English (`add-shillinq-bookkeeping-operations`) fields, English names,
  drop the empty audit-trail stub. Map: subsidieNumber→orderNumber, granteeOrganization/
  counterparty→counterpartyName, awardAmount/verleendBedrag→totalAmount, regelingNaam/
  grantProgram→`Grant.scheme`, state→status; the 5 amount-stages → 3 Payments + 2 fields.

## 4. Implementation phases (revised, safe)
1. **Base `Order` schema** + the `Payment` related object + unified `OrderLine`.
2. **Extensions** (`SalesOrder`/`PurchaseOrder`/`Booking`/`Grant`/`Engagement`) via `allOf:$ref`.
3. **Seed test data** for each type (so migrations are verifiable) — `occ shillinq:orders:seed`.
4. **Migrations** (hand-written, verified vs the seed): bookings-Order→Booking + extract deposits→Payment; Subsidie→Grant + amounts→Payments; PurchaseOrder→PurchaseOrder(ext); idempotent, IUser-typed, `_rbac:false` on reads+writes, **count-equality + no-field-loss audit**.
5. **Bug fixes** from the rejected build (currentUser:IUser, RBAC reads, register repair steps in info.xml, complete field maps).
6. **UI**: one Order workspace (filter by orderType) + a **Payments widget** + Verantwoording widget on the order detail; retire the bespoke pages; collapse nav.
7. **Compliance re-point + retire** the 3 Subsidie + redundant Order schemas.

## 5. Open design questions for sign-off
- **Q-A**: Keep `Quote`/`SalesOrder`/`BlanketOrder`/`Booking` as **separate extensions**, or only fold Subsidie/PO/DBA now and leave the sales-side as-is? (They're already a coherent quote→order→invoice flow.)
- **Q-B**: `Grant` 5 state-amounts — confirm the split: `aangevraagd`/`verleend`/`vastgesteld` as Grant fields (award stages) vs `uitbetaald`/`teruggevorderd` as `Payment`s. ✅ recommended.
- **Q-C**: One `Payment` object across all order types (recommended) vs type-specific.
