# Proposal: rate-card-engine

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`RateCardTemplate`, `RateCardVersion`, `RateSchedule`) +
multi-tier rate hierarchy (user / role / project / client / blended)
with effective-dated rates, materialized into invoiceable `RateRecord`
per tier-rank precedence. Consumption via `TimeEntry` billable-rate
lookup or AR/AP invoice line rate resolution. No PHP rate-calculation
service (ADR-031 exception: single guard for rate-precedence logic if OR
aggregation extension is not stable).

## Summary

Introduce the **rate-card-engine** capability for Shillinq as a
cross-tier rate management system enabling multi-tier rate hierarchies
with effective-dated periods. The capability declares `RateCardTemplate`
(reusable rate structure), `RateCardVersion` (effective-dated variant),
and `RateSchedule` (tier-specific rates: user, role, project, client,
blended) registers; provides rate-lookup aggregation (most specific
tier wins) per `x-openregister-aggregations`; and materializes resolved
rates into `RateRecord` for audit trail and billing reconciliation.

This capability supports the original time-tracking + invoicing +
financial-reporting scope: time entries booked at a user level are
billed at the most specific applicable rate (user > role > project >
client > blended default); invoices are materialized with resolved
rates for accuracy and dispute prevention; rate changes are
effective-dated so historical billing remains auditable.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure.

**Depends on:** None (foundational; feeds into AR/AP invoicing and time
tracking T2 capabilities).

## Motivation

Rate-card management is demanded by 21/26 competitors (Anuko, BigTime,
Clio, Clockify, Deltek, Everhour, Harvest, Hubstaff, Kantata, Kimai,
Moneybird, Replicon, Tempo, Timecamp, Timely, Toggl, Yuki). The
common pattern: multi-tier rate hierarchy (per user, per role, per
project, per client, blended default) with effective-dated periods so
rates can be adjusted forward-looking without breaking historical
invoices.

Per ADR-022, rate resolution (lookup by tier precedence) is a
declarative aggregation query, not a `RateResolutionService`. Per
ADR-031, rate materialization for audit trail is a declarative
lifecycle action, not custom code.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`rate-card-management`); declares 3 new registers
  (`RateCardTemplate`, `RateCardVersion`, `RateSchedule`) with
  effective-date lifecycle; adds rate-lookup aggregation; adds 3
  manifest navigation entries (Rate Cards, Rate Schedules, Rate
  Audit Trail).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle` (effective-date windows),
  `x-openregister-aggregations` (tier-precedence lookup).
- [ ] Project: (future) time-tracking, AR invoicing — consumes rate
  resolution via aggregation query.

## Scope

### In Scope

- One new capability spec (`rate-card-management`) — see the
  `specs/` folder.
- The `RateCardTemplate` register with name, description, rate-tier
  structure (user / role / project / client / blended), and audit
  trail for template versioning.
- The `RateCardVersion` register with effective-date and expiry-date
  windows, allowing multiple concurrent rate-card versions per
  administration.
- The `RateSchedule` register representing tier-specific rates
  (fixed hourly/daily rate, volume-discount brackets, currency) with
  effective-date lifecycle per tier and per-entity (userId, roleId,
  projectId, clientId, or blended-default).
- Rate-lookup aggregation: given (user, role, project, client,
  service-type, date), resolve the applicable rate by tier precedence
  (user > role > project > client > blended default).
- `RateRecord` register for audit trail: materializes each
  rate-lookup result with resolved tier, effective period, and
  resolved amount so historical rates remain queryable and
  disputable.
- Manifest navigation: 3 entries (Rate Cards, Active Schedules, Rate
  Audit Trail) with their `type: index` / `type: detail` pages.

### Out of Scope

- No T4 e-invoicing or UBL rate field emission — T4 consumes resolved
  rates additively.
- No multi-currency conversion — rates are stored in a single
  currency per rate card; T5 handles multi-currency reporting.
- No rate-change impact analysis or what-if scenario modeling — T5.
- No real-time rate alerts or anomaly detection — observability T5.

### Dependencies

- **Depends on:** None (foundational).
- **Feeds into:** Time tracking (billable-rate lookup per entry),
  AR invoicing (invoice-line rate resolution), AP invoicing (cost
  rate per vendor tier).

### Constraints

- Rate names, tier names, and entity references (userId, roleId,
  projectId, clientId) MUST be stable across effective-date windows
  so that historical `RateRecord` queries remain consistent.
- Effective-date windows MUST be non-overlapping per (tier, entity)
  pair to ensure deterministic rate-lookup results.
- Rate changes MUST be forward-effective (effective-date ≥ today) so
  historical invoices remain unaffected.

## Risks

1. **Tier-precedence logic stability**: If OR's `x-openregister-aggregations`
   extension is not stable, a single-method `RateResolutionGuard`
   ships as per ADR-031 exception; documented in spec.
2. **Effective-date enforcement**: Operators must understand that
   rate-card versions and schedules are effective-date-governed.
   Gap: operator training / UI guidance. Mitigated by ADR-030
   journeydoc pattern.
3. **Historical rate disputes**: If rate-lookup logic changes (e.g.,
   tier precedence reordering), historical `RateRecord` materialized
   results may not match re-calculated values. Mitigation: immutable
   `RateRecord` register, audit trail, and rate-lock per invoice
   line-item (future T4 addition).

## Rollback

Rate-card-engine is foundational but optional: if rolled back,
downstream time-tracking and AR/AP invoicing fall back to hard-coded
rates or manual per-invoice rate entry. No data loss (registers are
preserved; just not queried).

## Open Questions

1. Should `RateCardTemplate` be shareable across administrations, or
   per-administration only?  → Per-administration for now (OU
   isolation); multi-OU sharing (2026-Q3+) is a future T4 addition.
2. Should rate-lookup allow fuzzy matching (e.g., role precedence if
   user rate not found)? → Yes; specified in REQ-RATE-005 as ordered
   tier fallback.
3. Should rate-change history be queryable as an audit trail
   (e.g., "all rate changes for user-X in Q1 2026")? → Yes;
   `RateRecord` register + aggregation query per REQ-RATE-010.
