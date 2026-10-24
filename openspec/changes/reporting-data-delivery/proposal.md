---
kind: code
depends_on: [reporting-segment-results]
---

# Proposal: reporting-data-delivery

## Summary

Two tender asks share one theme: financial data that leaves shillinq on its
own. A controller schedules a report to be produced and delivered every
month without anyone pressing Generate, and the organisation's data team
feeds the ledger into its warehouse or Power BI. Shillinq generates reports
only on demand and offers only the generic object API. This change adds
report schedules with delivery, and declares a read-only financial feed that
integriq serves to outside BI tools.

## Motivation

Two reporting rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), both with tender
demand, decided `build` by the OpenSpec pass of 2026-09-27.

**`rep-bi-feed`**, "Feed the financial data to an outside data warehouse or
BI tool such as Power BI." Rated partial, built: "OpenRegister's REST object
API exposes every shillinq schema to an outside tool that pulls it (see
plt-api), and index pages export CSV or Excel; there is no push feed, OData
endpoint or BI connector." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. All five
competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Power BI Connector: Krijg direct toegang tot al je data".
- moneybird: https://www.moneybird.nl/changelog/nieuwe-rapportage-api/ (22 oktober 2025), "Met de nieuwe Rapportage API haal je rapporten ... automatisch op".
- snelstart: https://www.snelstart.nl/koppelingen, "Power BI Connector ... gegevens inlezen in Power BI vanuit je online administraties".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/twinfield-analy-3041240, "Twinfield Analysis werkt met behulp van gegevensverzamelingen".
- odoo: odoo/odoo@19.0 `addons/rpc/controllers/json2.py:50`, the JSON-2 API giving read access to journal items for a BI tool.

**`rep-scheduled`**, "Schedule a report to run and be delivered on its
own." Rated no, built state none: "Reports are generated on demand from the
report library (lib/Reporting/ReportCatalogue.php); no job or setting
schedules a report." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. moneybird
(https://www.moneybird.nl/changelog/frequentie-van-de-dagelijkse-update-is-instelbaar/)
and odoo (`addons/digest/models/digest.py`, periodic KPI digests) are
partial.

## Affected Projects

- [ ] Project: `shillinq`: report schedules with delivery, and the declaration of a financial feed.
- [ ] Project: `integriq`: serves the declared feed endpoints with their credentials (ADR-091); no change specified here.

## Scope

### In Scope

- `ReportSchedule`: a catalogue report, format, administration, frequency, run day, recipients (users or groups) and a destination folder.
- A background job producing due reports through `ReportGenerationService`, filing them in the destination folder and notifying the recipients with a link.
- Four feed datasets declared for integriq: posted ledger lines (with account, period, dimensions and signed amount), accounts, periods and relations, read-only and scoped to one administration per credential.

### Out of Scope

- Serving the feed and checking its credentials: integriq's (ADR-091).
- Emailing report files as attachments; recipients get a Nextcloud notification and a file link.

## Approach

Schedules are records run by one job; the feed is a declaration integriq
reads. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/`: `ReportSchedule`.
- `lib/BackgroundJob/`: `ScheduledReportJob`.
- `lib/Settings/`: the feed endpoint declaration.
- `src/manifest.d/`: a schedules page and an action on the report dialog.

## Cross-Project Dependencies

- integriq: ADR-091 decision 4 has an app declare the endpoints it needs and integriq own them. The feed's datasets are declared here; integriq's endpoint configuration serving them (paging, API keys, and OData if integriq offers it) is listed for its owner.

## Risks

### Risk 1: A feed exposes more than one administration
**Severity:** High. **Mitigation:** each dataset requires an administration filter bound to the credential; the declaration refuses an unscoped dataset.

### Risk 2: A scheduled report fails silently
**Severity:** Medium. **Mitigation:** a failed run notifies the schedule's owner with the reason and keeps the schedule's `lastError`.

## Rollback Strategy

Pause all schedules and withdraw the feed declaration.

## Open Questions

None.
