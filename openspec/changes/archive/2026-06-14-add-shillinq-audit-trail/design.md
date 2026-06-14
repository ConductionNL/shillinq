# Design — Audit Trail

## Context

OpenRegister already provides `audit-trail-immutable` per register
(opt-in via `x-openregister-audit: true`) and a query UI that
filters audit events by object type, actor, and timestamp. The gap
is that shillinq has many bookkeeping registers (T1's three, T2's
~10) and risks (a) future registers silently shipping without the
flag, and (b) bookkeepers having to leave shillinq's context to
inspect history.

This change wires both gaps closed:

- Every bookkeeping register MUST declare the audit flag (REQ-AT-001).
- Manifest entry into OR's audit-log UI, pre-filtered to bookkeeping
  object types (REQ-AT-002).
- Audit side panel on every bookkeeping detail page, filtered to the
  object's UUID (REQ-AT-003).

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the audit surface as **declarative metadata** — schema
  flag + manifest entries. No PHP audit code in shillinq.
- Consume OR's `audit-trail-immutable` abstraction — per ADR-022.
  Zero parallel audit table in shillinq.
- Make the surface **bookkeeper-discoverable** — both top-level
  navigation and per-object side panel.
- Forbid the anti-pattern (`lib/Db/Audit*`, `lib/Service/Audit*`)
  explicitly per ADR-022 enumeration.

## Non-Goals

- No app-local audit table or PHP audit service in shillinq.
- No audit retention rules in shillinq (governed by OR).
- No external SIEM / log shipping (Nextcloud / cluster ops).
- No bespoke Vue audit panel — the OR audit-log UI is the canonical
  surface.

## Decisions

### D1 — Audit flag declared once per register, enforced by CI

Per ADR-031, the flag is schema metadata
(`x-openregister-audit: true`) on every bookkeeping register. A CI
check extends `validate-manifest.js` (or a sibling
`validate-registers.js`) to assert presence on every register tagged
as bookkeeping.

**Alternative considered**: Enforce in code review only. Rejected —
human review misses silent omissions; CI is the durable guarantee.

### D2 — Audit UI surfaced through two complementary affordances

Top-level "Bookkeeping > Audit Trail" navigation answers "show me
everything that happened in bookkeeping last week". Per-object side
panel answers "show me what happened to *this* invoice". Both bind
to the same OR audit-log UI with different filter expressions.

**Alternative considered**: One surface only. Rejected — bookkeepers
need both views; collapsing to one forces context-switching.

### D3 — Anti-pattern forbiddance is explicit in the spec

Per ADR-022 enumeration, `lib/Db/Audit*`, `lib/Service/Audit*`, and
parallel audit tables are forbidden. The spec explicitly forbids
these paths (REQ-AT-005) so future contributors don't need to
re-derive the rule.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Immutable audit log | OR `audit-trail-immutable` | Consumed via `x-openregister-audit: true` on every register |
| Audit log query UI | OR's audit-log UI | Manifest entry pre-filtered to bookkeeping object types |
| Per-object filter | OR audit-log filter by object UUID | Side panel manifest binding passes the detail page's UUID |
| Retention | OR retention configuration | Consumed; shillinq does not enforce |
| Actor + before/after capture | OR audit-trail-immutable defaults | Automatic on every register write |
| RBAC on audit access | Nextcloud group system + OR ACL | `auditor` group sees all bookkeeping audits read-only |

**Net new code in implementation cycle**: 0 PHP services + 0 Vue
files. One manifest patch + one extension to `validate-manifest.js`.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Audit event capture | Consumed from OR audit-trail-immutable | ADR-022 |
| Audit UI | Manifest entry into OR's audit-log UI | No shillinq code |
| Per-object side panel | Manifest-driven (Tier-4) | Standard renderer |
| Audit-flag enforcement | CI check on schema metadata | Declarative gate |

No service class authored in this envelope.

## Seed Data

None. The capability carries no seed data; audit events accumulate
through normal register operations.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Future registers omit the audit flag | CI check fails the PR; reviewer guidance flags audit-flag drift in any new register PR |
| OR audit-log UI re-skins and breaks the manifest filter URL | Manifest filter expressions are documented in the spec; a coordinated rev with the OR team is required for any URL shape change |
| Side panel feels noisy on detail pages with high write volume | Side panel ships collapsed by default; bookkeeper opens it on demand (resolved per Open Question 1) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `src/manifest.json` is patched with one new top-level nav entry
   + one side-panel template for every bookkeeping detail page
   (additive).
2. The audit-flag CI check is extended to enumerate bookkeeping
   registers and assert the flag.
3. ADR-000 is annotated with the audit-flag-on-every-bookkeeping-
   register rule.

Down-direction: registers are non-destructive — the audit flag, once
on, captures events; reverting removes the manifest entries but
preserves the audit data in OR.

## Open Questions

1. **Side panel default state** — open or collapsed. Resolved during
   the implementing cycle's UX review.
2. **Pre-filter expression for the top-level audit nav** —
   resolved in `opsx-ff` discovery against OR's audit-log query-
   param documentation.
