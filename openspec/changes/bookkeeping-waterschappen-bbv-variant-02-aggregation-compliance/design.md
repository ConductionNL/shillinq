# Design — Member 02: aggregation + compliance

## Scope

This `kind: config` member declares the compliance aggregation as
`x-openregister-aggregations` metadata on the registers from member 01.
No controller, service, or UI code — those land later.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Total budget per programme | Aggregation (SUM × allocation %) | Pure arithmetic over mappings + GL |
| YTD spend per programme | Aggregation (SUM GL where date ≤ today) | Pure arithmetic |
| Utilization | Aggregation (YTDSpend / TotalBudget) | Ratio, not imperative |
| Compliance status | Aggregation-derived enum bucket | Threshold comparison, declarative |

The compliance status is **computed, not stored** (giant D3). No
imperative service is authored in this member — the imperative
fallback `ComplianceService` (member 08) exists only for paths the
aggregation engine cannot express, and `depends_on`s this member.

## Computation rules carried from the giant (REQ-BBVW-005)

```
TotalBudget(P) = SUM over mappings(P) of GL-budget × (allocation% / 100)
YTDSpend(P)    = SUM GL.amount (date ≤ today) × allocation% per mapping
Utilization(P) = YTDSpend(P) / TotalBudget(P)
ComplianceStatus(P) =
  unconfigured  if no mappings exist
  on-track      if Utilization ≤ 75%
  at-risk       if 75% < Utilization ≤ 90%
  non-compliant if Utilization > 90%
```

## Seed data

No new seed — reuses member 01's programmes, mappings, and the
integration-test GL fixtures. The integration test asserts the
materialised aggregation values for those fixtures.

## Security (ADR-005)

Aggregation reads inherit the registers' public-read posture; no new
write path. Aggregation is scoped to the active administration's fiscal
year (carried fully in member 09).
