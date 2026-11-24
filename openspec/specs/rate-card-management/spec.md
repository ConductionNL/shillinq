---
status: done
---

# Spec: rate-card-management

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** None (foundational)

## Purpose

This specification defines the requirements for rate card management in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/schema: rate card register — not browser-testable


### Requirement: REQ-RATE-001: Rate cards SHALL be declared as `RateCardTemplate` + `RateCardVersion` + `RateSchedule` registers

Rate-card management MUST be expressed as three new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `RateCardTemplate` — reusable rate-card structure (name, tier
  configuration, description, audit trail for schema changes).
- `RateCardVersion` — effective-dated variant of a template (start
  date, expiry date, currency, status).
- `RateSchedule` — tier-specific rates (tier name, entity reference,
  fixed rate, volume brackets, effective window, administrationId).

Rate-card-engine enables multi-tier rate resolution: given a lookup
request (user, role, project, client, date), the most-specific
applicable rate is returned by precedence (user > role > project >
client > blended default). No PHP rate-calculation service; lookup is
declarative aggregation per REQ-RATE-005.

#### Scenario: Reviewer confirms no parallel rate table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `rate_card`, `rate_schedule`, `rate_master`, or
  `billing_rate*`
- **THEN** no such classes SHALL exist.

#### Scenario: Rate-card version is effective-dated

- **GIVEN** a `RateCardVersion` with effectiveDate=2026-02-01
- **WHEN** a rate lookup occurs on 2026-01-31
- **THEN** the version SHALL NOT be used; prior version or error
  SHALL be returned.

### Requirement: REQ-RATE-002: The `RateCardTemplate` schema SHALL declare a minimum field set

The system SHALL satisfy this requirement: The `RateCardTemplate` schema SHALL declare a minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `templateId` | string | Yes | Unique rate-card template identifier |
| `name` | string | Yes | Human-readable template name |
| `description` | string | No | Purpose and scope of this template |
| `tierStructure` | enum array | Yes | Ordered list of tiers: `["user", "role", "project", "client", "blended"]` |
| `currency` | string | Yes | ISO 4217 currency code (default EUR) |
| `administrationId` | string | Yes | FK to administration (per-OU isolation) |
| `lifecycleState` | enum | Yes | One of `active`, `archived` |
| `createdAt` | datetime | Yes | Template creation timestamp |

Schema.org annotation: `schema:Thing`.

#### Scenario: Minimal rate-card template is valid

- **GIVEN** the schema
- **WHEN** `{templateId:"RCT-2026-001", name:"Consulting Rates", tierStructure:["user","role","project","client","blended"], currency:"EUR", administrationId:"adm-1", lifecycleState:"active", createdAt:"2026-01-01T00:00:00Z"}` is saved
- **THEN** validation MUST pass.

### Requirement: REQ-RATE-003: The `RateCardVersion` schema SHALL declare a minimum field set with effective-date window

The system SHALL satisfy this requirement: The `RateCardVersion` schema SHALL declare a minimum field set with effective-date window.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `versionId` | string | Yes | Unique version identifier |
| `templateId` | string | Yes | FK to `RateCardTemplate` |
| `effectiveDate` | date | Yes | Start date this version is active |
| `expiryDate` | date | No | End date (inclusive); null = open-ended |
| `status` | enum | Yes | One of `draft`, `active`, `expired`, `archived` |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Thing`.

#### Scenario: Non-overlapping effective-date windows per template

- **GIVEN** a template with version V1 (2026-01-01 to 2026-03-31)
  and version V2 (2026-04-01 onwards)
- **WHEN** both versions are queried
- **THEN** no overlap SHALL exist; lookup on 2026-03-15 returns V1,
  2026-04-15 returns V2.

#### Scenario: Version expiry is inclusive

- **GIVEN** version V1 with expiryDate=2026-03-31
- **WHEN** rate lookup occurs on 2026-03-31
- **THEN** V1 SHALL still be applicable; lookup must succeed.

### Requirement: REQ-RATE-004: The `RateSchedule` schema SHALL declare tier-specific rates with effective-date lifecycle

The system SHALL satisfy this requirement: The `RateSchedule` schema SHALL declare tier-specific rates with effective-date lifecycle.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `scheduleId` | string | Yes | Unique rate schedule identifier |
| `versionId` | string | Yes | FK to `RateCardVersion` |
| `tier` | enum | Yes | One of `user`, `role`, `project`, `client`, `blended` |
| `entityId` | string | No | Entity identifier (userId, roleId, projectId, clientId); null for blended-default tier |
| `rate` | number | Yes | Fixed hourly or daily rate in currency units |
| `unit` | enum | Yes | One of `hourly`, `daily`, `monthly`, `fixedPrice` |
| `effectiveDate` | date | Yes | Start date this rate is active |
| `expiryDate` | date | No | End date (inclusive); null = open-ended |
| `volumeBrackets` | array | No | Optional volume-discount array: `[{minUnits: 40, maxUnits: 50, rate: 82}, ...]` |
| `administrationId` | string | Yes | FK to administration |
| `status` | enum | Yes | One of `active`, `inactive`, `archived` |

Schema.org annotation: `schema:Thing`.

#### Scenario: Tier-specific rate is correctly stored

- **GIVEN** the schema
- **WHEN** `{scheduleId:"RS-001", versionId:"RCV-001", tier:"user", entityId:"user-alice", rate:130, unit:"hourly", effectiveDate:"2026-02-01", administrationId:"adm-1", status:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Blended-default rate has null entityId

- **GIVEN** a blended-default schedule for fallback
- **WHEN** `{scheduleId:"RS-DEFAULT", versionId:"RCV-001", tier:"blended", entityId:null, rate:85, unit:"hourly", effectiveDate:"2026-01-01", administrationId:"adm-1", status:"active"}` is saved
- **THEN** validation MUST pass and this schedule SHALL be used for
  tier-precedence fallback.

### Requirement: REQ-RATE-005: Rate resolution SHALL apply tier-precedence aggregation query

Rate lookup aggregation (no PHP service) MUST:

1. Accept input: `(userId, roleId, projectId, clientId, serviceType, lookupDate)`
2. Filter `RateSchedule` records by:
   - `versionId` matching the applicable `RateCardVersion` for
     `lookupDate`
   - `effectiveDate` ≤ `lookupDate` ≤ `expiryDate`
   - `status = "active"`
3. Rank by tier precedence: **user > role > project > client >
   blended**
4. Return the first matching schedule's `rate` and `unit`
5. If no match found, return error or configured fallback rate

#### Scenario: User rate overrides role rate

- **GIVEN** role="Senior" (€120/hour) and user="Alice"
  (€130/hour), both effective on 2026-02-15
- **WHEN** lookup occurs for (user=Alice, role=Senior,
  lookupDate=2026-02-15)
- **THEN** rate MUST be €130/hour (user tier wins).

#### Scenario: Project rate overrides client default

- **GIVEN** client="Acme" (€85/hour) and project="Acme Migration"
  (€95/hour), both effective on 2026-02-15
- **WHEN** lookup occurs for (projectId=Acme-Migration,
  clientId=Acme, lookupDate=2026-02-15)
- **THEN** rate MUST be €95/hour (project tier wins over client).

#### Scenario: Blended default is last resort

- **GIVEN** no user, role, project, or client rate found
- **WHEN** lookup occurs
- **THEN** blended-default rate (tier="blended", entityId=null) MUST
  be returned if status="active".

### Requirement: REQ-RATE-006: Rate lookup MUST be non-overlapping per (tier, entity, date)

Effective-date windows for the same (tier, entityId) pair MUST NOT
overlap. Validation MUST enforce this at schema or aggregation-precondition
level.

#### Scenario: Non-overlapping schedules per entity

- **GIVEN** user-alice rate 2026-01-01 to 2026-03-31 (€130/hour)
  and 2026-04-01 onwards (€135/hour)
- **WHEN** both schedules are saved
- **THEN** validation MUST succeed (no overlap); lookup on
  2026-03-31 returns €130, lookup on 2026-04-01 returns €135.

#### Scenario: Overlapping schedules are rejected

- **GIVEN** user-alice rate 2026-01-01 to 2026-06-30 (€130/hour)
  and 2026-02-01 to 2026-04-30 (€140/hour)
- **WHEN** the second schedule is saved
- **THEN** validation MUST fail with error:
  "Effective-date window overlaps with existing schedule".

### Requirement: REQ-RATE-007: Resolved rates MUST be materialized into a `RateRecord` register for audit trail

Each rate lookup (whether via aggregation query or fallback) MUST be
materialized into a `RateRecord` entry with:

| Field | Type | Purpose |
|---|---|---|
| `recordId` | string | Unique audit record identifier |
| `lookupDate` | date | Date the lookup occurred |
| `userId` | string | Input userId (may be null) |
| `roleId` | string | Input roleId (may be null) |
| `projectId` | string | Input projectId (may be null) |
| `clientId` | string | Input clientId (may be null) |
| `resolvedTier` | enum | Winning tier: user/role/project/client/blended |
| `resolvedScheduleId` | string | FK to winning `RateSchedule` |
| `resolvedRate` | number | Final rate amount |
| `resolvedUnit` | enum | hourly/daily/monthly/fixedPrice |
| `effectiveWindowStart` | date | Schedule's effective start |
| `effectiveWindowEnd` | date | Schedule's effective end |
| `administrationId` | string | FK to administration |
| `createdAt` | datetime | Materialization timestamp |

#### Scenario: Rate lookup is recorded in audit trail

- **GIVEN** a rate lookup resolves to user-rate €130/hour
- **WHEN** the lookup completes
- **THEN** a `RateRecord` entry MUST be created with
  `resolvedTier="user"`, `resolvedRate=130`, and
  `createdAt=<lookup-timestamp>`.

#### Scenario: RateRecord is immutable and queryable

- **GIVEN** a `RateRecord` materialized on 2026-02-15
- **WHEN** the underlying `RateSchedule` is later changed
- **THEN** the `RateRecord` MUST retain the historical resolved rate
  (immutable).

### Requirement: REQ-RATE-008: Manifest navigation SHALL provide rate-card views

The application MUST declare three manifest entries with `type: index`
/ `type: detail` pages:

1. **Rate Cards** — list `RateCardTemplate` + `RateCardVersion`;
   filter by administrationId + status.
2. **Rate Schedules** — list `RateSchedule` per active version;
   filter by tier + entity + effective-date.
3. **Rate Audit Trail** — list `RateRecord` per administration;
   query by lookupDate + resolvedTier + userId.

#### Scenario: Rate-cards index page lists all templates

- **GIVEN** the Rate Cards index page
- **WHEN** loaded for administrationId="adm-1"
- **THEN** all `RateCardTemplate` entries with
  `administrationId="adm-1"` SHALL be listed, grouped by status
  (active, archived).

#### Scenario: Rate audit trail is queryable

- **GIVEN** the Rate Audit Trail page
- **WHEN** filtered by resolvedTier="user" + lookupDate
  range [2026-02-01, 2026-02-28]
- **THEN** all `RateRecord` entries matching the filter SHALL be
  displayed with resolvedRate and effective-window information.

### Requirement: REQ-RATE-009: Effective-date changes MUST be forward-effective (future-dated)

Rate changes (new `RateCardVersion`, new `RateSchedule`) MUST have
`effectiveDate` ≥ today. Retroactive rate changes (effectiveDate <
today) MUST be rejected.

#### Scenario: Future-dated rate change is accepted

- **GIVEN** today = 2026-01-15
- **WHEN** a new `RateSchedule` with effectiveDate=2026-02-01 is saved
- **THEN** validation MUST pass.

#### Scenario: Retroactive rate change is rejected

- **GIVEN** today = 2026-01-15
- **WHEN** a new `RateSchedule` with effectiveDate=2026-01-01 is
  attempted
- **THEN** validation MUST fail with error:
  "Effective-date must be today or later".

### Requirement: REQ-RATE-010: Rate-card engine SHALL support historical rate queries

The application MUST allow querying historical rates:

- **Scenario**: "Show all rate changes for user-alice in Q1 2026"
  → Filter `RateRecord` by userId="alice" + lookupDate range
  [2026-01-01, 2026-03-31]; group by resolvedTier + resolvedRate;
  display timeline.
- **Scenario**: "Audit invoice line INV-001 rate" → Lookup
  `RateRecord` by invoiceLineId (FK from AR/AP invoice) to retrieve
  the resolved rate at invoice-creation time.

#### Scenario: Historical rate audit is available

- **GIVEN** an invoice line created on 2026-02-15 at rate €130/hour
- **WHEN** the operator queries historical rates for that invoice
- **THEN** the `RateRecord` for that lookup MUST be retrievable,
  showing resolved-tier, effective-window, and resolved-rate.

## Verification

`openspec validate` must exit clean on the change folder. Accountant
persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms
the rate-card setup matches Dutch SMB practice (template → version →
schedules with effective dates; blended fallback). Architecture
reviewer confirms ADR-031 compliance (no app-local service; aggregation
query or documented PHP-guard fallback; manifest carries navigation).
No source code changes outside
`openspec/changes/rate-card-engine/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests
for rate-lookup aggregation, tier precedence, effective-date filtering,
overlap validation, and RateRecord materialization; Playwright MCP
browser tests for the 3 manifest views (Rate Cards, Schedules, Audit
Trail); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors `docs/user-guide/billing/rate-cards.md` per ADR-030
journeydoc convention and commits rate-card setup + schedule timeline
screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings
for: `Rate Cards`, `Rate Card Template`, `Rate Card Version`, `Rate
Schedule`, `Rate Audit Trail`, `Effective Date`, `Tier`, `Blended
Default`, `User Rate`, `Role Rate`, `Project Rate`, `Client Rate`,
`Hourly`, `Daily`, `Monthly`, `Fixed Price`, `Volume Bracket`.

### Requirement: An hour resolves its billable rate by tier from the assignment's rate card (REQ-BRPH-001)

The system SHALL resolve the billable rate of an hour from the `RateCardTemplate`
named by the `ProjectAssignment` of the hour's person on the hour's project,
using the `RateCardVersion` effective on the hour's date and the first active
`RateSchedule` in the order user, role, project, client, blended. Each
resolution MUST be written as a `RateRecord` naming the tier and the schedule.

#### Scenario: A personal rate wins over the role rate

- GIVEN rate card Standaard 2026 with role senior at EUR 120 and user Alice at EUR 130
- AND Alice is assigned to project Renovatie Kade 12 with billing role senior and that rate card
- WHEN Alice's 6 hours of 14 October 2026 on that project are resolved
- THEN the rate is EUR 130 from the user tier and the value is EUR 780
- AND a RateRecord records tier user and the schedule of Alice's rate

#### Scenario: The role rate applies when the person has no personal rate

- GIVEN Bram is assigned to the same project with billing role senior and has no user-tier schedule
- WHEN Bram's 4.5 hours are resolved
- THEN the rate is EUR 120 from the role tier and the value is EUR 540

#### Scenario: The client rate applies to an assignment without a role

- GIVEN Chris is assigned with no billing role and the card has client Het Anker at EUR 95
- WHEN Chris's 2 hours are resolved
- THEN the rate is EUR 95 from the client tier

#### Scenario: A rate change mid-month prices each hour by its own date

- GIVEN Alice's user rate is EUR 130 until 15 October 2026 and EUR 135 from 16 October
- WHEN her hours of 15 and 16 October are resolved
- THEN the first is rated EUR 130 and the second EUR 135

### Requirement: An hour without a rate says why and is never billed at a default (REQ-BRPH-002)

The system MUST NOT price an hour at a fallback amount. When no rate resolves,
the answer SHALL be "no rate" with the reason: no assignment, no rate card on the
assignment, no version effective on the date, or no matching schedule. Drafting an
invoice with such an hour ticked SHALL be refused with HTTP 422 naming the person
and the date, and no invoice SHALL be written.

#### Scenario: A person without an assignment has no rate

- GIVEN Dana logged 1 hour on Renovatie Kade 12 and has no assignment to it
- WHEN the hour is resolved
- THEN the answer is no rate with reason "Dana is not assigned to this project"

#### Scenario: Drafting an invoice with an unrated hour is refused

- GIVEN Dana's hour is ticked on the Generate invoice page together with Alice's hours
- WHEN the project manager chooses Save as draft
- THEN the request is refused with HTTP 422 and the message names Dana and 14 October 2026
- AND no BillableInvoice is created

### Requirement: The project page shows rated hours and the unbilled value (REQ-BRPH-003)

`ProjectDetail` SHALL show a Billable hours card listing the project's hours in a
chosen month with person, date, hours, rate, tier, value and billed state, and
SHALL total the unbilled hours, the unbilled value and the hours without a rate.
When no hours source is connected the card MUST say so instead of showing zero.

#### Scenario: A project manager sees what October is worth

- GIVEN the October hours of Alice, Bram, Chris and Dana above and none of them invoiced
- WHEN the project manager opens Renovatie Kade 12 and the Billable hours card for October 2026
- THEN the card lists four rows and totals 12.5 rated unbilled hours worth EUR 1,510 and 1 hour without a rate

#### Scenario: Billed hours show their invoice

- GIVEN Alice's hours sit on posted invoice 2026-T-0031
- WHEN the project manager opens the card
- THEN Alice's row reads "Billed on 2026-T-0031" and her value is not in the unbilled total

#### Scenario: Without an hours source the card says so

- GIVEN no hours source is connected on the instance
- WHEN the project manager opens the project
- THEN the card says hours are not available because the hours app is not connected

### Requirement: An assignment carries a billing role and shows its current rate (REQ-BRPH-004)

`ProjectAssignment` SHALL carry an optional `billingRoleId` matched against the
role tier, and its `recognisedRate` SHALL show the rate the assignment resolves
to today. An invoice line MUST take its rate from the hour's own resolution,
never from `recognisedRate`.

#### Scenario: Setting a role updates the shown rate

- GIVEN Chris's assignment has no billing role and shows EUR 95
- WHEN the project manager sets billing role senior on it
- THEN the assignment shows EUR 120 in the Hours per assignment list

### Requirement: The Generate invoice page prices each hour by its own rate (REQ-BRPH-005)

The Generate invoice page SHALL use each hour's resolved rate for time and
material lines and MUST NOT ask for one rate card for the whole invoice when the
billing model is time and material. Each written line SHALL keep the rate in
`rateApplied` so a later rate change does not alter it.

#### Scenario: Two people on one invoice keep their own rates

- GIVEN Alice's 6 hours at EUR 130 and Bram's 4.5 hours at EUR 120 are ticked
- WHEN the invoice is saved as draft
- THEN its lines total EUR 1,320 and carry rateApplied 130 and 120

#### Scenario: A later rate change leaves a drafted line alone

- GIVEN the draft above exists
- WHEN Alice's user rate changes to EUR 140 from 1 October 2026
- THEN the draft line still reads EUR 130 until the line is removed and the hours re-ticked
