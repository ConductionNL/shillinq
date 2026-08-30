# Design — Rate Card Engine

status: pr-created

## Context

Rate-card management is foundational for time-tracking, project
billing, and financial reporting. The original Shillinq scope
(invoicing) requires billing at accurate rates; competitors show that
multi-tier rate hierarchies (by user, role, project, client, or
blended default) are standard, with effective-dated periods to support
forward-looking rate adjustments without breaking historical invoices.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire rate-card surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per
  ADR-031.
- Establish a **multi-tier rate hierarchy** with deterministic
  precedence (user > role > project > client > blended default).
- Enable **effective-dated rate periods** so rates can be adjusted
  forward-looking without breaking historical invoice records.
- Make the spec a **Dutch SMB accountant-readable contract** —
  rate-card setup, tier precedence, and effective-date windows are
  recognisable and predictable.
- Provide **rate-lookup aggregation** (no PHP service) and
  **materialized audit trail** (RateRecord) for billing
  reconciliation and dispute prevention.

## Non-Goals

- No PHP rate-calculation service; no `RateResolutionService.php`.
- No multi-currency conversion; rates are single-currency per card.
- No real-time alerts or what-if scenario modeling.
- No cross-administration rate sharing (per-OU isolation for now).

## Decisions

### D1 — Rate cards are templates versioned by effective-date window

`RateCardTemplate` is a reusable rate-structure definition (tiers:
user, role, project, client, blended). `RateCardVersion` represents
an effective-dated variant, allowing multiple concurrent versions
(e.g., "2026-01-01 rates", "2026-04-01 rates") per administration.
This decouples rate-card schema from rate-period lifecycle.

### D2 — Rate schedules are tier-specific, not flat

`RateSchedule` defines rates at a single tier (e.g., "user Alice's
hourly rate" or "role manager's project-X rate"). Each schedule is
effective-dated, allowing different rates per period without
overwriting history.

### D3 — Rate lookup is an aggregation query, not a service

Given (user, role, project, client, service-type, date), the
rate-resolution aggregation query returns the most-specific applicable
rate: user > role > project > client > blended default. If OR's
`x-openregister-aggregations` is stable, this is pure declarative
metadata. Per ADR-031 exception, if not stable, a single-method
`RateResolutionGuard` ships, documented.

### D4 — Resolved rates are materialized into RateRecord

Each rate lookup is materialized into a `RateRecord` (resolved tier,
resolved amount, effective period, resolution timestamp). This
provides audit trail, dispute-prevention, and historical
queryability. No re-calculation; the materialized result is
immutable.

### D5 — Effective dates are non-overlapping per (tier, entity)

For deterministic lookup results, (RateSchedule.tier, RateSchedule.entityId)
pairs MUST NOT have overlapping effective-date windows. Validation
enforced at schema level or aggregation precondition.

### D6 — Rate tiers are user > role > project > client > blended

This precedence is baked into the aggregation query signature,
reflecting Dutch SMB common practice: user-specific overrides are most
granular; blended default is the fallback. No custom tier ordering per
administration.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Multi-tier rate structure | RateCard entity (ADR-000, supplier-focused) | Distinct from supplier RateCard; rate-card-engine is for employee/project/client billing rates, not supplier rates. Parallel entities. |
| Effective-date windows | OR `x-openregister-lifecycle` (ADR-031) | RateCardVersion and RateSchedule use effective-date start/end; OR lifecycle extension if available. |
| Rate-lookup precedence | OR `x-openregister-aggregations` | Aggregation query with tier ordering; falls back to `RateResolutionGuard` per ADR-031 exception. |
| Materialized audit trail | T2 `bookkeeping-audit-trail` | RateRecord register stores resolved rate per lookup with timestamp + effective period. |
| Manifest navigation | T1 manifest pattern | 3 entries (Rate Cards, Schedules, Audit Trail) + their index/detail pages. |

## Seed Data

Example rate-card structure for a Dutch SMB (3 consultants, 2 roles,
2 projects):

**RateCardTemplate (RCT-001):**
- Name: "2026 Consulting Rates"
- Tiers: user, role, project, client, blended
- Description: "Multi-tier rates for consulting services"

**RateCardVersion (RCV-001):**
- Template: RCT-001
- Effective: 2026-01-01
- Expiry: 2026-03-31
- Currency: EUR

**RateSchedule examples:**
1. (Blended default) €85/hour, 2026-01-01 to 2026-03-31
2. (Role: Senior Consultant) €120/hour, 2026-01-01 to 2026-03-31
3. (Role: Junior Consultant) €65/hour, 2026-01-01 to 2026-03-31
4. (User: Alice, Senior) €130/hour, 2026-02-01 to 2026-03-31 (overrides role)
5. (Project: "Gemeente Migration") €95/hour, 2026-01-01 to 2026-03-31 (client fixed-price project)

**RateRecord (audit trail):**
- Lookup: (user=Alice, role=Senior, project=null, client=null, date=2026-02-15)
- Resolved tier: user
- Resolved amount: €130/hour
- Effective window: 2026-02-01 to 2026-03-31
- Created: 2026-02-15T10:30:00Z

## Design Trade-offs

| Trade-off | Choice | Rationale |
|---|---|---|
| Per-OU vs. cross-OU sharing | Per-OU (administrationId FK) | Isolation by default; cross-OU templates (2026-Q3+) is a future addition. Prevents accidental rate leakage. |
| Flat tier list vs. hierarchical | Flat (user > role > project > client > blended) | Simpler, more predictable; hierarchical nesting (future 2026-Q3) can layer on top. Dutch SMB practice uses flat precedence. |
| Aggregation query vs. PHP service | Aggregation (per ADR-031) | Declarative, testable, auditable; PHP service adds maintenance burden. |
| Immediate vs. delayed effective-date activation | Immediate (effective-date ≥ today) | Prevents retroactive rate changes; historical invoices remain stable. Operators must plan ahead. |
