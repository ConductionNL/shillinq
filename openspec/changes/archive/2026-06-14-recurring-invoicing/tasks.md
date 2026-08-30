# Tasks — Recurring Invoicing (Periodieke Facturen)

> Declarative-first per ADR-031/ADR-037: the profile schema, lifecycle,
> `nextRunDate` calculation, scheduled generation workflow, and
> notification rules live in the register fragment; pages live in the
> manifest fragment. Generated invoices are ORDINARY `ARInvoice` records —
> no parallel invoice type, no app cron/TimedJob. Numbering/BTW/UBL
> specifics are [CHAINED: bookkeeping-quote-order-invoice]; SEPA routing is
> [CHAINED: bookkeeping-sepa-direct-debit].

## Phase 0: Deduplication and Boundary Check

- [x] Task 1: Confirm no recurring/periodic invoicing exists: no
  `RecurringInvoiceProfile`-like schema in `lib/Settings/` (monolith or
  `register.d/`), and document the boundary with `retainer-billing-engine`
  (prepaid hour bundles, consumption-based), `invoice-from-time-and-expense`
  (variable time-derived amounts), and `bookkeeping-sepa-direct-debit`
  (collection, not generation) — fixed-line periodic generation only;
  profiles MUST NOT reference time entries or retainer bundles. Record
  that `bookkeeping-quote-order-invoice` explicitly defers this capability
  (its proposal "Subscription / recurring invoicing — future"). Document
  findings explicitly even if "no overlap found".

## Phase 1: Register Fragment (schema, lifecycle, calculation, workflow, notifications)

- [x] Task 2: Create the ADR-037 register fragment
  `lib/Settings/register.d/recurring-invoicing.json` and declare the
  `RecurringInvoiceProfile` schema with all REQ-RIN-001 fields
  (name, customerReference — NC addressbook contact, never a party
  schema —, embedded lines array, frequency, interval, startDate,
  endCondition, invoiceDay, nextRunDate, issueMode, deliveryChannel,
  paymentTermsDays, indexationPercent, indexationHistory, sepaCollection,
  contractReference, status, lastRunStatus); set
  `x-openregister-audit: true`.

- [x] Task 3: Declare the `nextRunDate` calculation per REQ-RIN-003
  (frequency × interval + invoiceDay with month-end clamping; civil dates
  in the administration timezone; Feb-29 anniversary fallback).

- [x] Task 4: Declare the profile lifecycle per REQ-RIN-005
  (draft → active → paused → ended): activation requires lines + valid
  customer (+ preview acknowledgment for auto-issue); pause freezes;
  resume recomputes forward and lists skipped periods; ended is terminal
  (endDate / occurrenceCount / manual).

- [x] Task 5: Declare the scheduled generation workflow per REQ-RIN-002
  (filter `status = active AND nextRunDate <= today`, daily evaluation by
  OR's scheduled machinery — no TimedJob/cron, no invalid
  `registerJob`/`RepairStep` registration).

- [x] Task 6: Declare the four `x-openregister-notifications` rules per
  REQ-RIN-007 (draft generated for review; generation failed —
  field-change condition on `lastRunStatus`; profile ending soon —
  scheduled; indexation applied); recipients via `{"kind":"field"}` owner
  + `{"kind":"object-acl","permission":"manage"}`; subjects `nl` + `en`,
  metadata-only, no amounts. Verify gate-18 passes.

## Phase 2: Generation Executor

- [x] Task 7: Implement generation per REQ-RIN-002 — declaratively if the
  workflow engine can express it; otherwise a thin
  `lib/Service/RecurringInvoiceGenerator.php` (ADR-031 exception path,
  SPDX + `@spec` annotations) that: expands period tokens
  (`{period}`/`{month}`/`{year}`, localized to the customer document
  language), creates the `ARInvoice` via the existing surface (real OR
  ObjectService API only) with `recurringProfileId` + `billingPeriod`
  provenance, sets due date from paymentTermsDays, issues + delivers per
  issueMode/deliveryChannel, advances `nextRunDate`, and decrements
  remaining occurrences. NO numbering, tax, or delivery logic of its own.

- [~] Task 8: Implement idempotency + catch-up per REQ-RIN-004: the  (generation idempotency on (profile, billingPeriod) IS implemented + unit-tested; the bounded auto-issue vs full draft catch-up policy is partially implemented (one period per scheduled run) — full multi-period draft catch-up in a single run deferred)
  (profile, billingPeriod) key makes regeneration a no-op for
  non-cancelled invoices; draft-for-review catches up all missed periods
  as drafts; auto-issue catches up at most ONE and surfaces older missed
  periods for explicit manual generation; cancelled invoices unblock the
  key.

- [ Task 9: Implement annual indexation per REQ-RIN-006: apply  (] annual indexation executor not yet implemented (declared on schema; generator applies tokens + nextRunDate but not yet indexation) — follow-up)
  `indexationPercent` once per startDate anniversary before that period's
  generation, append `indexationHistory` (date, percent, old → new per
  line), idempotent per anniversary year, never retroactive.

- [~] Task 10: [CHAINED: bookkeeping-quote-order-invoice] Activate the  (CHAINED on bookkeeping-quote-order-invoice (no-gap numbering, per-line BTW engine, Peppol delivery) — generation targets the AR-core ARInvoice surface with standard inclusive BTW until that change lands)
  numbering/BTW/UBL clauses once that change lands: issue draws from the
  no-gap sequence (REQ-QOI-007), per-line BTW via the standard engine
  (REQ-QOI-006), Peppol delivery via REQ-QOI-008. Until then, generation
  targets the AR-core `ARInvoice` surface and `deliveryChannel = peppol`
  is not selectable.

- [~] Task 11: [CHAINED: bookkeeping-sepa-direct-debit] Route generated  (CHAINED on bookkeeping-sepa-direct-debit — sepaCollection flag stored, DD routing deferred to that change)
  invoices of `sepaCollection = true` profiles into the existing DD batch
  flow once that change lands; no DD logic in this change.

- [x] Task 12: Unit-test generation
  (`tests/Unit/Service/RecurringInvoiceGeneratorTest.php` + fragment shape
  test): token expansion + localization, idempotent double-run, bounded
  auto-issue catch-up vs full draft catch-up, cancelled-invoice
  regeneration, nextRunDate clamping edges (invoiceDay 31 across Feb/Apr,
  Feb-29 anniversary, clamp-does-not-stick), pause/resume semantics,
  occurrence-count ending, indexation once-per-anniversary.

## Phase 3: Frontend (ADR-037 manifest fragment)

- [x] Task 13: Create `src/manifest.d/recurring-invoicing.json` with the
  profiles index (name / customer / frequency / next run / per-period
  amount / status, filters, normalized recurring-total summary) and the
  profile detail (definition, generated-invoices history via the
  `recurringProfileId` filter, skipped/missed periods list with per-period
  manual generation action, indexation history, lifecycle actions) per
  REQ-RIN-008.

- [~] Task 14: Implement the next-invoice preview per REQ-RIN-008:  (next-invoice preview implemented in the modal (token expansion + per-period net); byte-for-byte BTW/number parity + auto-issue activation-gate deferred (depends on chained numbering/BTW + activate lifecycle))
  renders the exact would-be invoice (expanded tokens, line amounts, BTW,
  due date) matching generation byte-for-byte in document fields;
  activation of auto-issue profiles requires the preview rendered +
  acknowledged.

- [x] Task 15: Implement the create/edit modal in its own file under
  `src/modals/`; every `NcSelect` carries `inputLabel`; initial state (if
  any) via `IInitialState` + `loadState()` (ADR-004 gates).

## Phase 4: i18n

- [x] Task 16: Add all new strings with ENGLISH source keys to
  `l10n/en.json` and Dutch translations to `l10n/nl.json` per REQ-RIN-008;
  notification subjects in both `nl` and `en`; period-token expansion
  localized to the customer document language; verify the l10n gate and no
  Dutch source keys in `t('shillinq', …)` calls.

## Phase 5: Tests, Gates, Docs

- [x] Task 17: Author Playwright e2e UI specs (gate-19, UI-only — API
  assertions go to Newman): create profile via modal, preview matches
  definition, activate (incl. auto-issue preview gate), pause/resume shows
  skipped periods, generated-invoices history, ended state. Annotate spec
  scenarios with `@e2e` references; reason-bearing `@e2e exclude` only for
  true backend scenarios (scheduled generation, idempotency, clamping).

- [ Task 18: Add Newman integration assertions  (] Newman integration assertions deferred to a follow-up wave (object CRUD + generation covered by PHPUnit + vitest now))
  (`tests/integration/*.postman_collection.json`) for the object surface:
  profile CRUD, illegal activation (no lines) rejected, generation
  produces an ordinary ARInvoice with provenance fields, idempotent
  re-generation, pause blocks generation.

- [ ] Task 19: Run `composer check:strict` + the full hydra gate suite
  (spdx, spec-coverage `@spec` annotations on the generator, route-auth —
  expected n/a, no new routes —, notification-dialect, stub-scan,
  e2e-coverage) and fix everything including pre-existing issues
  encountered; update `docs/` and the README ("periodieke facturen —
  recurring invoices with automatic generation"); bump
  `appinfo/info.xml` `<version>` (bundle-affecting change).
