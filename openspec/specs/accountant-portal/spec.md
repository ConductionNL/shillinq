# accountant-portal Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- accountant-portal (2026-07-13, archived)

## Purpose

Gives an accountant (or bookkeeper) with a Nextcloud account and
`AdministrationMembership` grants across several client administraties an
in-app surface to work across all of them: a scoped multi-client dashboard
and a one-click handover pack, both built entirely on the existing
`AdministrationContextService` RBAC (ADR-022 OpenRegister scoping) — no new
authorization model is introduced. This is distinct from the archived
`portal-contribution` change's `accountant` audience, which serves an
*external*, no-Nextcloud-account bookkeeper through portaliq; see this
change's design.md D1 for why the two do not overlap.

## Requirements

### Requirement: REQ-ACP-001 The accountant dashboard MUST list exactly the administrations the authenticated user has a valid membership for

The system SHALL list, on the accountant dashboard, every administration for
which the authenticated user has a currently-valid `AdministrationMembership`
record (as resolved by `AdministrationContextService::buildContext()`), and
no others. An anonymous request MUST be rejected with 401.

#### Scenario: Dashboard lists granted client administrations

- **GIVEN** an accountant with valid memberships for client administrations
  `WERK-001` (role `boekhouder`) and `BEHEER-001` (role `inkijker`), and no
  membership for `SECRET-999`
- **WHEN** the accountant opens the accountant dashboard
- **THEN** the response lists exactly `WERK-001` and `BEHEER-001` with their
  respective roles; `SECRET-999` never appears in the response.

@e2e accountant-portal::dashboard-lists-granted-client-administrations

### Requirement: REQ-ACP-002 Each client card MUST show period-close state, BTW filing status + deadline, missing-document count and open/attention items

The system SHALL compose, for each listed administration, a status card with:
the most recent `FiscalPeriod`'s close state; the most recent `VATReturn`'s
`statusCode` plus a statutory one-month filing deadline derived from its
period end date when not yet `filed`; a count of `SupplierInvoice` rows with
no recorded source document; and the open-items / needs-attention flags
produced by `PeriodCloseAssistantService::analyse()`. A signal that cannot be
resolved (no period/return on file, or a query failure) MUST degrade to
null/zero for that signal alone, without failing the rest of the card.

#### Scenario: A client mid-close with an unfiled BTW return surfaces both signals

- **GIVEN** client `WERK-001` has a `FiscalPeriod` in state `closing` and a
  `VATReturn` with `statusCode: draft` whose period ended 40 days ago
- **WHEN** the accountant dashboard is built for this client
- **THEN** the card shows `periodClose.state = "closing"` and
  `vatFiling.statusCode = "draft"` with `vatFiling.overdue = true` (the
  one-month statutory term has passed).

#### Scenario: A client with no fiscal period recorded yet degrades gracefully

- **GIVEN** client `NIEUW-001` has no `FiscalPeriod` records
- **WHEN** the accountant dashboard is built for this client
- **THEN** `periodClose` is `null` and `openItemsCount` is `0` — the card
  renders without error.

@e2e exclude pure backend signal-composition + degradation logic — proven by
`AccountantDashboardServiceTest`; not meaningfully browser-testable without
seeding period/return fixtures per test run.

### Requirement: REQ-ACP-003 A user with no membership for an administration MUST receive a masked 404 on every accountant-portal endpoint

The system SHALL treat a request scoped to an administration the
authenticated user has no valid `AdministrationMembership` for as if the
administration does not exist — a 404 response, never a 403 — on both the
dashboard's implicit scoping and the handover-pack export. This is the
security headline of this change: an accountant granted only client A's
administration MUST NOT be able to discover or reach client B's data.

#### Scenario: A non-granted administration is masked as 404 on the handover pack

- **GIVEN** an accountant with a valid membership for `WERK-001` only
- **WHEN** they request the handover pack for `BEHEER-001` (an
  administration that exists but they have no membership for)
- **THEN** the response is `404 Not Found` with a generic "not found"
  message — not `403 Forbidden`, and the response never confirms whether
  `BEHEER-001` exists.

#### Scenario: An anonymous request is rejected before any data lookup

- **GIVEN** no authenticated session
- **WHEN** the dashboard or handover-pack endpoint is requested
- **THEN** the response is `401 Unauthorized` and no `AdministrationMembership`
  lookup is performed.

@e2e exclude tenant-isolation across two accounts is a same-origin,
multi-session security proof, not a rendering concern — proven by
`AccountantPortalControllerTest::testHandoverPackMaskedForNonMember()` /
`testHandoverPackAnonymousRejected()` / `testDashboardRequiresAuthentication()`
(PHPUnit), mirroring `AdministrationExportControllerTest`'s existing
masked-404 proof for the sibling XAF export endpoint. The dashboard's own
scoping has no `{id}` to mask — its isolation guarantee is that a
non-granted administration never appears in the list at all, proven by
`AccountantDashboardServiceTest`.

### Requirement: REQ-ACP-004 The handover pack MUST bundle the journal export, BTW-overzicht, trial balance and XAF auditfile via the existing report generators

The system SHALL, on request for one administration's handover pack, render
the `general-ledger` (journal export), `vat-return` (BTW-overzicht),
`trial-balance` and `xaf` reports through the existing
`ReportGenerationService` / report-generator classes and stream them as a
single ZIP archive. No new report renderer SHALL be introduced for this
capability. A single report type failing to render MUST NOT prevent the
other reports in the pack from being delivered; the pack MUST fail only when
zero reports could be rendered.

#### Scenario: A granted administration's handover pack streams a ZIP with all four reports

- **GIVEN** an accountant with a valid membership for `WERK-001`
- **WHEN** they request the handover pack for `WERK-001`
- **THEN** the response is a ZIP archive containing an XAF entry, a trial
  balance entry, a general-ledger entry and a vat-return entry, each rendered
  by its existing generator.

#### Scenario: A missing BTW return for the period does not block the rest of the pack

- **GIVEN** client `NIEUW-001` has no `VATReturn` on file for the requested
  period
- **WHEN** the handover pack is requested for `NIEUW-001`
- **THEN** the response still succeeds with a ZIP containing the XAF, trial
  balance and general-ledger entries; the vat-return entry is simply absent.

@e2e exclude pure backend generator-orchestration + ZIP-bundling logic,
mirroring `AdministrationExportControllerTest`'s existing (non-e2e) proof for
the sibling XAF+attachments ZIP — proven by
`AccountantPortalControllerTest::testHandoverPackStreamsZipWithAllReports()`
/ `testHandoverPackSkipsFailedReport()` (PHPUnit).
