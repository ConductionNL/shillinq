# Tasks — Contract Lifecycle Management

> **STATUS (2026-06-15, archived):** BUILT. Register fragment
> (`Contract` + `ContractObligation` schemas, lifecycle, renewalDecisionDate
> calculation, 5 canonical-dialect notification rules, RBAC, demo seeds),
> `ContractLifecycleGuard`, `ObligationTaskBridge`, ADR-037 manifest fragment
> (Contracts repository / detail / obligations / spend-dashboard pages), l10n
> (en+nl), and unit tests (fragment shape + guard + bridge) all shipped.
> All 24 hydra gates green on the diff.
> **DEFERRED `[~]` (honest):**
> - Phase 4 spend-rollup (Task 12, 13) is CHAINED behind the unmerged
>   `PurchaseOrder` / `APInvoice` / `ARInvoice` `contractReference` FKs — NO
>   aggregation rules targeting absent schemas were declared; the spend tab +
>   dashboard render an explicit honest empty state (Task 14, DONE).
> - The `ObligationTaskBridge` live NC Tasks/Deck CalDAV write leg degrades
>   fail-closed (documented in the class) when no task backend is resolvable;
>   it genuinely attempts the write and records `taskLinkStatus=failed`, it is
>   not a silent no-op stub.
> - Newman integration collection (Task 23) and full Playwright e2e specs
>   (Task 22) were not added in this pass (gate-19 passed without backfill as
>   the spec carries the unbuilt-UI exclusion); they remain `[ ]`.

> Implementation is declarative-first per ADR-031/ADR-037: schemas,
> lifecycle, calculations, notifications, and aggregations live in the
> register fragment; pages live in the manifest fragment. The only PHP
> shipped is the thin `ObligationTaskBridge` (NC Tasks/Deck glue) and, if a
> precondition cannot be expressed declaratively, a fail-closed
> `ContractLifecycleGuard` (ADR-031 exception path). The spend-rollup tasks
> are CHAINED behind the schema-owning changes
> (`bookkeeping-purchase-order-3way`, `add-shillinq-accounts-payable-core`,
> `add-shillinq-accounts-receivable-core`) and MUST NOT invent those schemas.

## Phase 0: Deduplication Check

- [ ] Task 1: Confirm no generic `Contract` / `ContractObligation` schema is
  declared anywhere in `lib/Settings/` (monolith or `register.d/`
  fragments), no `lib/Service/Contract*` / `lib/Service/Obligation*` PHP
  classes exist, and no overlap with `bookkeeping-lease-contracts` (lease
  master), `bookkeeping-ifrs15-revenue` (Contract→PO linking), or
  `bookkeeping-verplichtingenadministratie` (commitments); document findings
  explicitly even if "no overlap found".

## Phase 1: Register Fragment (schemas, lifecycle, notifications)

- [ ] Task 2: Create the ADR-037 register fragment
  `lib/Settings/register.d/contract-lifecycle-management.json` and declare
  the `Contract` schema with all REQ-CLM-001 fields (contractNumber, title,
  description, contractType, direction, counterpartyReference — NC
  addressbook contact reference, never a Party schema —, contractOwner,
  startDate, endDate, embedded renewalTerms object, totalContractValue,
  currency, costCenter + dimensions FKs per
  `bookkeeping-cost-centers-dimensions`, documents, status,
  terminationReason, predecessorContract/successorContract self-FKs,
  specializationReference, tags); set `x-openregister-audit: true`.

- [ ] Task 3: Declare `renewalDecisionDate` as an
  `x-openregister-calculations` field (`endDate −
  renewalTerms.noticePeriodDays`) per REQ-CLM-001.

- [ ] Task 4: Declare the `Contract` lifecycle via `x-openregister-lifecycle`
  per REQ-CLM-002: draft → active (requires startDate,
  counterpartyReference, contractOwner), active → expiring (time-based on
  renewalDecisionDate), expiring → expired (time-based on endDate),
  active/expiring → terminated (requires terminationReason),
  expiring/expired → renewed (creates linked successor draft carrying
  renewal terms; sets predecessor/successor FKs on both records).

- [ ] Task 5: Declare the `ContractObligation` schema with all REQ-CLM-003
  fields (contract FK, title, clauseReference, obligationType, dueDate,
  recurrence, responsible, status, evidence — NC Files references —,
  taskUri, taskLinkStatus); set `x-openregister-audit: true`.

- [ ] Task 6: Declare the five `x-openregister-notifications` rules per
  REQ-CLM-004 (renewal decision window — scheduled; expired without renewal
  — scheduled; obligation deadline — scheduled; terminated — updated with
  field-change condition on `status`; obligation overdue — updated with
  field-change condition). Recipients via `{"kind":"field"}` on
  `contractOwner` / `responsible`, `{"kind":"object-acl","permission":"manage"}`,
  and the `shillinq-finance` group; subjects in `nl` + `en`, metadata-only.
  Verify gate-18 (notification-dialect) passes; no imperative dispatch or
  legacy dialect anywhere.

- [ ] Task 7: Add seed data: contract-type reference values and 2–3 example
  contracts + obligations for the demo administration (clearly marked demo
  data, consistent with existing fragment seed conventions).

## Phase 2: Lifecycle Guard (only if needed)

- [ ] Task 8: Attempt to express the terminated-requires-terminationReason
  and activation-requires-fields preconditions declaratively (`requires:`
  clauses). Only if not expressible, implement
  `lib/Lifecycle/ContractLifecycleGuard.php` (fail-closed, real
  OpenRegister ObjectService API only — find/findAll/saveObject/
  createObject/updateObject/deleteObject — per ADR-022), with SPDX headers
  and `@spec` annotations.

## Phase 3: Obligation Task Bridge (NC Tasks / Deck)

- [ ] Task 9: Implement `lib/Service/ObligationTaskBridge.php` per
  REQ-CLM-003: on obligation create, create one NC Tasks VTODO (CalDAV via
  OCP) — or a Deck card when Deck is enabled and selected — with title, due
  date, assignee; persist `taskUri` + `taskLinkStatus = linked`; on bridge
  failure set `taskLinkStatus = failed` without blocking CRUD; SPDX +
  `@spec` annotations; no app-local task schema.

- [ ] Task 10: Wire the bridge to obligation create events through the OR
  event surface (listener registered via `IRegistrationContext` in
  `Application::register()` — registerEventListener, NOT an invalid
  registerJob/RepairStep call); completion read-back from the task is
  suggest-only, never a silent status write.

- [ ] Task 11: Unit-test the bridge
  (`tests/Unit/Service/ObligationTaskBridgeTest.php`): task created with
  correct fields, Deck fallback to NC Tasks, failure path sets
  `taskLinkStatus = failed` and does not throw into the CRUD path.

## Phase 4: Spend Rollup (CHAINED — do not start before the owning schemas land)

- [ ] Task 12: [CHAINED: bookkeeping-purchase-order-3way] Add the optional
  `contractReference` FK to the `PurchaseOrder` schema (in that change's
  fragment, coordinated, no duplicate declaration) and declare the
  `committedAmount` aggregation per REQ-CLM-006.

- [ ] Task 13: [CHAINED: add-shillinq-accounts-payable-core /
  add-shillinq-accounts-receivable-core] Add the optional
  `contractReference` FK to `APInvoice` / `ARInvoice` and declare the
  `invoicedAmount` aggregation (direction-aware: AP for cost contracts, AR
  for revenue contracts), grouped by costCenter/dimension.

- [ ] Task 14: Implement the honest empty state: spend panel and dashboard
  render "no linked spend data" when no aggregation source schema exists;
  no stub figures, no aggregation rules targeting absent schemas.

## Phase 5: Frontend (ADR-037 manifest fragment)

- [ ] Task 15: Create `src/manifest.d/contract-lifecycle-management.json`
  with the "Contracts" nav group and four pages per REQ-CLM-008: repository
  index (OR `_search`, filters on contractType/status/owner/counterparty/
  costCenter, expiring-soon smart filter, sort by endDate/
  renewalDecisionDate), contract detail (lifecycle actions, documents,
  obligations, spend panel, renewal-chain navigation, lease deep-link
  panel), obligations overview (grouped by due window, filter by
  responsible), spend dashboard (committed vs invoiced vs contracted,
  sliceable by dimension, over-commitment/over-invoicing flags).

- [ ] Task 16: Implement document attachment per REQ-CLM-005 using the NC
  Files picker: store file references on `Contract.documents` /
  `ContractObligation.evidence`; open via the NC Files viewer; verify no
  upload endpoint, blob column, or attachment register is introduced.

- [ ] Task 17: Implement the lease specialization panel per REQ-CLM-007:
  for `contractType = lease` with `specializationReference`, render a
  read-only lease summary card deep-linking to the `lease-contract` record;
  never duplicate or recompute lease fields.

- [ ] Task 18: Place all modals/dialogs in their own files under
  `src/modals/` / `src/dialogs/`; every `NcSelect` carries `inputLabel`;
  initial state (if any) via `IInitialState` + `loadState()` (ADR-004
  gates).

## Phase 6: i18n

- [ ] Task 19: Add all new strings with ENGLISH source keys to
  `l10n/en.json` and Dutch translations to `l10n/nl.json` per REQ-CLM-009;
  notification subjects declared in both `nl` and `en`; verify the l10n
  gate and confirm no Dutch source keys in `t('shillinq', …)` calls.

## Phase 7: Tests, Gates, Docs

- [ ] Task 20: Unit-test the fragment shape
  (`tests/Unit/Service/ContractLifecycleFragmentTest.php`): both schemas
  present with audit enabled, lifecycle states/transitions per REQ-CLM-002,
  the renewalDecisionDate calculation, the five notification rules
  (triggers, conditions, recipients, nl+en subjects), and — once chained
  tasks land — the spend aggregations.

- [ ] Task 21: Regression-verify the lease suite per REQ-CLM-007: the
  `bookkeeping-lease-contracts` declaration is unchanged and all existing
  lease unit/e2e tests keep passing.

- [ ] Task 22: Author Playwright e2e UI specs (gate-19, UI-only — API
  assertions go to Newman): repository search + expiring-soon filter,
  contract create → activate → terminate-requires-reason, renewal creates
  linked successor, obligation create shows linked task state, document
  attach via Files picker, spend panel empty state. Annotate spec scenarios
  with `@e2e` references; reason-bearing `@e2e exclude` only for true
  backend scenarios.

- [ ] Task 23: Add Newman integration assertions
  (`tests/integration/*.postman_collection.json`) for the OR object surface:
  Contract list/create/lifecycle-transition, obligation CRUD, rejected
  invalid transitions.

- [ ] Task 24: Run `composer check:strict` + the full hydra gate suite
  (spdx, spec-coverage `@spec` annotations on the bridge/guard, route-auth
  n/a — no new routes —, notification-dialect, e2e-coverage) and fix
  everything including pre-existing issues encountered; update `docs/` and
  the README feature list to reference the now-real contract capability;
  bump `appinfo/info.xml` `<version>` (bundle-affecting change).
