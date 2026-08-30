# Tasks — Rate Card Engine

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `rate-card-management` spec
> — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `rate-card-management` capability spec already exists, no `RateCardTemplate`/`RateCardVersion`/`RateSchedule` schemas are declared, and no `lib/Service/Rate*` / `lib/Db/RateCard*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "enables multi-tier rate hierarchies for time-tracking and invoicing"
- [x] Task 2: Author `specs/rate-card-management/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: none (foundational)` header, `REQ-RATE-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (tier-precedence logic stability, effective-date enforcement, historical rate disputes) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (rate cards versioned by effective-date), D2 (tier-specific schedules), D3 (rate lookup is aggregation query), D4 (resolved rates materialized), D5 (non-overlapping effective dates), D6 (user > role > project > client > blended precedence)
- [x] Task 5: Declare the `RateCardTemplate` schema in `lib/Settings/shillinq_register.json` with all REQ-RATE-002 fields (templateId, name, description, tierStructure, currency, administrationId, lifecycleState, createdAt)
- [x] Task 6: Declare the `RateCardVersion` schema in `lib/Settings/shillinq_register.json` with all REQ-RATE-003 fields (versionId, templateId, effectiveDate, expiryDate, status, administrationId) — ensure non-overlapping effective-date window validation
- [x] Task 7: Declare the `RateSchedule` schema in `lib/Settings/shillinq_register.json` with all REQ-RATE-004 fields (scheduleId, versionId, tier, entityId, rate, unit, effectiveDate, expiryDate, volumeBrackets, administrationId, status) — with REQ-RATE-006 overlap validation
- [x] Task 8: Declare the `RateRecord` schema in `lib/Settings/shillinq_register.json` with all REQ-RATE-007 fields (recordId, lookupDate, userId, roleId, projectId, clientId, resolvedTier, resolvedScheduleId, resolvedRate, resolvedUnit, effectiveWindowStart, effectiveWindowEnd, administrationId, createdAt) — immutable register
- [x] Task 9: Implement rate-lookup aggregation per REQ-RATE-005 — accept (userId, roleId, projectId, clientId, serviceType, lookupDate); filter by version effective-date + schedule effective-date; rank by tier precedence (user > role > project > client > blended); return first match or error — NOT a PHP service, pure aggregation query or documented `RateResolutionGuard` per ADR-031 exception
- [x] Task 10: Implement effective-date validation per REQ-RATE-006 — ensure no overlapping schedules per (tier, entityId); validation at schema or aggregation-precondition level; reject overlap errors with clear messaging
- [x] Task 11: Implement RateRecord materialization per REQ-RATE-007 — each rate lookup (aggregation or fallback) MUST create an immutable `RateRecord` entry with resolved tier, rate, effective window, and timestamp; audit-trail queryable
- [x] Task 12: Implement future-date enforcement per REQ-RATE-009 — new RateCardVersion and RateSchedule effectiveDate MUST be ≥ today; reject retroactive changes with error message
- [x] Task 13: Add 3 manifest navigation entries (`Rate Cards`, `Rate Schedules`, `Rate Audit Trail`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-RATE-008; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with `RateCardTemplate`, `RateCardVersion`, `RateSchedule`, and `RateRecord` entries, reconciling against any existing Rate/Billing data-model entries; note that `RateCard` (supplier-focused, ADR-000) is distinct from rate-card-engine (employee/project/client billing)

## Verification

`openspec validate` must exit clean on the change folder. Accountant-persona
peer review (e.g. `/test-persona-janwillem` for SMB) confirms the
rate-card setup flow (template → version → schedules with effective
dates; tier precedence; blended fallback) matches Dutch SMB practice
and expectations. Architecture reviewer confirms ADR-022 + ADR-031
compliance (no app-local rate service; aggregation query or
documented PHP-guard fallback; manifest carries navigation). No source
code changes outside `openspec/changes/rate-card-engine/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests:**
  - Rate-lookup aggregation: user > role > project > client >
    blended precedence order (pre-declared on Task 9)
  - Effective-date filtering: schedule outside window is skipped
  - Overlap validation: overlapping schedules rejected (pre-declared
    on Task 10)
  - RateRecord materialization: each lookup stored with resolved
    tier + rate + effective window (pre-declared on Task 11)
  - Future-date enforcement: retroactive changes rejected (pre-declared
    on Task 12)
  - Volume-bracket discount calculation (if applicable)

- **Playwright MCP browser tests:**
  - Rate Cards index: list all templates, filter by status (pre-declared
    on Task 13)
  - Rate Schedules index: list active schedules per version; create /
    edit schedule (pre-declared on Task 13)
  - Rate Audit Trail: query by lookupDate + resolvedTier; verify
    historical rates immutable (pre-declared on Task 13)

- **Integration test:**
  - End-to-end: create template → version → schedules → perform lookup
    → verify RateRecord materialized → verify manifest pages load

- **CI exit code:** `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors:

- `docs/user-guide/billing/rate-cards.md` per ADR-030 journeydoc
  convention
- Screenshots: rate-card template setup, version effective-date windows,
  schedule tier configuration, rate lookup results, audit trail query
  results
- Operator flow: "How to set up multi-tier rates for a project",
  "How to change rates forward-looking without affecting invoices",
  "How to audit historical rates for a disputed invoice"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- UI labels: `Rate Cards`, `Rate Card Template`, `Rate Card Version`,
  `Rate Schedule`, `Rate Audit Trail`, `Effective Date`, `Expiry Date`,
  `Tier`, `Entity`, `Blended Default`, `User Rate`, `Role Rate`,
  `Project Rate`, `Client Rate`
- Units: `Hourly`, `Daily`, `Monthly`, `Fixed Price`
- Statuses: `Active`, `Inactive`, `Archived`, `Draft`, `Expired`
- Tier names: `User`, `Role`, `Project`, `Client`, `Blended`
- Error messages: "Effective-date must be today or later",
  "Effective-date window overlaps with existing schedule", "No
  applicable rate found; falling back to blended default"
