# Design: reporting-data-delivery

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **On-demand reports.** `lib/Reporting/ReportCatalogue.php` lists the report types; `ReportGenerationService::generate(reportType, period, administrationId, format)` (`lib/Reporting/ReportGenerationService.php:151`) produces a `GeneratedFile`, reached from `GenerateReportDialog.vue` through `POST /api/reporting/generate`. `listGenerated()` (line 270) lists produced files. Nothing runs it on a schedule.
- **Generic API.** OpenRegister's object API serves every schema to a caller with a Nextcloud session or app password; index pages export CSV and Excel.
- **ADR-091.** An HTTP surface that authenticates its caller by any scheme other than a Nextcloud session belongs to integriq (OpenConnector); the app declares the endpoints it needs and integriq owns them (decision 4).
- **Jobs.** ADR-069: jobs live in `lib/BackgroundJob/` and are registered in `appinfo/info.xml`.
- **Signed lines.** `reporting-segment-results` declares `GLLine.signedAmount`, `accountClass` and `countsInResult`.

## Goals / Non-Goals

**Goals**
- A report produced and delivered on a schedule.
- A read-only financial feed a BI tool can pull with its own credential.

**Non-Goals**
- Serving credentials in shillinq, email attachments.

## Decisions

### D1. Schedules are records, one job runs them

`ReportSchedule`: `administrationId`, `reportType` (a catalogue key),
`format`, `frequency` (`weekly`, `monthly`, `quarterly`), `runDay`,
`periodRule` (`previous-period`, `year-to-date`), `recipients` (user and
group ids), `folderPath`, `nextRunAt`, `lastRunAt`, `lastError`,
lifecycle `active` and `paused`. `ScheduledReportJob` runs hourly, picks
schedules with `nextRunAt` in the past, calls `generate()`, writes the file
into `folderPath` of the schedule owner's storage shared with the
recipients, sends a Nextcloud notification with the link, and sets the next
run. A failure records `lastError` and notifies the owner.

### D2. The feed is a declaration integriq serves

`lib/Settings/feeds.json` declares four datasets over shillinq schemas:
`ledger-lines` (posted `GLLine` with `countsInResult`, joined fields
account number and name, period, cost centre, project, customer,
`signedAmount`), `accounts`, `periods`, `relations`; each with its fields,
a required `administrationId` binding and a page size. Integriq reads the
declaration and exposes the endpoints with its credential handling; shillinq
ships no public controller.

Alternative considered: a shillinq `#[PublicPage]` OData controller with an
API key. Rejected by ADR-091 decision 1.

### D3. Two places to start from

A schedules page under Reports, and "Schedule this report" on the report
dialog prefilled with the chosen report and format.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Schedule states | Declarative: `x-openregister-lifecycle` on `ReportSchedule` | Active and paused. |
| Running schedules | Imperative, a background job (ADR-031 exception: scheduled bulk work) | Timed generation. |
| Feed datasets | Declarative: a declaration file | ADR-091 decision 4. |

## Seed Data

Gemeente Voorbeeld: schedule "Maandrapportage budget versus realisatie",
PDF, monthly on day 5, previous period, recipients group "controllers",
folder "/Rapportages/Maand". A Power BI workspace of the same municipality
pulls `ledger-lines` for its own administration only.

## Risks / Trade-offs

- [Recipients without access to the folder] → the file is shared with the recipients when written, and a recipient without shillinq access gets no link.

## Migration Plan

None.

## Open Questions

None.
