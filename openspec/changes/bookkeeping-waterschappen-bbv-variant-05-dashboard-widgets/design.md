# Design — Member 05: dashboard widgets

## Scope

This `kind: code` member composes the BBV Compliance Dashboard from
platform widgets. It authors no aggregation logic (member 02) and no
custom dashboard service (ADR-031) — the widgets read the materialised
aggregation via the route from member 04.

## Decisions carried from the giant

- **D4** — dashboard aggregates GL + programme data in real time;
  platform `CnDashboardPage` + `CnChartWidget` + `CnStatsBlock` +
  `CnDataTable` handle rendering. No custom dashboard service.

## Reuse

| Widget | Platform component | Data source |
|---|---|---|
| KPI cards | `CnStatsBlock` ×4 | aggregation counts (member 02) |
| Status distribution | `CnChartWidget` (pie) | aggregation status buckets |
| YTD trend | `CnChartWidget` (line) | GL cumulative spend per programme |
| Programme table | `CnDataTable` | per-programme aggregation rows |

Status badges: 🟢 on-track (0–75%), 🟡 at-risk (75–90%), 🔴
non-compliant (>90%), ⚪ unconfigured. Row click navigates to the
programme detail page.

## Security (ADR-005)

The dashboard renders read-only aggregation data scoped to the active
administration's fiscal year (full scoping carried in member 09). No
write path; no per-object id is dereferenced beyond what the read route
already authorises.

## i18n note

Hardcoded English strings are acceptable in this member; they are
extracted to translation keys in member 10. Modals (if any) live in
their own files per hydra-gate-modal-isolation.
