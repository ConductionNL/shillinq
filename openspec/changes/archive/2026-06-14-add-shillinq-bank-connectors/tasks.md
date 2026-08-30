# Tasks — Bank Connectors

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-bank-connectors` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [x] Task 1: Confirm no `BankConnection` schema or `bookkeeping-bank-connectors` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`); confirm T3 `bookkeeping-bank-reconciliation` manual MT940 path is intact (no overlap)
- [x] Task 2: Author `specs/bookkeeping-bank-connectors/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-bank-reconciliation (T3)` header, `REQ-BC-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (credentials in shillinq, SCA renewal cadence, openconnector availability) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Decisions (credentials in openconnector, time-based transition, `ScheduledWorkflow` for polling, declarative notifications, openconnector SCA handoff) and Reuse Analysis table per hydra `rules.design`
- [x] Task 5: Declare the `BankConnection` schema in `lib/Settings/shillinq_register.json` with REQ-BC-002 fields (connectionName, aggregatorSourceSlug, consentRef, ibanList, lastPolledAt, consentExpiresAt, lifecycleState, administrationId); confirm NO field names match `*Secret*` / `*ClientId*` / `*ApiKey*` / `*Token*` patterns per REQ-BC-003
- [x] Task 6: Add `x-openregister-lifecycle` to `BankConnection` declaring `pending → active → expiring → expired / revoked` transitions per REQ-BC-005; `active → expiring` is time-based (fires 14 days before `consentExpiresAt`) per REQ-BC-006; `expiring → expired` fires on the deadline; `expiring` state emits notification to configured recipient
- [x] Task 7: Register an OR `ScheduledWorkflow` for transaction polling (calls openconnector aggregator source by slug, normalises aggregator JSON to CAMT.053, attaches via docudesk, creates `BankStatement` records) per REQ-BC-004 + ADR-031 path 2; no `BankPollingJob extends TimedJob`; no Guzzle/Symfony HttpClient/curl_init in `lib/Service/Bank*` / `lib/Service/Psd2*` / `lib/Service/Aggregator*`
- [x] Task 8: Add `x-openregister-notifications` to `BankStatement` for new-transaction notifications per REQ-BC-005; declarative recipient resolution and channel fan-out
- [x] Task 9: Add Bank Connections navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > Bank Connections`, `type: index` page binding to `BankConnection`, `type: detail` page surfacing the consent-renewal action routing to openconnector SCA and remaining-days countdown when `state = expiring`) per REQ-BC-007; `node tests/validate-manifest.js` exits 0
- [x] Task 10: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note introducing `BankConnection` and its references to openconnector source slugs + docudesk attachment URIs

## Verification

`openspec validate` must exit clean on the change folder. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no credentials in shillinq schemas; no aggregator HTTP clients in shillinq; transaction polling via `ScheduledWorkflow` not TimedJob; consent-renewal via openconnector SCA; notifications declarative; manifest carries navigation) — security reviewer specifically greps for credential field names and HTTP-client usages. Bookkeeper-persona peer review confirms the consent-renewal UX is bounded (one click, SCA in bank UI). No source code changes outside `openspec/changes/add-shillinq-bank-connectors/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting lifecycle time-based transition fires at 14 days; consent-renewal routes through openconnector (mocked source); CAMT.053 attachment via docudesk; notification fires on new transaction; schema rejects credential field names (pre-declared on Tasks 5–8); Playwright MCP browser tests for the Bank Connections index + detail pages including the renewal action (Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/bank-connections.md` per ADR-030 journeydoc convention and commits a bank-connection renewal screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Bank Connection`, `Bankkoppeling`, `Consent`, `Toestemming`, `SCA Renewal`, `Expiring`, `Expired`, `Revoked`, `Aggregator`, `Last Polled`.
