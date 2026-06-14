# Proposal: bookkeeping-rekenkamer-audit-pack

`kind: config` per ADR-032 — the centre of mass is declarative
schema annotations + manifest entries that surface OpenRegister's audit-trail
capabilities and create comprehensive audit documentation for Rekenkamer
(Dutch audit office) and accountant control compliance.

## Summary

Introduce the **Rekenkamer Audit Pack** capability for Shillinq as comprehensive
audit documentation, signing trails, version control, and compliance reporting
surfaces on top of OpenRegister's `audit-trail-immutable` abstraction (per ADR-022).

This change declares that every financial and procurement register MUST carry
`x-openregister-audit: true` and declares the following audit surfaces:

1. Detailed audit trails for each signed document with before/after snapshots
2. Signing audit trail UI showing who approved/signed and when
3. Destruction reports for archived financial records (Archiefwet compliance)
4. Comprehensive change history with user attribution and timestamps
5. GDPR/AVG compliance audit overview and data export
6. Procurement decision audit trails meeting public sector transparency requirements
7. Document version tracking and change order management
8. Configuration change audit log
9. Integration with Nextcloud Activity app for decision lifecycle events
10. Financial record change audit logging

Shillinq ships zero parallel audit tables, zero `lib/Db/Audit*` or
`lib/Service/Audit*` classes — every audit event flows through OpenRegister
per ADR-022.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** bookkeeping-chart-of-accounts, accounts-payable-receivable,
procurement-compliance. The capability is a wiring + UI surface on top of T1 + T2
+ T3 registers; implementation requires these foundational specs to land first.

## Motivation

Dutch bookkeeping compliance (per Burgerlijk Wetboek Boek 2, Archiefwet, BBV,
GDPR/AVG) requires immutable audit trails on every financial and procurement object:

- **Rekenkamer audits** require proof of decision authenticity and change history
- **Accountant control** (accountantscontrole) requires complete before/after trails
- **Archiefwet compliance** requires destruction schedules with audit certification
- **Public sector transparency** (Woo/Openbaarheid) requires procurement decision trails
- **GDPR/AVG** requires subject access with complete activity logs

Per ADR-022, that audit trail MUST come from OpenRegister's `audit-trail-immutable`
abstraction, not from an app-local table.

OpenRegister already provides the audit trail and a query UI; the gap is
shillinq-side wiring: confirming every bookkeeping and procurement register opts in,
and surfacing multiple specialized audit views (signing trails, destruction reports,
compliance exports) inside shillinq's navigation so bookkeepers / auditors / accountants
don't have to leave the shillinq context to inspect what changed and why.

## Affected Projects

- [x] Project: shillinq — declares the audit-flag requirement on every financial
  and procurement register; adds five specialized audit surfaces (signing trail,
  destruction report, change history, compliance export, activity feed); wires
  the Activity app integration for decision lifecycle.
- [ ] Project: openregister — no source changes; the capability consumes the
  existing `audit-trail-immutable` abstraction and the existing audit-log UI.
- [ ] Project: nextcloud-core — existing Activity app provides the lifecycle
  event integration; no changes required.

## Scope

### In Scope

- One new capability spec (`bookkeeping-rekenkamer-audit-pack`) — see the
  `specs/` folder.
- Declaration that every T1 + T2 + T3 + future bookkeeping and procurement
  register MUST carry `x-openregister-audit: true`.
- Five specialized audit surfaces:
  1. **Signing Audit Trail**: shows who approved and when, with signature validation
  2. **Destruction Report**: documents archival and destruction per Archiefwet
  3. **Change History**: complete before/after diffs with user attribution
  4. **Compliance Export**: CSV/Excel/JSON export of audit events for external auditors
  5. **Activity Feed**: integration with Nextcloud Activity app for decision timeline
- Manifest navigation entry into OR's audit-log UI pre-filtered to bookkeeping
  and procurement object types.
- Audit side panel on every bookkeeping and procurement detail page, filtered
  to the object's UUID so the bookkeeper sees the per-object history inline.
- Destruction schedule management (mark records for deletion, track destruction).
- GDPR/AVG compliance audit overview showing access, modifications, and exports.
- Explicit forbidding of `lib/Db/Audit*` / `lib/Service/Audit*` / app-local
  audit tables per ADR-022 anti-pattern.
- Retention governed by OR and Archiefwet 7-year rule — not redeclared in shillinq.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal;
  the task list references them but the implementation lands via a separate
  `opsx-apply` cycle.
- **Audit log retention rules** — owned by OR (Archiefwet 7-year default per OR config).
- **External SIEM / log shipping** — owned by Nextcloud / cluster ops, not by shillinq.
- **Blockchain-based signing certification** — signing trails use Nextcloud's
  built-in user identity; cryptographic cert is out of scope.
- **Advanced analytics dashboards** — audit data export supports Power BI /
  Tableau; shillinq provides export only, not dashboards.

## Approach

Five deltas, adding Requirements to a brand-new spec:

**`bookkeeping-rekenkamer-audit-pack`** — declares the audit-flag requirement,
the five audit surface specifications, the manifest navigation entries, the side
panel bindings, the Activity app integration, the destruction schedule lifecycle,
the GDPR compliance audit overview, and the anti-pattern forbiddance. The spec
follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-RAP-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister audit-trail-immutable abstraction,
Nextcloud Activity app, and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — no schema changes in this spec; the
  audit-flag requirement governs every register added by T1 + T2 + T3 + future tier specs.
- `src/manifest.json` — adds 5 navigation entries (Bookkeeping > Signing Trail,
  > Destruction Report, > Change History, > Compliance Export, > Activity Feed)
  and 5 audit-surface bindings for every bookkeeping and procurement detail page.
- No new PHP services, controllers, or Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `audit-trail-immutable` being on by default and
  the audit-log UI being reachable from a manifest entry. Both are stable today.
- **Nextcloud Activity app** — depends on the Activity app being installed and
  the event dispatch system working. Both are stable Nextcloud core features.

## Risks

### Risk 1: Future register additions silently omit the audit flag

**Severity**: Medium
**Mitigation**: REQ-RAP-001 mandates the flag on every bookkeeping and procurement
register; a CI check (extensible from the existing `validate-manifest.js`) verifies
the flag is present. The check fails the PR if a new register ships without it.

### Risk 2: Signing trail assumes Nextcloud user identity is trusted

**Severity**: Low
**Mitigation**: The spec declares that signing is based on Nextcloud user identity
(per ADR-005); cryptographic signatures are out of scope. If crypto-grade signing
is required in future, a separate spec handles that integration.

### Risk 3: Archiefwet destruction compliance requires legal review

**Severity**: Medium
**Mitigation**: The spec declares the destruction schedule UI and audit trail; a
separate legal review cycle (outside this spec) validates that the retention policy
and destruction process meet Dutch law. Spec implementation includes legal sign-off.

### Risk 4: GDPR data export surface may become a honeypot for PII extraction

**Severity**: High
**Mitigation**: Export is RBAC-scoped to `auditor` group only. Content is logged
(all exports tracked in audit trail). Export contains only non-sensitive audit fields
(timestamp, user, object type, operation); PII (email, name, address) is excluded.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder;
no runtime impact. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR; the audit events already captured
by OR remain queryable through OR's own UI.

## Open Questions

1. **Destruction schedule UI vs. automated Archiefwet retention** — should
   destruction be manual (bookkeeper marks for deletion) or automatic
   (system deletes after 7 years)? Resolved during the implementing cycle's
   legal + UX review.
2. **Signing trail granularity** — should the UI show line-item approvals or
   document-level approvals only? Resolved during the `opsx-ff` discovery cycle.
3. **Activity feed filtering by permission level** — should general staff see
   only their own activity or should managers see team activity? Resolved
   during the UX review with `/test-persona-janwillem`.
4. **Compliance export schema** — which audit fields are exportable without
   PII leakage? Coordinate with legal and external auditors during the
   implementing cycle.
