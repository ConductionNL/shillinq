---
kind: code
depends_on: [reporting-segment-results]
---

# Proposal: reporting-custom-analysis

## Summary

A director arranges their own dashboard of the figures they watch and
slices the ledger in a pivot table by account, period, cost centre or
customer. Shillinq's dashboards are fixed lists of widgets in the manifest,
and nothing pivots the ledger. The fleet already has a place where users
build their own dashboards: launchpad takes any widget a Nextcloud app
offers. This change makes shillinq offer its financial figures as Nextcloud
dashboard widgets and adds a pivot page over posted ledger lines.

## Motivation

One reporting row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`rep-bi`**, "Build your own dashboards or pivot tables on the financial
data." Rated no, built state none. Matrix evidence: "Dashboards are fixed
manifest widget lists (src/manifest.json Dashboard, *Dashboard pages); grep
'pivot' in src/lib finds only a stock-by-location page description; no
user-editable dashboard or pivot builder component in src/components or
src/views." Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Power BI Connector: Krijg direct toegang tot al je data".
- snelstart: https://www.snelstart.nl/koppelingen, "Met de Power BI Connector voor SnelStart kun je eenvoudig gegevens inlezen in Power BI".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/twinfield-analy-3041240, "Met Twinfield Analysis kan direct vanuit Microsoft Excel contact worden gelegd met de gegevens".
- odoo: odoo/odoo@19.0 `addons/spreadsheet_dashboard` spreadsheet dashboards, and pivot and graph views on journal items.

The feed to an outside BI tool is the sibling row `rep-bi-feed`, specified in
`reporting-data-delivery`.

## Affected Projects

- [ ] Project: `shillinq`: financial dashboard widgets through Nextcloud's dashboard API, and a pivot page.

## Scope

### In Scope

- Five dashboard widgets registered with Nextcloud's dashboard API: revenue this month against last year, open receivables and overdue part, cash position, result by month (bar), top customers by revenue.
- The widgets usable on the Nextcloud dashboard and in launchpad dashboards, scoped to the user's active administration.
- A pivot page over posted ledger lines: rows and columns chosen from account, account group, period, cost centre, project and customer; signed amounts; totals; export to CSV and Excel.

### Out of Scope

- A dashboard builder inside shillinq. Launchpad is the fleet's dashboard builder.
- Formulas and spreadsheets.

## Approach

Widgets reuse the figures `FinancialDashboardService` already computes. The
pivot sums the signed amounts `reporting-segment-results` declares. Details
are in design.md.

## New Dependencies

None.

## Impact

- `lib/Dashboard/`: five widget classes; `lib/AppInfo/Application.php`: their registration.
- `lib/Controller/`: a pivot endpoint; `src/views/`: the pivot page and a Reports card.

## Cross-Project Dependencies

- launchpad: lists any widget a Nextcloud app offers (launchpad matrix row `build-nc-widgets`, yes, built). No launchpad change.

## Risks

### Risk 1: A pivot over a large ledger is slow
**Severity:** Medium. **Mitigation:** the endpoint caps the groups per axis and the period span, uses bounded queries (ADR-058), and says when it capped.

## Rollback Strategy

Unregister the widgets and remove the page.

## Open Questions

None.
