# Design — Document Attachment Integration

## Context

The Belastingdienst-mandated 7-year retention obligation requires
every bookkeeping entry (journal, AP invoice, AR invoice, bank
statement) to carry an immutable reference to its source document.
Per ADR-022, that document storage MUST live in **docudesk**, the
dedicated document-management Conduction app; shillinq carries the
FK only.

This change exists because the downstream T2 capabilities (AP,
AR, bank-rec) and the T1 `JournalEntry` register all want to
attach source documents — and would otherwise each invent its own
field shape. Defining the contract once, in one spec, is the
ADR-022 way.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire attachment surface as the **declarative
  FK-URI contract** — schema field shape + mime-type metadata +
  failure-mode semantics. No PHP attachment service in shillinq.
- Consume the docudesk attachment API — per ADR-022. Zero file
  storage code authored in shillinq.
- Make the contract a **competent-bookkeeper readable contract** —
  a Dutch SMB accountant should recognise that the invoice PDF
  attached to an AP invoice is the same docudesk object surfaced
  under "documents" elsewhere.
- Keep the shape narrow enough that AP / AR / bank-rec /
  journal-entries can all reference this spec without reshaping it.

## Non-Goals

- No file-storage code in shillinq.
- No parallel attachment table mirroring docudesk's storage.
- No docudesk-side schema changes (its model is already stable).
- No bespoke Vue components — the existing docudesk file-viewer
  is invoked via standard URI resolution.

## Decisions

### D1 — Foreign-key URI, not internal object reference

The attachment is referenced by URI string
(`docudesk://attachments/<uuid>/<filename>`), not by internal OR
relation. The URI form supports cross-app linking without coupling
shillinq's register schema to docudesk's internal IDs.

**Alternative considered**: An `x-openregister-relations`
cross-app FK. Rejected — internal FKs require docudesk and
shillinq to share OR object IDs, which is not the docudesk
contract. URI form is the established cross-app pattern.

### D2 — Non-blocking failure mode

When docudesk is unavailable (transient downtime, network
partition), saving a bookkeeping object with a
`sourceDocumentUri` MUST succeed. The audit trail records the
unavailability; the detail page renders a warning banner; the
bookkeeping flow continues.

**Alternative considered**: Block bookkeeping write on docudesk
availability. Rejected — bookkeeping operations are time-sensitive
(month-end close, payment runs); a transient docudesk outage MUST
NOT halt the books.

### D3 — Mime-type per role is metadata, not validation

Each attachment role declares an expected mime-type (`invoice` →
PDF / image, `statement` → CAMT.053 / MT940 / CSV). Mismatch
surfaces a warning, not a block — operators may legitimately
attach a scanned image of a printed invoice.

**Alternative considered**: Hard validation on mime-type.
Rejected — operator-driven flexibility is more valuable than
strict typing for retention purposes.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Document storage | docudesk attachment object API | Consumed via URI; no app-local storage |
| Retention enforcement | docudesk's own retention rules | Consumed; shillinq does not enforce |
| Audit trail of attachment events | OR audit-trail-immutable on the parent bookkeeping register | The URI write/clear event is captured by the parent's audit |
| RBAC pass-through | Nextcloud group system + docudesk role mapping | The shillinq `auditor` group resolves against docudesk's auditor ACL |
| Side-panel UI | docudesk file-viewer (existing) | Manifest declares the side panel binding; no shillinq Vue |
| URI persistence | Schema-level string field on each bookkeeping register | One field shape, referenced by AP, AR, bank-rec, journal-entries |

**Net new code in implementation cycle**: 0 PHP services + 0 Vue
files. One manifest patch for the audit side panel + per-spec
schema field declarations in the consuming capability specs.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Attachment storage | Consumed from docudesk | ADR-022 |
| URI persistence | Declarative (schema field) | Pure data |
| Mime-type metadata | Declarative (schema annotation) | Pure data |
| Failure-mode warning banner | Manifest-driven | Tier-4 manifest renderer |
| Auditor RBAC pass-through | Consumed from Nextcloud group system | Standard plumbing |

No service class authored in this envelope.

## Seed Data

None. The contract carries no seed data; per-spec consumers
add their own seed source-documents only if the consuming capability
needs them (e.g. a sample invoice PDF in the AR seed cycle).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| URI scheme drift between docudesk versions | Pin the scheme in the spec; coordinate with docudesk team on any future scheme change via an `opsx-new` change |
| docudesk unavailability impacts the bookkeeping UX | Failure-mode requirement guarantees the bookkeeping write completes; the banner makes the gap visible |
| Mime-type-per-role table grows over time | Additive — each new consuming spec extends the role table without changing this spec |

## Migration Plan

Spec-only — no runtime migration in this change. When the AP /
AR / bank-rec / journal-entries specs land their schema fields,
they reference this spec's URI shape additively; no existing
field changes.

Down-direction: registers are non-destructive — the URI string
fields remain queryable after rollback even if the consuming
specs revert.

## Open Questions

1. **URI scheme** — resolved per Risk 1 in `proposal.md`.
2. **Auditor RBAC pass-through plumbing** — resolved during the
   implementing cycle's RBAC review.
