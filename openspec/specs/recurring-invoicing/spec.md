---
status: done
---

# recurring-invoicing Specification

## Purpose
Manages recurring billing definitions as OpenRegister-managed profiles that reference Nextcloud addressbook contacts and generate ordinary AR invoices per period through a scheduled OpenRegister workflow, with no app-local cron or parallel invoice type. The capability covers declarative next-run-date calculation with month-end clamping, idempotent generation with bounded catch-up after downtime, a draft-to-ended profile lifecycle, optional annual price indexation, declarative notifications, and a manifest-driven UI with an exact next-invoice preview.
## Requirements
### Requirement: REQ-RIN-001 — The system SHALL store recurring billing definitions as an OpenRegister-managed `RecurringInvoiceProfile` schema with the customer referencing the Nextcloud addressbook

The `RecurringInvoiceProfile` schema MUST be declared in the ADR-037
register fragment `lib/Settings/register.d/recurring-invoicing.json` (NOT
the monolith) with `x-openregister-audit: true`. CRUD goes through
OpenRegister's generic object surface (ADR-022).

| Property | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Operator-facing profile name |
| `customerReference` | string (contact URI/UID) | Yes | NC addressbook contact; linked `CustomerMaster` resolved per AR conventions — no party schema invented |
| `lines` | array | Yes | Embedded: description (with period tokens), quantity, unitPrice, vatCode, revenueAccount FK, costCenter/dimensions FKs |
| `frequency` | enum | Yes | weekly, monthly, quarterly, semi-annually, annually |
| `interval` | integer | Yes | Multiplier (e.g. frequency=monthly, interval=2 → every 2 months); default 1 |
| `startDate` | date | Yes | First billing period start |
| `endCondition` | object | No | `{ endDate }` or `{ occurrenceCount }`; absent = open-ended |
| `invoiceDay` | integer | Yes | Day of period to invoice (1–31; clamped per REQ-RIN-003) |
| `nextRunDate` | date | Computed | Declared calculation (REQ-RIN-003) |
| `issueMode` | enum | Yes | draft-for-review (default), auto-issue |
| `deliveryChannel` | enum | Yes | email, peppol, none |
| `paymentTermsDays` | integer | Yes | Due-date offset on generated invoices |
| `indexationPercent` | decimal | No | Annual price indexation (REQ-RIN-006) |
| `indexationHistory` | array | No | Applied indexations (date, percent, price deltas) |
| `sepaCollection` | boolean | No | Route generated invoices into the SEPA DD flow [CHAINED] |
| `contractReference` | FK | No | Soft link to a `Contract` (contract-lifecycle-management), where one backs the billing |
| `status` | enum | Yes | Lifecycle state (REQ-RIN-005) |

#### Scenario: Operator creates a monthly hosting profile

- **GIVEN** an operator defines "Hosting Acme" for the Acme B.V. contact with one line "Hosting {month} {year}" at EUR 99.00 (VAT 21%), frequency monthly, invoiceDay 1, draft-for-review
- **WHEN** the profile is saved via OpenRegister's standard object API
- **THEN** it MUST be persisted in `draft` with `nextRunDate` computed, and no invoice MUST exist yet

#### Scenario: Customer is a Nextcloud contact, not an invented schema
@e2e exclude schema-shape assertion verified by the register fragment + validate-registers, not observable UI behaviour

- **GIVEN** the register fragment of this change
- **WHEN** its schema declarations are inspected
- **THEN** only `RecurringInvoiceProfile` MUST be declared — no Customer/Party/Subscription-customer schema — and `customerReference` MUST hold the NC addressbook contact reference

### Requirement: REQ-RIN-002 — Generation SHALL produce an ordinary `ARInvoice` per period through the existing invoicing surfaces, scheduled by OpenRegister — no app-local cron and no parallel invoice type

A scheduled workflow MUST be declared on the profile schema (filter:
`status = active AND nextRunDate <= today`, evaluated at least daily by
OR's scheduled machinery; no TimedJob, no app cron, no invalid
`registerJob` registration). Each run generates, per due profile, one
standard `ARInvoice` carrying provenance fields `recurringProfileId` and
`billingPeriod`, with:

- line descriptions token-expanded (`{period}`, `{month}`, `{year}`,
  localized to the customer's document language),
- due date = issue date + `paymentTermsDays`,
- BTW computed by the standard per-line engine and the invoice number
  drawn from the normal no-gap sequence at issue [CHAINED:
  bookkeeping-quote-order-invoice REQ-QOI-006/007 — until that change
  lands, generation targets the AR-core `ARInvoice` surface and these
  clauses activate with it],
- delivery via the profile's channel (email, or Peppol UBL per REQ-QOI-008
  [CHAINED]),
- downstream behavior identical to a manual invoice: dunning, ageing,
  credit limits, payment links, bank matching — no special-casing anywhere.

Any thin generation executor PHP (only if not fully expressible
declaratively) is ADR-031 exception-path glue composing existing surfaces
via the real OR ObjectService API; it MUST contain no numbering, tax, or
delivery logic of its own.

#### Scenario: Due profile generates one ordinary invoice
@e2e exclude backend generation verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** an active monthly profile with `nextRunDate = today` and one line "Hosting {month} {year}", EUR 99.00, VAT 21%
- **WHEN** the scheduled generation runs
- **THEN** one `ARInvoice` MUST exist with the description expanded for the current period, EUR 20.79 VAT computed by the standard engine, `recurringProfileId` and `billingPeriod` set, and `nextRunDate` MUST have advanced one month

#### Scenario: Generated invoice is downstream-indistinguishable
@e2e exclude downstream dunning/ageing parity is backend behaviour, not a recurring-specific UI flow

- **GIVEN** a generated invoice that goes unpaid past its due date
- **WHEN** the existing dunning cadence evaluates
- **THEN** the invoice MUST enter dunning exactly as a manually created invoice would, with no recurring-specific code path

#### Scenario: Reviewer confirms no app-local scheduler
@e2e exclude static code-scan assertion (no TimedJob/registerJob), enforced by gate review not e2e

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for TimedJob/cron registrations or invalid `IRegistrationContext::registerJob` calls introduced by this change
- **THEN** none MUST exist; scheduling lives in the declared OR workflow

### Requirement: REQ-RIN-003 — `nextRunDate` SHALL be a declared calculation with pinned month-end clamping, computed on civil dates in the administration's timezone

`nextRunDate` MUST be declared via `x-openregister-calculations` from
`frequency`, `interval`, `invoiceDay`, and the last generated
`billingPeriod`:

- `invoiceDay` greater than the target month's length clamps to the last
  day of that month (invoiceDay 31 → Feb 28/29, Apr 30, …).
- Anniversary dates of Feb 29 fall to Feb 28 in non-leap years.
- All date arithmetic uses civil dates in the administration's timezone —
  never UTC timestamps (a DST shift MUST NOT move an invoice day).

#### Scenario: Invoice day 31 clamps in short months
@e2e exclude nextRunDate clamping verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** an active monthly profile with `invoiceDay = 31` whose last generated period is January 2027
- **WHEN** `nextRunDate` is computed
- **THEN** it MUST be 2027-02-28

#### Scenario: Clamped month does not stick
@e2e exclude nextRunDate clamping verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** the same profile after the February 2027 invoice generated on the 28th
- **WHEN** `nextRunDate` is computed for March
- **THEN** it MUST be 2027-03-31 (clamping is per-month, not destructive)

### Requirement: REQ-RIN-004 — Generation SHALL be idempotent per profile and billing period, with bounded, explicit catch-up after downtime

Generation MUST be idempotent on the (profile, billing period) key, and
catch-up after downtime MUST be bounded and explicit.

- The pair (`recurringProfileId`, `billingPeriod`) is the idempotency key:
  if a non-cancelled invoice already exists for it, generation for that
  period MUST be a no-op.
- After missed runs (downtime, job backlog): `draft-for-review` profiles
  MUST generate all missed periods as drafts; `auto-issue` profiles MUST
  auto-generate at most ONE missed period and surface older missed periods
  on the profile detail for explicit per-period manual generation — never
  silently mass-issue.

#### Scenario: Double-fired job generates nothing twice
@e2e exclude idempotency verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** a profile whose July 2026 invoice was generated
- **WHEN** the scheduled workflow fires again for the same period (replay, overlapping run)
- **THEN** no second invoice for `billingPeriod = 2026-07` MUST exist

#### Scenario: Auto-issue catch-up is bounded
@e2e exclude bounded catch-up is scheduled backend behaviour verified at unit/integration level

- **GIVEN** an active auto-issue monthly profile and three missed periods after an outage
- **WHEN** the next scheduled run executes
- **THEN** exactly one missed period MUST be auto-issued, and the two older periods MUST be listed on the profile detail awaiting explicit operator action

#### Scenario: Cancelled invoice allows regeneration
@e2e exclude regeneration unblock verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** a generated draft for `2026-07` that the operator cancelled because of a wrong price
- **WHEN** the operator corrects the profile and triggers generation for that period
- **THEN** a new invoice for `2026-07` MUST be generated (the cancelled one does not block the key)

### Requirement: REQ-RIN-005 — Profiles SHALL follow a declarative lifecycle — draft → active → paused → ended — where resume never silently back-generates

The lifecycle MUST be declared via `x-openregister-lifecycle`:

- `draft → active`: requires at least one line, a valid
  `customerReference`, and (for `auto-issue`) a rendered next-invoice
  preview acknowledgment (REQ-RIN-008).
- `active → paused`: generation stops; `nextRunDate` frozen.
- `paused → active`: `nextRunDate` recomputes forward from today; periods
  skipped while paused are listed for explicit manual generation, never
  auto-generated.
- `active/paused → ended`: on `endDate` passed, `occurrenceCount`
  exhausted (after the final generation), or manual end. `ended` is
  terminal; a new profile is created for re-engagements.

#### Scenario: Pause stops generation
@e2e exclude lifecycle gating is backend scheduled behaviour, asserted via Newman not UI e2e

- **GIVEN** an active profile paused on the 15th with `nextRunDate` on the 1st of next month
- **WHEN** the scheduled workflow evaluates next month
- **THEN** no invoice MUST be generated while the profile is `paused`

#### Scenario: Resume skips the gap explicitly
@e2e exclude resume skip-list is backend scheduled behaviour, asserted via Newman not UI e2e

- **GIVEN** a profile paused for three months and then resumed
- **WHEN** resume executes
- **THEN** `nextRunDate` MUST point at the next upcoming period, and the three skipped periods MUST be listed on the profile detail for optional manual generation — none auto-generated

#### Scenario: Occurrence count ends the profile
@e2e exclude occurrence-count ending verified by RecurringInvoiceGeneratorTest (unit)

- **GIVEN** an active profile with `occurrenceCount = 12` and 11 invoices generated
- **WHEN** the 12th invoice is generated
- **THEN** the profile MUST transition to `ended` and no further `nextRunDate` MUST be scheduled

### Requirement: REQ-RIN-006 — Optional annual indexation SHALL adjust unit prices transparently at each start-date anniversary

When `indexationPercent` is set, the system MUST adjust unit prices once
per start-date anniversary before generation.

When `indexationPercent` is set:

- At each `startDate` anniversary, unit prices on the profile lines MUST
  be increased by the percentage before that period's generation.
- Each application MUST append to `indexationHistory` (date, percent, per
  line old → new price), and an indexation-applied notification MUST fire
  (REQ-RIN-007) before the first indexed invoice is delivered.
- Indexation MUST apply at most once per anniversary year (idempotent),
  and changing `indexationPercent` MUST never retroactively recompute past
  invoices.

#### Scenario: Anniversary applies the indexation once
@e2e exclude indexation is declarative/backend behaviour, deferred executor step, not UI

- **GIVEN** an active profile started 2026-03-01 with `indexationPercent = 3.0` and a line at EUR 100.00
- **WHEN** the March 2027 generation cycle runs
- **THEN** the line MUST be EUR 103.00 with an `indexationHistory` entry recording 3.0% and 100.00 → 103.00, the March invoice MUST use the new price, and a re-run MUST NOT index to 106.09

### Requirement: REQ-RIN-007 — Recurring-billing events SHALL be notified via the x-openregister-notifications dialect — never imperative dispatch

The fragment MUST declare rules per ADR-031 and the
`shillinq-notifications` conventions, subjects in `nl` + `en`,
metadata-only (profile name, period, state — no amounts in subjects):

1. **Draft generated for review** — `created`-trigger on `ARInvoice`
   scoped to `recurringProfileId` present and draft state (or
   `updated`-rule equivalent per engine capabilities); recipient: profile
   owner (`{"kind":"field"}`).
2. **Generation failed** — `updated` trigger with field-change condition
   on the profile's `lastRunStatus = failed`; recipients: profile owner
   plus `{"kind":"object-acl","permission":"manage"}`.
3. **Profile ending soon** — `scheduled` trigger (intervalSec ≥ 86400)
   filtering active profiles whose final period is the next one;
   recipient: profile owner.
4. **Indexation applied** — `updated` trigger on `indexationHistory`
   change; recipient: profile owner.

No app-local dispatch code, listeners, or reminder background jobs
(gate-18).

#### Scenario: Review-mode draft notifies the owner
@e2e exclude notification dispatch is the OR x-openregister-notifications dialect, not app UI

- **GIVEN** a draft-for-review profile owned by alice generates its July draft
- **WHEN** the OR notification engine evaluates the rules
- **THEN** alice MUST receive a notification naming the profile and period, with the subject available in both `nl` and `en`

#### Scenario: No imperative dispatch code exists
@e2e exclude static code-scan assertion enforced by gate-18, not e2e

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for app-local notification dispatch or reminder jobs introduced by this change
- **THEN** none MUST exist; all rules live in the `x-openregister-notifications` declarations (gate-18)

### Requirement: REQ-RIN-008 — The UI SHALL ship as ADR-037 manifest pages with an exact next-invoice preview, and all strings SHALL use ENGLISH source keys

The frontend MUST ship as the ADR-037 manifest fragment
`src/manifest.d/recurring-invoicing.json`:

- **Profiles index** — columns name / customer / frequency / next run /
  amount per period / status; filters on status and frequency; a
  recurring-total summary (sum of active per-period amounts, normalized
  monthly).
- **Profile detail** — definition, **next-invoice preview** rendering the
  exact would-be invoice (expanded tokens, line amounts, BTW, due date),
  generated-invoices history (the `recurringProfileId` filter on
  invoices), skipped/missed periods awaiting manual generation
  (REQ-RIN-004/005), indexation history, lifecycle actions.
- **Create/edit** as a modal in its own file under `src/modals/`;
  activation of an `auto-issue` profile MUST require the preview to have
  been rendered (REQ-RIN-005).

Every `NcSelect` carries `inputLabel` (ADR-004 gates). All new strings use
ENGLISH source keys with Dutch translations in the same change (e.g.
`t('shillinq', 'Next invoice on {date}')` → nl
`'Volgende factuur op {date}'`); notification subjects declared in both
`nl` and `en`.

#### Scenario: Preview shows the exact would-be invoice

- **GIVEN** a draft profile with line "Hosting {month} {year}", EUR 99.00, VAT 21%, invoiceDay 1
- **WHEN** the operator opens the next-invoice preview
- **THEN** it MUST render the expanded description for the next period, EUR 99.00 + EUR 20.79 VAT = EUR 119.79, and the computed due date — matching what generation would produce byte-for-byte in the document fields

#### Scenario: Auto-issue requires a rendered preview
@e2e exclude preview-gated activation depends on the chained activate lifecycle; deferred, not yet wired to e2e

- **GIVEN** a draft profile with `issueMode = auto-issue` whose preview has never been rendered
- **WHEN** activation is attempted
- **THEN** it MUST be rejected until the preview has been rendered and acknowledged

#### Scenario: Dutch UI renders translated strings from English keys

- **GIVEN** a user with locale `nl`
- **WHEN** the profiles index is rendered
- **THEN** labels MUST appear in Dutch, resolved from English source keys present in `l10n/en.json` and `l10n/nl.json`, and no Dutch source keys MUST exist in `t('shillinq', …)` calls

