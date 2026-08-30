# Design — Contract Lifecycle Management

## Context

Contract management is named in Shillinq's one-line summary and the README
advertises a contract repository with full-text search, a contract spend
dashboard, and obligation task management with automated deadline tracking —
but the only contract modelling in the app is lease-specific
(`bookkeeping-lease-contracts` and siblings, IFRS 16) plus IFRS-15 contract
*linking*. The 2026-06-11 feature re-evaluation rated this the app's single
High-severity gap.

The design question is not "how do we build a CLM engine" but "how little do
we build". Nextcloud already owns documents (NC Files, with versioning and
full-text search), tasks (NC Tasks / Deck), and contacts (addressbook);
OpenRegister already owns lifecycle, notifications, aggregations, audit, and
RBAC. The domain layer Shillinq actually has to add is small: the contract
record, its renewal terms, its obligations, and the spend rollup.

## Goals

- Express CLM as **declarative metadata** — two schemas + lifecycle +
  notifications + aggregations in an ADR-037 register fragment — per ADR-031.
- Make renewal/notice windows and obligation deadlines impossible to miss:
  scheduled notification rules on real date fields, owner-resolved
  recipients.
- Answer "committed vs invoiced vs contracted value" per contract by reusing
  the bookkeeping registers and the existing dimension model — no parallel
  spend bookkeeping.
- Keep every reusable Nextcloud surface in its leaf: documents in NC Files,
  tasks in NC Tasks/Deck, counterparties in the NC addressbook.
- Absorb lease contracts as a *specialization link* with zero regression to
  the lease suite.

## Non-Goals

- No PHP `ContractService` / `ObligationService` / `SpendReportService`
  (ADR-031 anti-pattern). The single allowed PHP unit is the thin
  NC Tasks/Deck bridge plus, if needed, a fail-closed lifecycle guard.
- No document storage, blob columns, upload endpoints, or attachment
  register in Shillinq (ADR-022; `bookkeeping-document-attachment-integration`
  anti-pattern list).
- No invented Customer/Party/Supplier schema — counterparty is an NC contact.
- No e-signature, clause templating, or AI clause extraction in v1.
- No accounting: IFRS 16 stays in the lease suite, IFRS 15 in
  `bookkeeping-ifrs15-revenue`, commitments in
  `bookkeeping-verplichtingenadministratie`.

## Reuse Analysis

| Need | Reused surface | What Shillinq adds |
|---|---|---|
| Contract document storage, versioning, content full-text search | NC Files (+ Nextcloud full-text search) | `documents` array of file references on `Contract` (link, don't store) |
| Obligation to-dos, assignment, due-date UX | NC Tasks (CalDAV VTODO) / Deck card | `ContractObligation` row (compliance metadata) + `taskUri` link via the bridge |
| Counterparty identity | NC addressbook contact (`OCP\Contacts\IManager`) | `counterpartyReference` field; never a Party schema |
| Lifecycle states + transitions | OR `x-openregister-lifecycle` | State graph + transition guards declared on `Contract` |
| Renewal / deadline notifications | OR notification engine, `x-openregister-notifications` (ADR-031) | Declarative rules on real fields; `nl`+`en` subjects |
| Committed spend | `PurchaseOrder` (bookkeeping-purchase-order-3way) | Optional `contractReference` FK consumed by an aggregation (CHAINED) |
| Invoiced spend | `APInvoice` / `ARInvoice` (AP/AR core changes) | Optional `contractReference` FK consumed by an aggregation (CHAINED) |
| Spend slicing | `bookkeeping-cost-centers-dimensions` | `costCenter` / dimension FKs on `Contract`; aggregation group-by |
| Audit trail | OR audit (`x-openregister-audit: true`) | Nothing — no app-local audit code |
| RBAC / repository access | OR RBAC + object ACLs | Nothing — no app-local permission code |
| Lease accounting | `bookkeeping-lease-contracts` register | `specializationReference` link only |

## Decisions

### D1 — Two schemas only: `Contract` and `ContractObligation`

The instruction set ("app-local OR schemas only for the domain layer") maps
to exactly two record types with independent identity and lifecycle. Renewal
terms are an embedded object on `Contract` (D3), the party link is a field
(D4), and the spend rollup is an aggregation (D5) — none of them justify a
schema. This mirrors how the lease suite scoped `lease-contract` and how
`bookkeeping-voorzieningen-claims` keeps satellites embedded.

### D2 — Lifecycle: draft → active → expiring → expired, with renewed/terminated exits

`draft` (being negotiated, incomplete fields allowed) → `active` (start date
reached, required fields complete) → `expiring` (inside the
notice/renewal-decision window, computed from `endDate − noticePeriodDays`)
→ `expired` (end date passed without renewal). `terminated` is reachable
from `active`/`expiring` (requires a termination reason — guard candidate).
`renewed` is reachable from `expiring`/`expired` and creates a successor
`draft` contract carrying the renewal terms, linked by
`predecessorContract`/`successorContract` self-FKs, so renewal chains are a
navigable history rather than mutated records. Time-based flips
(active→expiring, expiring→expired) are evaluated by OR's scheduled
machinery, not an app cron; the notification SLA never depends on the state
flip having happened (the scheduled rules filter on the date fields
directly).

### D3 — Renewal terms are an embedded object, not a schema

`renewalTerms { renewalType: none|manual|auto-renew, renewalTermMonths,
noticePeriodDays, priceIndexation }` lives on the contract. A renewal-terms
record has no identity, lifecycle, or ACL of its own; promoting it to a
schema would only add joins. `renewalDecisionDate` is a declared calculation
(`endDate − noticePeriodDays`), so the scheduled notification rule and the
repository's "decide by" column read one real field.

### D4 — Counterparty is an NC addressbook contact reference

`counterpartyReference` stores the contact link (addressbook URI / contacts
UID), same pattern as the bookings–pipelinq customer bridge and the
quote-order-invoice `customerReference`. The repository UI renders the
contact card via the contacts API. Where a counterparty is also an AP/AR
relation, that linkage already lives on the AP/AR side — CLM does not
duplicate it.

### D5 — Spend rollup is a chained declarative aggregation

`committedAmount` = sum of `PurchaseOrder` totals with
`contractReference = this`, `invoicedAmount` = sum of `APInvoice` (cost
contracts) / `ARInvoice` (revenue contracts) totals with
`contractReference = this`; both grouped by cost center / dimension for the
dashboard. Declared as `x-openregister-aggregations` — explicitly NOT a PHP
report service. Because the source schemas belong to unmerged changes
(`bookkeeping-purchase-order-3way`, AP/AR core), the aggregation rules and
the FK additions are CHAINED behind those changes, exactly like
`shillinq-notifications` chained its rules behind the schema owners. Until
then the dashboard renders an honest empty state.

### D6 — Repository search = OR `_search` on metadata + NC full-text on documents

The README's "contract repository with full-text search" is satisfied
compositionally in v1: the repository index searches contract metadata
(number, title, counterparty display name, tags) through OR's standard
`_search`, and document *content* search is Nextcloud full-text search over
the linked NC Files documents (which already indexes them). No app-local
indexer, no duplicated search stack. A combined single-box search view is a
possible v2 refinement (proposal Open Question 2).

### D7 — Obligations: register row is the source of truth, the NC task is a surface

`ContractObligation` holds what compliance needs (clause reference, type,
due date, recurrence, responsible, status, evidence file link). The bridge
creates one NC Tasks VTODO (or Deck card when Deck is enabled and chosen)
per obligation and stores `taskUri`; task completion is read back
opportunistically to suggest (not force) status updates. Deadline
notifications fire from the OR engine on the obligation row — never from
the task app — so deleting the task cannot silence a deadline. The bridge is
fail-closed glue: a bridge failure marks the obligation row
(`taskLinkStatus: failed`) and never blocks CRUD.

### D8 — Lease contracts become a specialization by link, not by inheritance

The `lease-contract` register (IFRS 16) remains canonical and untouched. A
generic `Contract` row of `contractType: lease` MAY carry
`specializationReference` → the `lease-contract` record; the contract detail
page renders a "Lease accounting" panel that deep-links to the lease detail.
The generic record MUST NOT duplicate lease payment terms, IBR,
classification, or any computed lease figure. Creating the generic wrapper
for existing leases is an optional, idempotent backfill action — the lease
suite keeps working if no wrapper exists (no regression).

### D9 — i18n with ENGLISH source keys

All new UI strings use English source keys —
`t('shillinq', 'Renewal decision due')` → nl `'Verlengingsbeslissing
vereist'` — with `nl` translations added in the same commit. Notification
subjects declare both `nl` and `en` per the `shillinq-notifications`
convention and stay metadata-only.
