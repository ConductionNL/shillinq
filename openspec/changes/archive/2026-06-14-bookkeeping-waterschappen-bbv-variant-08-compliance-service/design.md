# Design — Member 08: compliance service

## Scope

This `kind: code` member implements the thin imperative orchestration +
caching surface around the declarative aggregation (member 02). The
maths lives in the aggregation; this service only assembles the widget
envelope and caches it.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Where it lives | Why imperative here |
|---|---|---|
| Budget / spend / utilization maths | Aggregation (member 02) | Declarative — not reimplemented here |
| Status bucketing | Aggregation (member 02) | Declarative |
| Caching (TTL 1h) + invalidation on GL write | `ComplianceService` | OR aggregation has no cache-invalidation hook; thin glue |
| Widget envelope assembly | `BBVComplianceWidget` controller | Response shaping, not business logic |

The service is deliberately minimal: it MUST read the aggregation
rather than recompute it. Any duplication of the member-02 formulas in
PHP is an anti-pattern the reviewer should flag.

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| Aggregation values | member 02 `x-openregister-aggregations` | read via OR ObjectService (find/findAll) |
| Cache | NC cache (`ICacheFactory`) | TTL 1h, invalidate on GL write |
| Registers | members 01/03 | `find` / `findAll` (real OR ObjectService API) |

## Security (ADR-005)

`computeComplianceStatus` reads only; the controller route is the
member-04 `#[NoAdminRequired]` read route. No write path, no per-object
IDOR — programme ids resolve within the active administration scope
(member 09).
