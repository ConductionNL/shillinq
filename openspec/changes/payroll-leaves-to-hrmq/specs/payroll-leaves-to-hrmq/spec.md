# Spec: payroll-leaves-to-hrmq (delta)

## ADDED Requirements

### Requirement: REQ-PLH-001 — The gross-to-net payroll engine MUST be removed from shillinq

The `Werkgever`, `Werknemer`, `LoonheffingTabel2026`, `LoonPeriode`,
`LoonStrook`, `LHAfdracht`, and `Loonjournaalpost` schemas, their manifest
pages (`Werkgevers`/`WerkgeverDetail`, `Werknemers`/`WerknemerDetail`,
`Loonperiodes`/`LoonperiodeDetail`, `Loonstroken`/`LoonstrookDetail`,
`LHAfdrachten`/`LHAfdrachtDetail`, `Loonjournaalposten`/
`LoonjournaalpostDetail`), and their supporting PHP classes
(`PayrollCalculator`, `PayrollService`, `PayrollController`, `PayrollGuard`,
`BsnValidator`) and the three read-only compute routes
(`payroll#loonstrook`, `payroll#lhAfdracht`, `payroll#journaalpost`) MUST be
deleted, per the company decision that hrmq owns payslips/payroll and
`design.md` §1's verified retire table.

#### Scenario: The Payroll nav group and its routes are gone

- **GIVEN** the manifest after this change
- **WHEN** the effective manifest's `section: "main"` top-level entries are
  inspected
- **THEN** no `Payroll`/`Loonadministratie` entry exists, and no
  `/loonadministratie/**` route resolves to a page

@e2e payroll-leaves-to-hrmq::payroll-nav-group-gone

#### Scenario: The retired schemas are no longer declared

- **GIVEN** `lib/Settings/register.d/` after this change
- **WHEN** the merged effective register is inspected
- **THEN** `Werkgever`, `Werknemer`, `LoonheffingTabel2026`, `LoonPeriode`,
  `LoonStrook`, `LHAfdracht`, and `Loonjournaalpost` are not declared

@e2e exclude backend register-declaration diff, no browser-visible behaviour — verified by inspecting the merged effective register

### Requirement: REQ-PLH-002 — The detachering-quartet duplicate schemas MUST be removed

`Employee`, `Payroll`, `Deduction`, and `DeterminationLetter` (declared in
`lib/Settings/register.d/bookkeeping-detachering-payroll-administratie.json`,
none carrying a manifest page) MUST be deleted, together with their
supporting PHP handoff services (`PayrollApArHandoffService`,
`PayrollWkrHandoffService`, `PayrollUpaHandoffService`,
`PayrollLivLkvHandoffService`, `PayrollJaaropgaveService`,
`PayrollSbrConversionService`) and `lib/Standards/rules/payroll-tax.json`.
Per `design.md` §5, this MUST NOT proceed against a real deployment until a
live OR-API row count (never a migration script's own log) confirms the
deployment carries only seed-created rows, or a migration into hrmq's
`Employee`/`EmploymentContract`/`PayrollRun`/`Payslip` registers has run.

#### Scenario: The detachering-quartet schemas are gone from the effective register

- **GIVEN** `lib/Settings/register.d/` after this change
- **WHEN** the merged effective register is inspected
- **THEN** `Employee`, `Payroll`, `Deduction`, and `DeterminationLetter` are
  not declared in the `shillinq` register

@e2e exclude no-page schema retirement, no browser-visible behaviour — verified by register-declaration diff

### Requirement: REQ-PLH-003 — `PayrollChartOfAccountsMapping` MUST survive unchanged

`lib/Service/PayrollChartOfAccountsMapping.php` MUST NOT be deleted or
modified by this change. It is stateless and carries no dependency on any
retiring schema (`design.md` §1a); the "bookkeeping-chart-of-accounts app
integration" it documents as its consumer, and hrmq's incoming
`JournalEntry` line `accountNumber` values, both continue to rely on it.

#### Scenario: The chart-of-accounts mapping class is untouched

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected for changes to
  `lib/Service/PayrollChartOfAccountsMapping.php`
- **THEN** no changes are present

@e2e exclude negative/scope-boundary requirement — verified by diff inspection, no new browser behaviour to assert

### Requirement: REQ-PLH-004 — The existing JournalEntry/PaymentRun lifecycle MUST remain the sole hrmq receiving path

Shillinq MUST NOT add a bespoke hrmq-ingestion listener, controller, or
service for consuming hrmq's pushed `JournalEntry`/`PaymentRun` drafts. Per
`hrmq/openspec/specs/payroll-glpost-shillinq/spec.md` REQ-PGP-003 and
`.../payroll-sepa-netpay-shillinq/spec.md` REQ-PNP-004, hrmq writes these as
drafts via `OCA\OpenRegister\Service\ObjectService`, in-process, and never
drives a shillinq lifecycle transition — the existing generic post/approve
lifecycle already exercised by other internal producers MUST be sufficient
to receive and process them. Any genuinely new absence-handling code this
change's implementer finds necessary MUST follow the fail-soft pattern
already established by `lib/Service/HrmqCostRateAdapter.php` (string-only
FQCN, `class_exists()` guard, empty result rather than an exception when
hrmq is absent — never a hard dependency in either direction), per ADR-081
Decision 7's degrade-gracefully rule: *"A source app MUST degrade gracefully
when the receiver is absent — an unsent allocation is a visible pending
state, never a silent drop."*

#### Scenario: A draft JournalEntry created the way hrmq creates it posts through the existing lifecycle unchanged

- **GIVEN** a draft `JournalEntry` object created via `ObjectService`,
  matching the exact field shape and call pattern
  `payroll-glpost-shillinq` REQ-PGP-002 declares hrmq uses
- **WHEN** the object is posted through shillinq's existing JournalEntry
  lifecycle (the same path any operator-authored draft uses)
- **THEN** it materialises a balanced GLTransaction exactly as any other
  draft JournalEntry would, with no hrmq-specific code path involved

@e2e exclude backend-only lifecycle proof, no new browser-visible surface — verified by a PHPUnit test exercising ObjectService directly with hrmq's exact push shape

#### Scenario: No new hrmq-ingestion listener is added

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected for a new listener, controller, or service class
  whose name or docblock references hrmq's push mechanism
- **THEN** none is found — the diff contains deletions and the DBA re-home
  only, no new receiving-path code

@e2e exclude negative/scope-boundary requirement — verified by diff inspection

### Requirement: REQ-PLH-005 — `SalarisFeed`'s retirement MUST NOT ship without resolving its hrmq capability gap

`SalarisFeed`/`SalarisFeedDetail` (schema + page pair) MUST NOT be deleted
until one of the two resolutions in `design.md` §4 is chosen: a documented
product decision that shillinq's 3rd-party-salarisbureau detachering-payroll
use case is out of scope (dropped, not migrated, per the ADR-081
`case.kosten` precedent), or a confirmed hrmq capability that can receive
the equivalent data. Until resolved, this requirement blocks only
`SalarisFeed`'s own deletion — it MUST NOT block REQ-PLH-001/002's
retirement of the rest of the engine, which have confirmed hrmq mappings
(`design.md` §2).

#### Scenario: SalarisFeed is not deleted while the gap is unresolved

- **GIVEN** neither resolution in `design.md` §4 has been recorded
- **WHEN** this change's implementation is reviewed
- **THEN** `SalarisFeed`/`SalarisFeedDetail` and their schema remain
  present, even though every other retiring page/schema has been removed

@e2e exclude blocking pre-work gate, not a runtime behaviour — verified by the tasks.md task-0 checklist and this document's own decision record

### Requirement: REQ-PLH-006 — `OpdrachtgeversVerklaring`/`IB47Record` MUST relocate to DBA Compliance with no route change

`OpdrachtgeversVerklaringen`/`OpdrachtgeversVerklaringDetail` and
`IB47Jaarbatch`/`IB47RecordDetail` MUST move from the generic `Bookkeeping`
top-level menu group into the existing `DBACompliance` top-level group
(`design.md` §3), keeping their existing routes and page ids unchanged (no
route rename in the same change as a menu-placement change, per this repo's
own churn-avoidance discipline).

#### Scenario: The DBA-adjacent pages are reachable from DBA Compliance

- **GIVEN** an authenticated user viewing the main navigation after this
  change
- **WHEN** they open the `DBACompliance` top-level group
- **THEN** `OpdrachtgeversVerklaringen` and `IB47Jaarbatch` are listed as
  children, and their existing routes still resolve to the same pages as
  before this change

@e2e payroll-leaves-to-hrmq::dba-pages-reachable-at-new-home

#### Scenario: The pages are no longer reachable from the generic Bookkeeping group

- **GIVEN** the main navigation after this change
- **WHEN** the `Bookkeeping` top-level group's children are inspected
- **THEN** `OpdrachtgeversVerklaringen` and `IB47Jaarbatch` are no longer
  listed there

@e2e payroll-leaves-to-hrmq::dba-pages-reachable-at-new-home

### Requirement: REQ-PLH-007 — `npm run check:nav-reachability` MUST stay green

Every page route removed by this change MUST take its menu entry with it in
the same edit (no orphaned route, no dangling menu entry), and every page
that survives (re-homed or unchanged) MUST remain reachable. The existing
`check:nav-reachability` gate (already on `development`,
`tests/validate-nav-reachability.js`) MUST pass against this change's final
manifest state without adding new baseline exceptions for pages this change
itself deletes or moves.

#### Scenario: The reachability gate passes with no new baseline exceptions attributable to this change

- **GIVEN** the manifest after this change
- **WHEN** `npm run check:nav-reachability` runs
- **THEN** it exits 0, and `tests/nav-reachability-baseline.json` gains no
  new entry whose reason references this change's retired or relocated
  pages

@e2e exclude CI gate assertion, not a browser scenario — verified by `npm run check:nav-reachability` in CI

### Requirement: REQ-PLH-008 — `PayrollWebhookController` MUST be removed, and openconnector transport re-home is explicitly out of scope

Both `payrollWebhook#info` and `payrollWebhook#receive` routes and their
controller MUST be deleted (`design.md` §1c — the receiver has no consumer
once the engine is gone). Re-establishing salarisbureau webhook ingestion
through openconnector — for hrmq or any other target — is explicitly NOT
this change's scope and is NOT `integration-config-to-openconnector`'s scope
extension either (confirmed against that change's own proposal.md, whose
`ADAPTERS` registry does not cover payroll bureaus); it requires a new,
not-yet-scoped change, contingent on hrmq's payroll engine growing a
webhook-ingestion mode it does not have today.

#### Scenario: The payroll webhook routes are gone

- **GIVEN** `appinfo/routes.php` after this change
- **WHEN** it is inspected
- **THEN** no `payrollWebhook#info` or `payrollWebhook#receive` entry exists,
  and `lib/Controller/PayrollWebhookController.php` no longer exists

@e2e exclude route/controller deletion, no browser behaviour — verified by routes.php + controller-file diff

### Requirement: REQ-PLH-009 — Cross-repo tasks MUST be handed back, not silently implemented or silently dropped

The five cross-repo items in `design.md` §8 (hrmq detachering capability
decision, hrmq-side migration if chosen, live-count re-verification on every
real deployment, the `Werkgevers` mapping-gap confirmation, and the optional
`dba-compliance-marker` spec update) MUST be recorded as explicit follow-up
items handed to the orchestrator, and MUST NOT be implemented as part of
this change's own tasks.md, nor closed by assumption.

#### Scenario: Cross-repo items are named, not implemented

- **GIVEN** this change's tasks.md
- **WHEN** it is inspected
- **THEN** each of the five items in `design.md` §8 appears as a named,
  explicitly cross-repo-flagged entry, and none of them has a corresponding
  implementation task inside this change

@e2e exclude process requirement, no runtime behaviour — verified by tasks.md structure

### Requirement: REQ-PLH-010 — Non-goals

This change MUST NOT modify hrmq's repository in any way, MUST NOT modify
`ExpenseSettlementClassifier`/`ReimbursementPolicy`/`PassThroughMarkupRule`
beyond the single relocation-target edit required by `Payroll`'s removal
(`design.md` §9), MUST NOT rename any surviving route, and MUST NOT
implement openconnector transport for salarisbureau webhooks (REQ-PLH-008).
Sequencing with `nav-six-clusters` (`design.md` §9) is a coordination note,
not an implementation dependency this change's tasks.md is blocked on.

#### Scenario: No hrmq-repository file appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no file under the hrmq repository appears in it

@e2e exclude negative/scope-boundary requirement — verified by diff inspection
