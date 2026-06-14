# Proposal: add-shillinq-audit-trail

`kind: config` per ADR-032 — the centre of mass is declarative
schema annotations (`x-openregister-audit: true`) + manifest entries
that surface OR's audit-log UI pre-filtered to bookkeeping objects.
No PHP service classes, no app-local audit tables are authored.

## Summary

Introduce the **audit-trail** capability for Shillinq as the wiring
+ UI surface on top of OpenRegister's `audit-trail-immutable`
abstraction (per ADR-022). This change declares that every
bookkeeping register MUST carry `x-openregister-audit: true`,
registers a manifest entry into OR's audit-log UI pre-filtered to
bookkeeping object types, and adds the audit side panel onto every
bookkeeping detail page. Shillinq ships zero parallel audit tables,
zero `lib/Db/Audit*` or `lib/Service/Audit*` classes — every audit
event flows through OR per ADR-022.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** nothing. The capability is a wiring + UI surface on
top of T1 + T2 registers; it has no code dependency on a particular
T1/T2 spec landing first.

## Motivation

AVG / Woo / Archiefwet compliance requires immutable audit trails on
every bookkeeping object — who changed what, when, and from which
prior state. Per ADR-022, that audit trail MUST come from
OpenRegister's `audit-trail-immutable` abstraction, not from an
app-local table.

OpenRegister already provides the audit trail and a query UI; the
gap is shillinq-side wiring: confirming every bookkeeping register
opts in, and surfacing the OR audit log inside shillinq's
navigation so bookkeepers / auditors don't have to leave the
shillinq context to inspect what changed.

Without this capability spec, future register additions in shillinq
might silently omit the audit flag (the field is opt-in per
register), breaking compliance posture.

## Affected Projects

- [x] Project: shillinq — declares the audit-flag requirement on
  every bookkeeping register; adds the "Bookkeeping > Audit Trail"
  manifest navigation entry; adds the audit side-panel binding to
  every bookkeeping `type: detail` page.
- [ ] Project: openregister — no source changes; the capability
  consumes the existing `audit-trail-immutable` abstraction and the
  existing audit-log UI.

## Scope

### In Scope

- One new capability spec (`bookkeeping-audit-trail`) — see the
  `specs/` folder.
- Declaration that every T1 + T2 + future bookkeeping register MUST
  carry `x-openregister-audit: true` (or the OR-canonical equivalent
  flag).
- Manifest navigation entry into OR's audit-log UI pre-filtered to
  bookkeeping object types (`Account`, `GLTransaction`, `GLLine`,
  `JournalEntry`, `FiscalPeriod`, `APInvoice`, `ARInvoice`, etc.).
- Audit side panel on every bookkeeping `type: detail` page, filtered
  to the object's UUID so the bookkeeper sees the per-object history
  inline.
- Explicit forbidding of `lib/Db/Audit*` / `lib/Service/Audit*` /
  app-local audit tables per ADR-022 anti-pattern.
- Retention governed by OR — not redeclared in shillinq.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Audit log retention rules** — owned by OR (Archiefwet 7-year
  default per OR config).
- **External SIEM / log shipping** — owned by Nextcloud / cluster
  ops, not by shillinq.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-audit-trail`** — declares the audit-flag requirement,
the manifest navigation entry, the audit side panel binding, and
the anti-pattern forbiddance. The spec follows the conduction-schema
format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with
exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed
`REQ-AT-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister audit-trail-immutable
abstraction and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — no schema changes in this
  spec; the audit-flag requirement governs every register added by
  T1 + T2 + future tier specs.
- `src/manifest.json` — adds 1 navigation entry (Bookkeeping > Audit
  Trail) and 1 audit-side-panel binding template for every
  bookkeeping detail page.
- No new PHP services, controllers, or Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `audit-trail-immutable` being on by
  default and the audit-log UI being reachable from a manifest
  entry. Both are stable today.

## Risks

### Risk 1: Future register additions silently omit the audit flag

**Severity**: Medium
**Mitigation**: REQ-AT-001 mandates the flag on every bookkeeping
register; a CI check (extensible from the existing
`validate-manifest.js`) verifies the flag is present. The check
fails the PR if a new bookkeeping register ships without it.

### Risk 2: Audit-trail UI placement ambiguity

**Severity**: Low
**Mitigation**: The spec declares both surfaces — top-level
"Bookkeeping > Audit Trail" nav + per-object side panel — so users
can drill in either direction. Placement details (icon, ordering)
resolve during the implementing cycle's UX review.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
the audit events already captured by OR remain queryable through
OR's own UI.

## Open Questions

1. **Side-panel default open vs collapsed** — resolved during the
   implementing cycle's UX review with `/test-persona-janwillem`.
2. **Pre-filter expression in the audit-log manifest URL** —
   resolved in `opsx-ff` discovery against OR's audit-log
   query-param shape.
