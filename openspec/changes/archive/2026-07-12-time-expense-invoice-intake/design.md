# Design: time-expense-invoice-intake

## Architecture Overview

Shillinq already owns the full T&M invoice-generation stack:
`InvoiceGenerationService::draftInvoice()`, `BillingModelEngine`,
`InvoiceDeduplicationService`, `RateCardResolver`, and the `BillableInvoice`
/ `BillableInvoiceLine` / `RetainerSchedule` schemas, wired through
`/api/v1/invoices/generate`. That operator flow drafts an invoice from
`UrenRegistratie` (time-entry) rows that **already exist** in shillinq.

This change adds the missing ingress: a second app (pipelinq) needs to hand
shillinq a batch of time that does **not** yet exist as `UrenRegistratie`
rows, and needs the whole hand-off to be idempotent (safe to retry). The new
`BillingIntakeController` + `TimeIntakeService` is a thin adapter that:

1. Authenticates + resolves the administration scope exactly like
   `InvoiceApiController`.
2. Short-circuits on a known `batchId` (idempotency ledger =
   `TimeIntakeBatch`).
3. Materialises each inbound entry into a `UrenRegistratie` row stamped with
   `externalId` / `sourceApp` / `sourceBatchId`.
4. Delegates to the existing `InvoiceGenerationService::draftInvoice()` with
   `billingModel = t_and_m` and the freshly-created `timeEntryIds`.
5. Records the `TimeIntakeBatch → invoiceId` link and returns the frozen
   response contract.

```
pipelinq (time-billing-handoff-emit)
        │  POST /apps/shillinq/api/billing/time-intake
        ▼
BillingIntakeController::timeIntake()      (auth + scope, like InvoiceApiController)
        ▼
TimeIntakeService::ingest()
   ├─ TimeIntakeBatch lookup by (administrationId, batchId) ──► hit ► return {duplicated:true}
   ├─ validate (T&M only, entries present, rates resolvable, no cross-batch dup externalId)
   ├─ materialise UrenRegistratie rows (externalId, sourceApp, sourceBatchId)
   ├─ InvoiceGenerationService::draftInvoice(t_and_m, timeEntryIds)   ◄─ existing machinery
   └─ persist TimeIntakeBatch (batchId → invoiceId, status=invoiced)
        ▼
   {invoiceId, invoiceNumber, status:"draft", lines:n, duplicated:false}
```

## API Design

### `POST /apps/shillinq/api/billing/time-intake`

Full request/response schema is frozen in `contract.md`. Summary:

**Request:**
```json
{
  "batchId": "00000000-0000-0000-0000-000000000000",
  "organisationRef": "cust-5432",
  "currency": "EUR",
  "billingModel": "t_and_m",
  "period": { "start": "2026-06-01", "end": "2026-06-30" },
  "entries": [
    { "externalId": "pl-time-1001", "date": "2026-06-03", "minutes": 120,
      "description": "API design review", "hourlyRate": 150.0, "projectRef": "proj-widget-api" }
  ]
}
```
**Response:**
```json
{ "invoiceId": "bil-2026-0042", "invoiceNumber": "BIL-2026-0042",
  "status": "draft", "lines": 1, "duplicated": false }
```

## Database Changes

None in the SQL sense — shillinq owns no tables (OpenRegister-backed). Schema
changes land as a `register.d` fragment (see File Structure + Seed Data):

- **New schema `TimeIntakeBatch`** — the idempotency ledger.
- **Extend `UrenRegistratie`** with `externalId`, `sourceApp`,
  `sourceBatchId` (fragment-extends the canonical schema per ADR-037; never
  edits `shillinq_register.json`). The established pattern
  `add-shillinq-audit-trail.json` already targets `UrenRegistratie` via a
  fragment, so this is a supported extension shape.

## Nextcloud Integration

- Controllers: `OCA\Shillinq\Controller\BillingIntakeController` (new).
- Services: `OCA\Shillinq\Service\TimeIntakeService` (new); consumes existing
  `InvoiceGenerationService`, `AdministrationContextService`, and
  OpenRegister's `ObjectService` (via the DI container, as
  `InvoiceApiController` does).
- Mappers/Entities: none — OpenRegister `ObjectService` only (ADR-001).
- Events/Hooks: none new. Invoice creation flows through the existing
  service, so its existing audit-trail / lifecycle behaviour applies
  unchanged.

## Security Considerations

- Auth follows the shillinq API-controller pattern: `#[NoAdminRequired]` plus
  an explicit `$this->session->getUser() === null` guard returning `401`. No
  `#[PublicPage]`.
- `administrationId` is resolved server-side via
  `AdministrationContextService`; a client-supplied value is never trusted
  (prevents cross-tenant writes — ADR-005).
- Per-object authorization: `organisationRef` / `projectRef` / `rateCardId`
  are validated to belong to the resolved administration before use; a ref
  outside the tenant is rejected, not silently coerced.
- The route registers its auth attribute so it is reachable (gate
  route-auth / route-reachability).
- Idempotency + cross-batch `externalId` dedup prevent a replay or a
  duplicate-emit from producing double invoices.

## File Structure

```
lib/
  Controller/
    BillingIntakeController.php        # new — timeIntake()
  Service/
    TimeIntakeService.php              # new — ingest(): validate, materialise, delegate, ledger
  Settings/
    register.d/
      time-expense-invoice-intake.json # new — TimeIntakeBatch + UrenRegistratie provenance fields
appinfo/
  routes.php                           # +1 route: billingIntake#timeIntake
```

## Seed Data

Two schemas are touched. Seed objects go in the change's `_registers.json`
(generated by the apply agent from this section), scoped to the `shillinq`
register. Placeholders use the nil-UUID form where an id is illustrative.

### Schema: `TimeIntakeBatch`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | intake-batch-june-widgetapi | intake-batch-may-support | intake-batch-replay-demo |
| administrationId | adm-1 | adm-1 | adm-1 |
| batchId | 11111111-1111-1111-1111-111111111111 | 22222222-2222-2222-2222-222222222222 | 33333333-3333-3333-3333-333333333333 |
| sourceApp | pipelinq | pipelinq | pipelinq |
| organisationRef | cust-5432 | cust-7788 | cust-5432 |
| projectId | proj-widget-api | proj-support | proj-widget-api |
| currency | EUR | EUR | EUR |
| periodStart | 2026-06-01 | 2026-05-01 | 2026-06-01 |
| periodEnd | 2026-06-30 | 2026-05-31 | 2026-06-30 |
| entryCount | 2 | 3 | 2 |
| status | invoiced | invoiced | invoiced |
| invoiceId | bil-2026-0042 | bil-2026-0031 | bil-2026-0042 |
| receivedAt | 2026-07-01 | 2026-06-01 | 2026-07-02 |

**Related items per object:**
- Files: none.
- Notes: Object 1 — "June T&M overflow approved in pipelinq"; Object 3
  demonstrates the idempotent replay pointing at the same invoice as Object 1.
- Tasks: none.
- Contacts: `organisationRef` links to the seed Nextcloud contact / customer
  used by the existing `BillableInvoice` seeds (`cust-5432`, `cust-7788`).

### Schema: `UrenRegistratie` (provenance fields on existing seed rows)
The three new fields seed onto the existing `UrenRegistratie` seed rows that
back the intake demo, showing the pipelinq provenance chain.
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| externalId | pl-time-1001 | pl-time-1002 | pl-time-2001 |
| sourceApp | pipelinq | pipelinq | pipelinq |
| sourceBatchId | 11111111-1111-1111-1111-111111111111 | 11111111-1111-1111-1111-111111111111 | 22222222-2222-2222-2222-222222222222 |

**Related items per object:**
- Files: none.
- Notes: none.
- Tasks: none.
- Contacts: none (provenance-only fields; the parent `UrenRegistratie` seed
  already carries `personId` / `projectId`).

## Declarative-vs-imperative decision

Per ADR-031, schema-declarative behaviour is the default and a custom service
is acceptable only as a documented exception. This change is deliberately
split:

- **Imperative (legitimate exception, ADR-031 §"What apps SHOULD still write
  in PHP" → external API integrations):** `BillingIntakeController` +
  `TimeIntakeService` are an **external-integration ingress adapter** — they
  receive a POST from a *different app* (pipelinq), translate its wire shape
  into shillinq `UrenRegistratie` rows, and enforce idempotency. The OR schema
  engine cannot receive an inbound HTTP request from another app or run the
  translation/idempotency logic, so this glue is code. This is the same class
  as the existing `SupplierInvoiceImportController` and `PayrollWebhookController`
  ingress adapters in shillinq.
- **Declarative / reused (NOT re-implemented):** the actual invoice
  construction — line aggregation, `lineItemsByModel`, `summary` totals,
  rate snapshotting, and the draft→posted **lifecycle** — stays in the
  existing `InvoiceGenerationService` / `BillingModelEngine` and the
  `BillableInvoice` schema's status field. The intake does **not** compute
  totals, does not re-derive line items, and does not manage the invoice
  lifecycle. The `TimeIntakeBatch.status` (received → invoiced/failed) is a
  thin ingress ledger, not a business-object lifecycle; it records
  what-happened-to-this-batch, not a stateful domain workflow.

No new aggregation or notification logic is introduced imperatively. If a
future slice wants "notify the operator when a batch drafts an invoice", that
is added declaratively via `x-openregister-notifications` on `TimeIntakeBatch`,
not a hand-rolled notifier (ADR-031 dialect).

## Goals / Non-Goals

**Goals:** one idempotent ingress route; one draft `BillableInvoice` per
batch (T&M); frozen contract for the pipelinq consumer; zero new UI.

**Non-Goals:** non-T&M models via intake; AR/GL posting; multi-currency; any
change to the existing operator invoice API or UI; the pipelinq emit side.

## Decisions

### D1: Materialise `UrenRegistratie` rows vs. build `BillableInvoiceLine`s directly
**Chosen:** materialise `UrenRegistratie` rows, then call the existing
`draftInvoice()`. **Why:** reuses `InvoiceDeduplicationService`,
`RateCardResolver`, totals, and audit trail unchanged — the intake stays
thin and the invoice is indistinguishable from an operator-drafted one.
**Alternative rejected:** constructing `BillableInvoiceLine` rows directly in
the intake would duplicate the T&M math and bypass dedup — more code, drift
risk, ADR-031 anti-pattern (re-implementing aggregation).

### D2: Idempotency key = `batchId` + cross-batch `externalId` guard
**Chosen:** two layers. `batchId` gives exact-replay idempotency (same batch
→ same invoice). A per-entry `externalId` uniqueness check (per
administration) stops the *same time* being re-sent under a *new* batchId
from double-billing. **Alternative rejected:** `batchId` alone — a client
retry that regenerates the batch id would double-bill.

### D3: Expenses accept-and-defer for this slice
**Chosen:** the request accepts an optional `expenses[]`, but if
`ExpenseClaimEntry` creation is not wired in this slice, the intake
accepts-and-ignores expenses and drafts a time-only invoice, recording the
count in the batch. **Why:** keeps the slice minimal and buildable; expenses
have a separate capability. **Deferred question** — see below.

## Risks / Trade-offs

- [Double-billing across batches] → cross-batch `externalId` dedup +
  `InvoiceDeduplicationService` on generation.
- [Fragment-extending a canonical schema might not merge new props] → the
  migration/seed task verifies the merged `UrenRegistratie` materialises the
  three fields on import before any intake runs; pattern already proven by
  `add-shillinq-audit-trail.json`.
- [Partial failure mid-materialisation leaves orphan `UrenRegistratie` rows]
  → the batch is only recorded as `invoiced` after `draftInvoice()` succeeds;
  a failure marks the batch `failed` and the orphan rows carry the
  `sourceBatchId`, so a retry of the same `batchId` is detected and cleaned/
  reused rather than duplicated.

## Migration Plan

1. Ship the `register.d` fragment; OpenRegister imports it via the existing
   repair/register-import step. Verify `TimeIntakeBatch` and the three
   `UrenRegistratie` fields materialise.
2. Register the route; deploy the controller + service.
3. Rollback: revert the single commit. Dropping the fragment restores the
   canonical `UrenRegistratie`; already-created batches/invoices remain
   readable but are no longer produced. No data migration needed.

## Open Questions

- **Expenses (D3):** does this slice wire `ExpenseClaimEntry` creation, or
  accept-and-defer? Provisional: accept-and-defer (time-only). **DEFERRED —
  needs product confirmation.**
- **`rateRef` failure mode:** hard-fail the batch (422) on an unresolvable
  `rateRef` when no inline `hourlyRate` is present, vs. skip the entry.
  Provisional: hard-fail the whole batch for auditability. **DEFERRED.**
- **`organisationRef` identity:** confirm pipelinq sends a shillinq
  customer/contact id resolvable in the target administration, not a pipelinq
  internal id. Provisional: shillinq customer id; contract says
  `organisationRef`. **DEFERRED — cross-repo confirmation with
  `time-billing-handoff-emit`.**
