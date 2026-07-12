---
kind: code
depends_on: []
chain:
  - time-expense-invoice-intake
---

# Proposal: time-expense-invoice-intake

## Summary

Add an authenticated, idempotent billing-intake endpoint to shillinq —
`POST /apps/shillinq/api/billing/time-intake` — that accepts a batch of
**approved** time entries (and optionally expenses) from pipelinq and
materialises them into **one draft** `BillableInvoice` (Time & Materials
model only for this slice) with its `BillableInvoiceLine` rows. This is
the shillinq half of the pipelinq time-billing handoff: pipelinq owns the
project/CRM record and the approval, shillinq owns invoicing. The endpoint
unblocks pipelinq's `time-billing-handoff-emit` change, which today sits
`blocked-on-prereq` because shillinq has no ingress route to hand approved
time to.

## Motivation

Ecosystem leaf-ownership research (2026-07-12, Specter DB) fixes invoicing
as **owned by shillinq**, with pipelinq reintegrating as a leaf that emits
approved time rather than growing its own invoicing surface. Pipelinq's
`manifest.yaml` currently marks its invoicing capability
`blocked-on-prereq`, delegated to shillinq, because shillinq has not shipped
an approval/invoice ingress route — so the handoff cannot be built on the
pipelinq side yet.

The business case is strong and independent of the internal wiring: user
research lists "all-in-one CRM + quotes/invoicing" as a **top-5 user wish**
(6 independent sources), and the **#1 churn driver is incumbent price
escalation** — integrated invoicing that does not cost an extra per-seat
module is a direct wedge against that churn. Shipping the minimal ingress
route now is the smallest change that turns "delegated, blocked" into
"delegated, live" for the whole time-billing loop.

Why now: shillinq already has the full T&M invoice-generation machinery
built (`InvoiceGenerationService`, `BillingModelEngine`,
`InvoiceDeduplicationService`, the `BillableInvoice` / `BillableInvoiceLine`
/ `RetainerSchedule` schemas, and the `/api/v1/invoices/*` routes). What is
missing is a single ingress endpoint that a **different app** can POST a
batch of externally-approved time to, idempotently. This change is a thin
adapter over machinery that already exists and is live-verified.

## Affected Projects

- [x] Project: `shillinq` — new billing-intake controller + thin intake
  service + route; a register fragment adding a `TimeIntakeBatch` schema
  and three source-provenance fields on `UrenRegistratie`.
- [ ] Project: `pipelinq` — **not touched by this change.** Pipelinq consumes
  this endpoint in its own separate change `time-billing-handoff-emit`
  (cross-repo consumer; see Cross-Project Dependencies).

## Scope

### In Scope

- New authenticated endpoint `POST /apps/shillinq/api/billing/time-intake`.
- Request payload: batch id (idempotency key), client/organisation ref,
  `entries[]` of `{externalId, date, minutes, description, hourlyRate or
  rateRef, projectRef?}`, `currency`, `period {start, end}`, optional
  `expenses[]`.
- Validation + persistence of the batch (`TimeIntakeBatch`), materialisation
  of each entry into a `UrenRegistratie` row stamped with its `externalId`,
  `sourceApp`, and `sourceBatchId`, and creation of **one** draft
  `BillableInvoice` (`billingModel = t_and_m`) with `BillableInvoiceLine`
  rows via the existing `InvoiceGenerationService`.
- Idempotency: re-POSTing the same batch id returns `200` with the existing
  invoice reference and `duplicated: true`; no duplicate invoice, no
  duplicate `UrenRegistratie` rows.
- Documented response contract for the pipelinq consumer:
  `{invoiceId, invoiceNumber?, status: "draft", lines: n, duplicated: bool}`.
- Auth: follows the existing shillinq API-controller pattern
  (`#[NoAdminRequired]` + session check + server-resolved administration
  scope, never a client-supplied `administrationId`); ADR-005 per-object
  authorization. No `#[PublicPage]`.

### Out of Scope

- **Any new admin/UI surface.** Draft invoices created by the intake appear
  in the existing invoice list UI — no new screen is built.
- **Billing models other than T&M.** `fixed_fee`, `milestone`, `retainer`,
  `mixed` remain reachable only via the existing `/api/v1/invoices/generate`
  operator flow; the intake rejects any non-T&M batch.
- **Posting to AR / GL.** The intake stops at `status: draft`; posting stays
  the operator's existing `POST /api/v1/invoices/{id}/post` action.
- **Multi-currency.** EUR only, matching the existing invoice slice.
- **The pipelinq emit side** (`time-billing-handoff-emit`) — a separate
  change in the pipelinq repo.

## Approach

A thin imperative adapter (external-integration ingress — a legitimate
ADR-031 exception) that reuses existing declarative + service machinery:

1. New `BillingIntakeController::timeIntake()` authenticates like every
   other shillinq API controller and resolves the administration scope
   server-side.
2. A new `TimeIntakeService` looks up `TimeIntakeBatch` by `(administrationId,
   batchId)`. If found → return its stored invoice reference with
   `duplicated: true` (idempotent short-circuit).
3. Otherwise it validates the batch (T&M-only, entries present, rates
   resolvable), materialises each entry into a `UrenRegistratie` row stamped
   with `externalId` / `sourceApp: "pipelinq"` / `sourceBatchId`, then calls
   the existing `InvoiceGenerationService::draftInvoice()` with
   `billingModel = t_and_m` and the new `timeEntryIds`.
4. It records a `TimeIntakeBatch` row linking `batchId → invoiceId`, then
   returns the response contract.

Aggregation (line totals, `lineItemsByModel`, `summary`) and the
draft→posted lifecycle stay in the existing service / schema layer — the new
code is only the ingress adapter and its idempotency ledger.

## New Dependencies

None. Reuses existing shillinq services, schemas, and OpenRegister's
`ObjectService`.

## Impact

- `appinfo/routes.php` — one new route.
- `lib/Controller/BillingIntakeController.php` — new.
- `lib/Service/TimeIntakeService.php` — new.
- `lib/Settings/register.d/time-expense-invoice-intake.json` — new fragment:
  `TimeIntakeBatch` schema + `externalId` / `sourceApp` / `sourceBatchId`
  provenance fields on `UrenRegistratie` (fragment-extends the canonical
  schema per ADR-037 — never edits `shillinq_register.json`).
- Existing `InvoiceGenerationService`, `BillingModelEngine`,
  `BillableInvoice` / `BillableInvoiceLine` schemas — consumed, not modified.

## Cross-Project Dependencies

- **pipelinq → shillinq (consumer):** pipelinq change `time-billing-handoff-emit`
  will POST to this endpoint. This change defines and freezes the request +
  response contract that `time-billing-handoff-emit` codes against. This
  change ships first and independently; pipelinq's manifest capability moves
  from `blocked-on-prereq` to buildable once this route is live.
- No dependency in the other direction — shillinq does not call pipelinq.

## Risks

### Risk 1: Double-billing across batches

**Severity:** High — **Mitigation:** Idempotency is keyed on `batchId`, but a
retry could re-send the *same time* under a *new* batch id. Every
materialised `UrenRegistratie` row carries `externalId` + `sourceApp`;
generation reuses `InvoiceDeduplicationService`, which already blocks a
time-entry id appearing in more than one draft/posted invoice. The intake
additionally skips materialising an `externalId` that already exists for the
administration, so a re-sent entry lands on its original invoice, not a new
line.

### Risk 2: Fragment-extending a canonical schema

**Severity:** Medium — **Mitigation:** `UrenRegistratie` is defined in
`shillinq_register.json`, which ADR-037 forbids editing. Adding provenance
fields via a `register.d` fragment follows the established pattern
(`add-shillinq-audit-trail.json` already targets `UrenRegistratie`). The
migration/seed step verifies the merged schema materialises the new fields
on import before any intake runs.

### Risk 3: Contract drift with the pipelinq consumer

**Severity:** Low — **Mitigation:** The request/response contract is written
in `contract.md` and mirrored in the spec's scenarios; the pipelinq change
references this change by name. Any change to the shape is a coordinated
edit across both changes.

## Rollback Strategy

Revert the single commit: remove the route, controller, service, and the
`register.d` fragment. Because shillinq owns no tables (OpenRegister-backed),
dropping the fragment leaves the canonical `UrenRegistratie` schema
unchanged; any `TimeIntakeBatch` / draft `BillableInvoice` rows already
created remain readable but are simply no longer produced. No data migration
is required to roll back.

## Open Questions

- **Expense passthrough shape:** the slice accepts an optional `expenses[]`,
  but expense materialisation reuses `ExpenseClaimEntry` from a separate
  capability. For this slice the intake MAY accept-and-ignore expenses
  (time-only invoice) if `ExpenseClaimEntry` creation is not wired — decided
  in design.md; flagged as a deferred question for the reviewer.
- **`rateRef` resolution:** entries may carry an inline `hourlyRate` or a
  `rateRef` into shillinq's `RateCard`. Whether an unresolvable `rateRef`
  hard-fails the whole batch or falls back to a per-entry `hourlyRate` is
  settled in design.md.
