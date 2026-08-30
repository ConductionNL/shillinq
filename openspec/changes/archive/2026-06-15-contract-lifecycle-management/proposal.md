# Proposal: contract-lifecycle-management

`kind: config` per ADR-032/ADR-037 — a declarative-first capability: two new
register schemas, lifecycle states, x-openregister-notifications rules,
spend-rollup aggregations, manifest pages, and one thin NC Tasks/Deck
integration bridge. No app-local contract "engine".

## Summary

Introduce **generic contract lifecycle management (CLM)** for Shillinq: a
contract repository covering every contract type (purchase, sales, service,
subscription, lease, employment, other) with lifecycle states
(draft / active / expiring / expired / renewed / terminated), renewal-date and
notice-period tracking, obligation deadline management, a per-contract spend
dashboard (committed vs invoiced), and document attachment.

This closes the single biggest gap found in the 2026-06-11 feature
re-evaluation: contract management is named in the app's **one-line summary**
("…and contract management into one self-hosted solution") and the README
advertises five contract features ("Contract repository: centralized document
management with full-text search", "View contract spend dashboard",
"obligation task management with automated deadline tracking", "Contract
lifecycle management (creation, renewal, obligations)") — yet only
lease-specific contracts exist (`bookkeeping-lease-contracts` + 4 sibling
lease specs) and IFRS-15 contract *linking*. There is no generic CLM spec and
no in-flight change.

Company conventions are load-bearing here and explicitly honored:

- **Contract documents live in NC Files** — the register stores file
  references (link, don't store); full-text search over document content is
  Nextcloud's, not ours.
- **Obligation tasks surface via NC Tasks / Deck** — the actionable to-do is
  an NC Tasks VTODO (or Deck card); the register keeps only the compliance
  metadata + a link (per ADR-022 / "content types belong in leaves").
- **A counterparty is a Nextcloud entity** — the contract references an NC
  addressbook contact (`counterpartyReference`); no Customer/Party schema is
  invented.
- **App-local OR schemas only for the domain layer** — `Contract` and
  `ContractObligation`; renewal terms are an embedded object on `Contract`;
  the spend rollup is a declarative aggregation, not a schema or a PHP
  report service.
- **Notifications use the x-openregister-notifications dialect** (ADR-031) —
  scheduled renewal-decision and obligation-deadline rules plus
  field-change-conditioned status rules; no imperative dispatch.

**Depends on:**
- `bookkeeping-document-attachment-integration` (link-don't-store FK contract
  for source documents)
- `bookkeeping-cost-centers-dimensions` (spend dashboard dimension reuse)
- `shillinq-notifications` (notification rule conventions; that change
  explicitly defers the contract-renewal rule "until a contract is modelled"
  — this change models it and lands the rule)
- `bookkeeping-purchase-order-3way` / `add-shillinq-accounts-payable-core` /
  `add-shillinq-accounts-receivable-core` (CHAINED: the committed/invoiced
  spend rollup consumes their `PurchaseOrder` / `APInvoice` / `ARInvoice`
  schemas via a `contractReference` FK once those changes land)
- `bookkeeping-lease-contracts` (lease contracts become a specialization
  linked from the generic repository; no regression, no field duplication)

## Motivation

Every Shillinq target segment manages contracts today in folders and
spreadsheets: ZZP'ers track client service agreements and their own SaaS
subscriptions; SMBs track supplier contracts, leases, and maintenance
agreements; public-sector bodies are *required* to track contracts,
renewal/notice windows, and contract spend (rechtmatigheid, EU procurement
thresholds, verplichtingenadministratie). Missed notice periods auto-renew
unwanted contracts; missed obligations (insurance certificates, SLA reviews,
indexation triggers) create real financial and compliance exposure.

The bookkeeping side of Shillinq already produces the two numbers a contract
manager needs — committed spend (purchase orders) and invoiced spend (AP/AR
invoices) — but with no contract to hang them on, "are we overspending against
this contract?" is unanswerable. Competitors ship CLM as a separate paid
module; Shillinq promised it in its own one-line description.

## Affected Projects

- [x] Project: shillinq — two new register schemas (`Contract`,
  `ContractObligation`) in an ADR-037 register fragment; lifecycle states;
  notification rules; spend aggregations; manifest pages (repository,
  detail, obligations, spend dashboard); one thin NC Tasks/Deck bridge.
- [ ] Project: openregister — consumer only (lifecycle engine, notification
  engine, aggregations); no OR changes required.
- [ ] Project: docudesk — optional soft consumer (signature workflow on a
  contract's NC Files document); out of base scope.

## Scope

### In Scope

- **`Contract` schema** in the ADR-037 fragment
  `lib/Settings/register.d/contract-lifecycle-management.json`: identity
  (contractNumber, title, description, contractType), counterparty as an NC
  addressbook contact reference, dates (startDate, endDate,
  noticePeriodDays, renewalDecisionDate computed), embedded `renewalTerms`
  object (renewalType none/manual/auto-renew, renewalTermMonths,
  priceIndexation), value (totalContractValue, currency, costCenter /
  dimension FKs per `bookkeeping-cost-centers-dimensions`), owner
  (contractOwner uid), document links (NC Files file references), lifecycle
  status, predecessor/successor self-FKs for renewal chains, and an optional
  `specializationReference` (e.g., to a `lease-contract` record).
- **`ContractObligation` schema**: contract FK, title, clauseReference,
  obligation type (deliverable / payment / compliance / review / notice),
  dueDate, recurrence, responsible uid, status
  (open / in-progress / done / waived / overdue), evidence file reference
  (NC Files), and the NC Tasks/Deck link (`taskUri`).
- **Lifecycle** (`x-openregister-lifecycle`): draft → active → expiring →
  expired; active/expiring → terminated; expiring → renewed (renewal creates
  a successor draft contract carrying the renewal terms forward).
- **Notification rules** (`x-openregister-notifications`, ADR-031 dialect):
  scheduled renewal-decision-window rule, scheduled obligation-deadline
  rule, and `updated`+field-change-condition rules for status transitions.
  Subjects in `nl` + `en`, metadata-only. This lands the contract-renewal
  rule that `shillinq-notifications` explicitly deferred.
- **Spend dashboard** (`x-openregister-aggregations`): committed
  (PurchaseOrder rows referencing the contract) vs invoiced
  (APInvoice/ARInvoice rows referencing the contract) vs totalContractValue,
  sliceable by cost center / dimension. Declarative; chained behind the
  schema-owning changes.
- **Document attachment**: NC Files references on Contract and
  ContractObligation (link, don't store); content full-text search delegated
  to Nextcloud; repository metadata search via OR `_search`.
- **Lease relation, no regression**: `lease-contract` (IFRS 16) stays the
  canonical lease accounting record; a generic `Contract` row may point at it
  via `specializationReference` so leases appear in the repository without
  duplicating any lease field or calculation.
- **Frontend** (ADR-037 manifest fragment
  `src/manifest.d/contract-lifecycle-management.json`): "Contracts" nav
  group with repository index (search + filters), contract detail (lifecycle
  actions, documents, obligations, spend), obligations overview, and spend
  dashboard page.
- **i18n**: ENGLISH source keys in `t('shillinq', '…')`, `nl` + `en`
  catalogs.
- **One thin integration bridge**: `lib/Service/ObligationTaskBridge.php`
  creating/linking the NC Tasks VTODO (or Deck card) for an obligation via
  OCP APIs. This is integration glue, not domain logic (ADR-031 exception
  path, fail-closed).

### Out of Scope

- **E-signature workflow** — docudesk / openconnector territory; a contract
  document is signed there and linked here.
- **Contract authoring / clause templates / AI clause extraction** — future
  capability; the repository links finished documents.
- **Procurement workflow** (RFQ → PO) — owned by
  `bookkeeping-purchase-order-3way`; CLM only consumes the PO's
  `contractReference`.
- **IFRS 16 / IFRS 15 accounting** — owned by the lease suite and
  `bookkeeping-ifrs15-revenue`; CLM links, never recomputes.
- **Re-implementing tasks, files, versioning, or full-text search** — NC
  Tasks/Deck, NC Files (with versioning), and Nextcloud full-text search are
  consumed, never mirrored (ADR-022).

## Approach

One delta adding TWO register schemas + lifecycle + notifications +
aggregations as declarative metadata, plus one thin bridge service:

1. **`Contract`** — master record; counterparty is an NC contact reference;
   renewal terms embedded; documents are NC Files references; lifecycle
   states drive the repository views and the notification rules.
2. **`ContractObligation`** — deadline-bearing obligation row; the actionable
   task lives in NC Tasks/Deck (created by the bridge, linked by `taskUri`);
   the register keeps compliance metadata + evidence link.
3. **Spend rollup** — `x-openregister-aggregations` over PurchaseOrder /
   APInvoice / ARInvoice rows carrying `contractReference`; rendered on the
   contract detail page and the spend dashboard. No PHP report service.
4. **Notifications** — `x-openregister-notifications` rules on both schemas
   per the `shillinq-notifications` conventions (scheduled triggers with
   filters on real date/state fields; `updated` triggers with field-change
   conditions; recipients via `{"kind":"field"}` on `contractOwner` /
   `responsible` plus `{"kind":"object-acl","permission":"manage"}` and the
   `shillinq-finance` group).

Specs: one spec file `contract-lifecycle-management` with REQ-CLM-001 …
REQ-CLM-009.

## New Dependencies

None. NC Tasks (CalDAV VTODO via OCP) and NC Files are core Nextcloud
surfaces; Deck is an optional enhancement (bridge degrades to NC Tasks when
Deck is absent). OpenRegister lifecycle/notification/aggregation engines are
already Shillinq dependencies.

## Impact

- `lib/Settings/register.d/contract-lifecycle-management.json` — NEW ADR-037
  register fragment: `Contract` + `ContractObligation` schemas, lifecycle,
  notifications, calculations (renewalDecisionDate), aggregations (spend
  rollup), seed contract types.
- `src/manifest.d/contract-lifecycle-management.json` — NEW ADR-037 manifest
  fragment: "Contracts" nav group + repository / detail / obligations /
  spend-dashboard pages.
- `lib/Service/ObligationTaskBridge.php` — NEW thin NC Tasks/Deck bridge
  (create VTODO or Deck card, store `taskUri`, fail-closed).
- `lib/Lifecycle/ContractLifecycleGuard.php` — NEW, only if a cross-field
  precondition cannot be expressed declaratively (e.g., "terminated requires
  terminationReason"); ADR-031 exception path.
- `l10n/en.json`, `l10n/nl.json` — new keys (ENGLISH source strings).
- `tests/Unit/Service/ContractLifecycleFragmentTest.php`,
  `tests/Unit/Service/ObligationTaskBridgeTest.php` — fragment shape +
  bridge behavior.
- `tests/e2e/` — repository, detail, obligation, spend-dashboard UI specs
  (gate-19).

## Cross-Project Dependencies

- **bookkeeping-purchase-order-3way** — CHAINED. `PurchaseOrder` gains the
  optional `contractReference` FK consumed by the committed-spend rollup;
  the rollup rule MUST NOT be attached before that schema exists.
- **add-shillinq-accounts-payable-core / add-shillinq-accounts-receivable-core**
  — CHAINED. `APInvoice` / `ARInvoice` gain the optional `contractReference`
  FK consumed by the invoiced-spend rollup.
- **bookkeeping-lease-contracts** — soft. Generic contracts may link a
  `lease-contract` specialization; the lease register and its IFRS 16
  pipeline are untouched.
- **shillinq-notifications** — convention owner. The deferred contract
  renewal/expiry rule is landed here, on the real `Contract` schema.
- **bookkeeping-verplichtingenadministratie** — soft consumer. Commitment
  accounting can reference contracts as the commitment source.
- **bookkeeping-archiefwet-retention** — soft. Retention classes apply to
  contract records like any other register.

## Risks

### Risk 1: Spend rollup depends on schemas owned by unmerged changes

**Severity**: Medium
**Mitigation**: The rollup is declared but CHAINED (same pattern as
`shillinq-notifications`): the contract register, lifecycle, obligations,
documents, and notifications all ship and work without it; the spend
dashboard renders "no linked spend data" until `PurchaseOrder` /
`APInvoice` / `ARInvoice` exist and carry `contractReference`. No invented
schemas, no stub data.

### Risk 2: Obligation task drift between the register and NC Tasks/Deck

**Severity**: Medium
**Mitigation**: Single direction of truth — deadlines and compliance status
live on `ContractObligation`; the NC task is a *surface* (created once,
linked by `taskUri`, completion read back opportunistically). The deadline
notification fires from the OR notification engine on the register row, so a
deleted/ignored task never silences a deadline. Bridge failures are logged
and surfaced on the obligation row; they never block obligation CRUD.

### Risk 3: Lease overlap creates a second source of truth

**Severity**: Low
**Mitigation**: Hard rule in the spec (REQ-CLM-007): the generic `Contract`
linked to a `lease-contract` MUST NOT duplicate lease payment terms, IBR, or
classification fields; it carries only repository metadata + the link. The
lease suite's specs and tests are untouched.

### Risk 4: "Expiring" state requires time-based evaluation

**Severity**: Low
**Mitigation**: The expiring transition is evaluated by the OR scheduled
notification/lifecycle machinery (nightly), not by a bespoke cron in
Shillinq. The scheduled renewal-decision notification filters on the real
`renewalDecisionDate` field, so even if the state flip lags, the
notification SLA holds.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR;
Shillinq ships without CLM, as today.

**Post-merge, before adoption:** the register fragment and manifest fragment
are self-contained; removing the fragment files removes the capability. No
other register is modified (the `contractReference` FKs land with their
owning changes).

**Production, after adoption:** contract and obligation records remain in OR
(soft-delete per OR conventions); NC Files documents and NC Tasks are native
Nextcloud data and survive independently. Disabling the manifest fragment
hides the UI without data loss.

## Open Questions

1. **Deck vs NC Tasks default** — bridge to NC Tasks (always available) with
   Deck as an opt-in per-contract board, or admin-configurable default?
   Resolved during implementation of the bridge (Task 14).
2. **Repository full-text scope** — is OR `_search` over contract metadata +
   NC full-text over linked documents sufficient for the README's
   "full-text search" promise, or do we want a combined search view in v1?
   Resolved at design review (D6 documents the v1 stance).
3. **Auto-renew execution** — does `renewalType=auto-renew` auto-create the
   successor contract at the decision date, or only notify? V1 notifies and
   offers one-click renewal (human-in-the-loop); revisit after adoption.
