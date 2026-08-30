# Proposal: add-shillinq-document-attachment-integration

`kind: config` per ADR-032 — the centre of mass is the declarative
FK-URI contract + mime-type metadata + manifest side-panel
registrations. No PHP service classes, no app-local attachment
storage are authored.

## Summary

Introduce the **document-attachment integration** capability for
Shillinq as one of the cross-app contracts that the bookkeeping
compliance + operations envelope (Tier 2 per
`adr-001-bookkeeping-tier-roadmap.md`) depends on. This change
defines the foreign-key URI contract for attaching source documents
(invoice PDFs, receipts, bank statements, contracts) to bookkeeping
objects via **docudesk** per ADR-022. It declares the mime-type
contract per attachment role, the non-blocking failure mode when
docudesk is unavailable, and the auditor-role pass-through. Shillinq
ships zero file-storage code, zero parallel attachment tables — every
attachment is a docudesk reference resolved at render time.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** nothing. This change defines a cross-app contract
that the AP, AR, bank-reconciliation, journal-entries, and other
bookkeeping capabilities consume.

## Motivation

The Belastingdienst requires 7-year retention of every source
document tied to a bookkeeping entry — the original supplier
invoice PDF, the receipt scan, the bank statement, the contract.
Per ADR-022, shillinq MUST NOT reimplement file storage; the
storage layer lives in **docudesk** (the dedicated document
management app), and bookkeeping objects reference attachments by
foreign-key URI.

Without an explicit contract spec, each downstream bookkeeping
capability would invent its own field shape (`document_id` vs
`source_uri` vs `attachment_ref`), risking divergence and breaking
cross-register audit queries. This capability defines the canonical
URI shape, the mime-type expectations, and the failure semantics in
one place.

## Affected Projects

- [x] Project: shillinq — declares the URI field contract used by
  every register that carries a source-document reference
  (`JournalEntry.sourceDocumentUri`, future
  `APInvoice.sourceDocumentUri`, `BankStatement.sourceDocumentUri`,
  etc.). Adds the audit-side-panel manifest entry per detail page.
  No new registers ship in this change — the contract is consumed
  additively by other capability specs.
- [ ] Project: openregister — no source changes; the URI contract
  is consumed by `x-openregister-relations` external-FK semantics.
- [ ] Project: docudesk — no source changes; this change documents
  the FK URI shape (`docudesk://attachments/<uuid>/<filename>`) that
  docudesk already serves through its standard object API.

## Scope

### In Scope

- One new capability spec
  (`bookkeeping-document-attachment-integration`) — see the
  `specs/` folder.
- The FK URI contract:
  `docudesk://attachments/<uuid>/<filename>` (canonical), with the
  underlying docudesk object accessible via the standard object
  API.
- Mime-type expectations per attachment role
  (`invoice`/`receipt`/`statement`/`contract`).
- Non-blocking failure mode when docudesk is unavailable: the URI
  persists, the audit trail records the gap, and the bookkeeping
  detail page renders a warning banner. The bookkeeping flow MUST
  NOT block on docudesk transient downtime.
- Auditor-role pass-through: when a bookkeeping object is opened by
  a user with the `auditor` role, the attachment URI resolves
  against docudesk with the same role-derived ACL.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **The downstream registers** themselves (AP, AR, bank statements,
  journal entries) — owned by their respective capability specs.
  This spec only defines the contract those specs reference.
- **docudesk-side schema changes** — docudesk's attachment model is
  already stable; no changes required.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-document-attachment-integration`** — declares the FK
URI contract, mime-type-per-role metadata, and failure-mode
semantics. Every bookkeeping register that wants to attach a source
document references this spec's URI shape rather than inventing its
own.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-DA-*` for
traceability.

## New Dependencies

None. The change consumes the existing docudesk attachment API and
the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35` (from
`shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — no schema changes in this
  spec (additive consumers in other capability specs add the URI
  field).
- `src/manifest.json` — adds the audit-side-panel entry to existing
  bookkeeping detail pages; no top-level navigation.
- No new PHP services, controllers, or Vue components.

## Cross-Project Dependencies

- **docudesk** — depends on the standard attachment object API
  being stable (it is). If docudesk is unavailable at runtime, the
  failure-mode requirement governs behaviour.
- **OpenRegister** — depends on `x-openregister-relations`
  external-FK semantics (URI rather than internal object reference).

## Risks

### Risk 1: docudesk unavailability blocks bookkeeping flow

**Severity**: Medium
**Mitigation**: REQ-DA-003 mandates non-blocking failure mode —
the URI persists, the audit trail records the gap, the detail
page renders a warning banner. Bookkeepers can post journals,
issue invoices, and reconcile bank statements even when docudesk
is transiently down; the attachment binds when docudesk recovers.

### Risk 2: Mime-type drift between expected role and actual upload

**Severity**: Low
**Mitigation**: REQ-DA-002 declares expected mime-types per role
(`invoice` → PDF / image, `statement` → CAMT.053 XML / MT940 /
CSV, etc.). Mime-type mismatch surfaces a warning, not a block —
operators can still attach an image of an invoice even if the
expected type was PDF.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder; no runtime impact. After implementation (separate
cycle), rollback follows the standard pattern: revert the
implementing PR. The URI contract is additive — existing references
remain queryable.

## Open Questions

1. **URI scheme registration** — `docudesk://` is the proposed
   canonical scheme; alternative is full HTTPS URL. Resolved in
   `opsx-ff` discovery against docudesk's documented public API.
2. **Auditor role plumbing across apps** — confirms the
   `auditor` role granted in shillinq propagates to docudesk via
   the Nextcloud group system. Resolved during the implementing
   cycle's RBAC review.
