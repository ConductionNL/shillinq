# Proposal: bookkeeping-journal-entries

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are authored.

## Summary

Introduce journal entry (memoriaalboekingen) capability for the Shillinq double-entry
bookkeeping engine. This change adds declarative `JournalEntry` schema + manifest
navigation, enabling operators to record manual journal entries, recurring templates,
and reversing accruals that materialise balanced GL transactions per the
`bookkeeping-general-ledger` capability.

This change is part of **Tier 1 (foundation)** of the 5-tier bookkeeping rollout
(see [adr-001-bookkeeping-tier-roadmap.md](../../architecture/adr-001-bookkeeping-tier-roadmap.md)).
The journal-entries capability depends on and ships alongside
`bookkeeping-chart-of-accounts` and `bookkeeping-general-ledger` in the same T1 envelope.

The change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec
for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
seeding.

## Motivation

Shillinq's double-entry ledger foundation (chart of accounts + GL transactions + GL lines)
lands in T1. However, the GL is a machine-level construct (balanced postings with debit/credit
sides). Bookkeepers work at a higher level: journal entries (human-authored forms),
recurring templates (monthly subscriptions, depreciation, accruals), and reversing journals
(year-end accruals that flip in the next period).

`JournalEntry` is the human surface for posting. Every journal entry materialises exactly
one GL transaction when posted. Three sub-types capture the full range:

- **manual** (memoriaalboekingen) — one-off entries
- **recurring** — templates that fire on a cadence (monthly, weekly, yearly)
- **reversing** — entries that auto-flip at period boundary (accrual reversal pattern)

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers part of **Tier 1**:
the journal-entries capability within the `add-shillinq-bookkeeping-foundation` change.

## Affected Projects

- [x] Project: shillinq — adds 1 new schema to `lib/Settings/shillinq_register.json`,
  adds 1 manifest navigation entry in `src/manifest.json`. Consumes existing OR
  abstractions (audit, RBAC, approval workflow, attachments, lifecycle).
- [ ] Project: openregister — no source changes; this change consumes existing OR
  abstractions. If a needed extension is missing, it is filed as an OR issue and the
  gap recorded in `design.md`.
- [ ] Project: docudesk — no source changes; journal entries reference docudesk
  attachments by foreign-key URI per ADR-022.

## Scope

### In Scope

- One new capability spec (`bookkeeping-journal-entries`) — see the `specs/` folder.
- Manual journal entry (memoriaalboekingen) with multi-line balanced entries.
- Recurring journal templates (subscriptions, depreciation, periodic accruals) with
  `cadence` declaration for OR's scheduled-workflow primitive.
- Reversing journals (accrual reversal pattern) that auto-post inverse entries at
  period boundary.
- Approval-gate workflow consumed from OpenRegister's approval-workflow abstraction
  per ADR-022 — DO NOT reimplement.
- Source-document linkage to docudesk attachments by FK (invoice scans, bank statements).
- Manifest navigation entry (Bookkeeping > Journals) using `type: index`/`type: detail`
  page renderers from `@conduction/nextcloud-vue`.
- Audit trail consumed from OpenRegister's audit-trail-immutable abstraction per
  ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal; the task
  list references them but the implementation lands via a separate `opsx-apply` cycle
  on the spec.
- **Sub-ledgers (AP/AR), period close, financial statements** — those are T2+ capabilities.
- **Multi-currency translation, VAT/BTW posting automation** — T5+ capabilities.
- **Frontend Vue components** beyond what `CnIndexPage` / `CnDetailPage` from
  `@conduction/nextcloud-vue` already render generically from a register manifest.
  No bespoke Vue files in this spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-journal-entries`** — declares the `JournalEntry` register as a
human-authored construct for journal posting. Sub-types: `manual` (memoriaal),
`recurring` (cadence-driven template), and `reversing` (auto-reversing at period
boundary). Posting a journal materialises exactly one balanced `GLTransaction` per
the general-ledger capability. Approval gate and audit trail consumed from OR.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Requirements are
prefixed for traceability (`REQ-JE-*`) and numbered starting at REQ-001.

## New Dependencies

None beyond those already in T1 foundation. This change consumes existing OpenRegister
abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`JournalEntry`); declares
  `x-openregister-lifecycle` rules on `JournalEntry` (approval + posting).
- `src/manifest.json` — adds 1 navigation entry (Journals) and 1 `type: index` +
  1 `type: detail` page entry.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being stable:
  `x-openregister-lifecycle` (ADR-031), audit-trail-immutable (ADR-022),
  approval-workflow (ADR-022), scheduled-workflow (ADR-031 background-job guidance).
  If a needed shape is missing, the gap is filed as an OR issue and the relevant
  requirement is annotated in `design.md`.
- **docudesk** — journal entries reference attachments by foreign-key URI for source
  documents. No code coupling — the FK is a plain string field validated by JSON Schema.

## Risks

### Risk 1: Cross-schema materialisation requires declarative lifecycle outcome

**Severity**: Medium  
**Mitigation**: Posting a `JournalEntry` must emit a CloudEvent that the OR engine
consumes and creates the GL header + lines in a single transaction. Whether this fits
inside an `x-openregister-lifecycle.requires` or action decorator depends on whether
the engine supports cross-schema effects. If not, ADR-031's exception path applies:
a thin `BookkeepingMaterializationService::materializeGLTransaction(journalId)` PHP
service is called from the lifecycle's action handler. The service is stateless, ~50 LOC.
Document the gap as an OR issue. The spec author resolves this during `opsx-ff`
discovery, not during `opsx-apply`.

### Risk 2: Scheduled-workflow + n8n adapter stability

**Severity**: Low  
**Mitigation**: Recurring journals depend on OR's `ScheduledWorkflow` primitive + n8n
adapter (ADR-031 §"Background jobs"). If the adapter is not yet stable, defer recurring
journals to T2. The spec permits a `journalType: "recurring"` enum value but allows T1
to reject it with "not yet implemented" in the UI. Ship manual + reversing first; add
recurring in T2 if needed.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime
impact because no implementation lands until `opsx-apply` is run on the spec. After
implementation (separate cycle), rollback follows the standard pattern: revert the
implementing PR, run the repair step in down-direction (registers are non-destructive —
unused schemas remain queryable but unreferenced). No data migration risk at the spec stage.

## Open Questions

1. **Cross-schema materialisation** — see Risk 1. The `opsx-ff` design phase resolves
   whether the lifecycle engine supports emitting events that trigger GL posting or
   whether a thin service is needed. The spec itself is shape-neutral: `REQ-JE-007`
   mandates the outcome without prescribing implementation.
2. **Recurring journal scheduling backend** — is OR's `ScheduledWorkflow` + n8n
   adapter ready in T1, or should recurring defer to T2? If defer, ship manual + reversing
   in T1, add recurring in T2's `add-shillinq-bookkeeping-compliance` change.
3. **Approval policy scope** — does an approval policy apply to all journals (simple
   threshold) or per journal-type (manual requires dual control; recurring/reversing
   automatic)? Confirm with bookkeeper persona before `opsx-apply`.
