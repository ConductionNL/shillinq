# Proposal: add-shillinq-journal-entries

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are
authored.

## Summary

Introduce the **journal entries** capability for Shillinq as the third
slice of the bookkeeping foundation (Tier 1 of the 5-tier bookkeeping
rollout per `adr-001-bookkeeping-tier-roadmap.md`). This change
declares the `JournalEntry` register as the human-author construct that
materialises one or more GL transactions on post. Three sub-types
ship: `manual` (memoriaalboekingen), `recurring` (with cadence —
depreciation / subscriptions / accruals), and `reversing` (auto-
reversing on next period boundary). Approval routing is consumed from
OpenRegister's approval-workflow abstraction via
`x-openregister-lifecycle.requires` (per ADR-022) — DO NOT
reimplement. Source documents reference docudesk attachments by FK URI
(per ADR-022). No PHP service classes, no custom database tables, no
Vue components — the entire capability lands as register metadata +
manifest entries.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:**
- [`add-shillinq-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md)
  — `JournalEntry.lines[].accountNumber` foreign-keys into `Account.accountNumber`.
- [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
  — `JournalEntry.glTransactionId` is the back-reference to the
  `GLTransaction` materialised on post.

## Motivation

Bookkeepers think in journal entries (memoriaalboekingen, recurring
templates, reversing accruals). The GL transaction is the
machine-readable balanced posting that gets created when a journal
is posted. Without a human-author surface, every general ledger
posting would need to be hand-authored as a `GLTransaction` +
`GLLine` set — fine for automated AP/AR feeds (Tier 2+) but
unworkable for the manual journals a competent bookkeeper authors
every period.

The sibling `add-shillinq-chart-of-accounts` change declares the
`Account` register. The sibling `add-shillinq-general-ledger` change
declares the balanced ledger that this change's `JournalEntry`
materialises on post. This change owns the human surface that closes
the loop.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`JournalEntry`)
  to `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry in `src/manifest.json`
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  approval workflow, `ScheduledWorkflow` for recurring materialisation
  per ADR-031 background-job guidance). If a needed extension is
  missing, the gap is filed as an OR issue and recorded in `design.md`.
- [ ] Project: docudesk — no source changes; journal entries reference
  docudesk attachments by foreign-key URI per ADR-022.

## Scope

### In Scope

- One new capability spec (`bookkeeping-journal-entries`) — see the
  `specs/` folder.
- `JournalEntry` register with three sub-types:
  - `manual` (memoriaal) — multi-line balanced entry authored by a
    bookkeeper.
  - `recurring` — declares a `cadence`; the OR `ScheduledWorkflow`
    primitive materialises the next posting per ADR-031.
  - `reversing` — declares a `reversesOn: <periodId>`; the OR
    scheduled-workflow primitive creates the inverse posting at
    period boundary.
- Approval-gate workflow consumed from OpenRegister's
  approval-workflow abstraction via
  `x-openregister-lifecycle.requires` per ADR-022 — DO NOT
  reimplement.
- Source-document linkage to docudesk by foreign-key URI per ADR-022
  — no file blob in the register.
- Opening-balances import as a journal-entry type with
  source-document FK to a docudesk attachment.
- Materialisation declarative — a lifecycle `post` action on
  `JournalEntry` emits a CloudEvent that the OR engine consumes and
  creates the GL header + lines. No PHP orchestration.
- Manifest navigation entry (Bookkeeping > Journals) using
  `type: index`/`type: detail` page renderers from
  `@conduction/nextcloud-vue`.
- Audit trail consumed from OpenRegister's audit-trail-immutable
  abstraction per ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Chart of accounts** — owned by sibling
  `add-shillinq-chart-of-accounts`.
- **General ledger (balanced postings, GLTransaction/GLLine)** —
  owned by sibling `add-shillinq-general-ledger`.
- **VAT/BTW posting automation** — Tier 3
  (`add-shillinq-bookkeeping-operations` ships `bookkeeping-vat-btw-filing`).
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in this
  spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-journal-entries`** — declares the `JournalEntry`
register as a higher-level human-authored construct that materialises
one or more GL transactions on post. Sub-types: `manual` (memoriaal),
`recurring` (with `cadence`), and `reversing` (auto-creates inverse on
next period boundary). Approval gate consumed via the OpenRegister
approval-workflow abstraction.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-JE-*` for
traceability.

## New Dependencies

- **`add-shillinq-chart-of-accounts`** — `JournalEntry.lines[].accountNumber`
  FKs into `Account`.
- **`add-shillinq-general-ledger`** — `JournalEntry.glTransactionId` is
  the back-reference to the `GLTransaction` materialised on post.

Otherwise none. This change consumes existing OpenRegister abstractions
and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`
(from `shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema
  (`JournalEntry`); declares `x-openregister-lifecycle` (approval).
- `src/manifest.json` — adds 1 navigation entry (Journals) and 1
  `type: index` + 1 `type: detail` page entry.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022), approval-workflow (ADR-022 + the `object-interactions`
  spec), `ScheduledWorkflow` + n8n adapter (per ADR-031 background-job
  guidance). If a needed shape is missing, the gap is filed as an OR
  issue and recorded in `design.md`.
- **docudesk** — journal entries reference attachments by foreign-key
  URI for source documents. No code coupling — the FK is a plain
  string field validated by JSON Schema.

## Risks

### Risk 1: OR's approval-workflow abstraction does not yet expose policy-binding by amount threshold

**Severity**: Low
**Mitigation**: Some administrations require approval only above
a threshold (e.g. €5000); others always require dual control. The
approval-workflow `policy` reference is a string token bound on the
schema; if OR's current shape only supports static policy names, the
amount-threshold variant requires a thin OR extension. File as OR
issue; the spec is written to mandate the behaviour without
prescribing the OR mechanism. If unavailable at implementation, ship
the static-policy variant and queue threshold-based policies for the
next OR release.

### Risk 2: Recurring materialisation needs `ScheduledWorkflow` + n8n adapter to be wired

**Severity**: Medium
**Mitigation**: ADR-031 background-job guidance routes recurring
postings through the OR `ScheduledWorkflow` primitive with an n8n
adapter. If the primitive is not yet wired on the target environment,
materialisation falls back to a Nextcloud cron job invoking
`occ openregister:scheduled-workflow:run` per ADR-031 guidance —
still no app-local cron. The spec mandates the behaviour without
prescribing the runner; the discovery step resolves which path
applies.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are
non-destructive — unused schemas remain queryable but unreferenced).
No data migration risk at the spec stage.

## Open Questions

1. **Approval-policy binding shape** — see Risk 1. Resolved in
   `opsx-ff` discovery with OR's current `approval-workflow` extension
   shape.
2. **Reversing-journal collision with manual journal at period
   boundary** — the reversing posting is timestamped to the period's
   first day; manual journals on that day post separately and the
   audit trail surfaces both. Confirm with the bookkeeper persona
   that this is the desired UX.
