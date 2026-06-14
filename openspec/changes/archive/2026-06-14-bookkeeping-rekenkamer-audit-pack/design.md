# Design — Rekenkamer / Accountantscontrole Audit Pack

## Context

Dutch bookkeeping compliance (Burgerlijk Wetboek Boek 2, Archiefwet, BBV, GDPR/AVG,
Woo/Openbaarheid) requires immutable audit trails on every financial and procurement
object. OpenRegister already provides `audit-trail-immutable` per register (opt-in via
`x-openregister-audit: true`) and a query UI that filters audit events by object type,
actor, and timestamp.

The gap is that shillinq has many bookkeeping and procurement registers (T1's three,
T2's ~10, T3's ~15) and risks:

- (a) Future registers silently shipping without the audit flag, breaking compliance posture.
- (b) Bookkeepers having to leave shillinq's context to inspect history.
- (c) No specialized surfaces for specific audit use cases (signing trails, destruction reports,
  GDPR data exports, compliance reports).

This change wires all three gaps closed via five complementary audit surfaces:

1. **Signing Audit Trail** — who approved and signed (document-level and line-item level)
2. **Destruction Report** — Archiefwet-compliant archival and destruction tracking
3. **Change History** — complete before/after diffs with user attribution
4. **Compliance Export** — CSV/Excel/JSON for external auditors (with PII exclusion)
5. **Activity Feed** — Nextcloud Activity app integration for decision timeline

The change is **spec-only**. Implementation lands later through `opsx-apply` and the
standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the five audit surfaces as **declarative metadata** — schema flag + manifest
  entries. No PHP audit code in shillinq.
- Consume OR's `audit-trail-immutable` abstraction — per ADR-022. Zero parallel audit
  table in shillinq.
- Make the surfaces **bookkeeper-discoverable** — top-level navigation, per-object side
  panel, and Activity feed integration.
- Support **Dutch compliance requirements** — Archiefwet (7-year retention), BBV
  (bookkeeping integrity), AVG/GDPR (subject access), Woo (transparency).
- Forbid the anti-pattern (`lib/Db/Audit*`, `lib/Service/Audit*`) explicitly per ADR-022.

## Non-Goals

- No app-local audit table or PHP audit service in shillinq.
- No audit retention rules in shillinq (governed by OR and Archiefwet).
- No external SIEM / log shipping (Nextcloud / cluster ops).
- No bespoke Vue audit panels beyond the five declared surfaces — the OR audit-log UI
  is the canonical read-only source.
- No blockchain-based signing certification — signing trails use Nextcloud user identity.
- No analytics dashboards in shillinq — compliance exports support external BI tools.

## Decisions

### D1 — Audit flag declared once per register, enforced by CI

Per ADR-031, the flag is schema metadata (`x-openregister-audit: true`) on every
bookkeeping and procurement register. A CI check extends `validate-manifest.js` (or a
sibling `validate-registers.js`) to assert presence on every register tagged as
bookkeeping or procurement.

**Alternative considered**: Enforce in code review only. Rejected — human review misses
silent omissions; CI is the durable guarantee.

### D2 — Five specialized audit surfaces, each with distinct owner and UI

Rather than one generic "audit log" view, the spec declares five surfaces, each
serving a distinct stakeholder:

| Surface | Owner | Question answered | Users |
|---------|-------|-------------------|-------|
| Signing Trail | Accountant | Who approved this document and when? | Auditor, Accountant |
| Destruction Report | Compliance Officer | What records were destroyed and when? | Auditor, Legal, Compliance |
| Change History | Bookkeeper | What changed in this record? | Bookkeeper, Supervisor |
| Compliance Export | External Auditor | Can I see all changes for compliance? | Auditor (external), Account manager |
| Activity Feed | Staff | What happened to this decision today? | All staff (filtered by permission) |

**Alternative considered**: One generic "audit log" view for all. Rejected — each
stakeholder has different filter/export needs; cramming all into one view confuses
priorities and hides the important fields for each use case.

### D3 — Destruction schedule lifecycle with legal audit trail

Archiefwet requires proof that records were destroyed per policy. The destruction
schedule is not a deletion — it's a state transition tracked in the audit trail:

- Records enter `status: retained` on creation.
- At 7 years (configurable per Archiefwet article), they become eligible for
  `status: marked-for-destruction`.
- A compliance officer reviews and approves the batch (creates a destruction order).
- The destruction is executed and logged as a removal event in the audit trail
  (not a true deletion, but a terminal state change).
- External auditors query the audit trail to verify compliance.

**Alternative considered**: Auto-delete at 7 years. Rejected — deletion is irreversible
and exposes the system to accidental data loss. Explicit destruction order with approval
is the safer, auditable path.

### D4 — GDPR/AVG compliance audit with PII exclusion

The compliance export surface logs all audit events for a subject (GDPR subject access
request) but excludes direct PII fields (email, phone, address). If external auditors
need PII-complete audit, they request it separately via a dedicated compliance export
channel with additional legal review.

**Alternative considered**: Full PII export. Rejected — logging all PII in every
export creates a honeypot for data leakage; explicit legal review and separate channel
is the safer practice.

### D5 — Activity app integration for decision lifecycle

Decisions (approvals, rejections, sign-offs) are emitted as Nextcloud Activity events,
allowing staff to see "what happened to this case/invoice" via the familiar Activity
UI. The Activity feed is permission-scoped (you see only activity on objects you
have read access to).

**Alternative considered**: Custom activity panel in shillinq. Rejected — Nextcloud
Activity is the canonical UI for "what changed"; reusing it reduces UI fragmentation
and leverages existing Nextcloud tooling (email notifications, dashboard widgets, etc.).

### D6 — Anti-pattern forbiddance is explicit in the spec

Per ADR-022 enumeration, `lib/Db/Audit*`, `lib/Service/Audit*`, and parallel audit
tables are forbidden. The spec explicitly forbids these paths (REQ-RAP-010) so future
contributors don't need to re-derive the rule.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Immutable audit log | OR `audit-trail-immutable` | Consumed via `x-openregister-audit: true` on every register |
| Audit log query UI | OR's audit-log UI | Manifest entry pre-filtered to bookkeeping object types |
| Per-object filter | OR audit-log filter by object UUID | Side panel manifest binding passes the detail page's UUID |
| Signing trail extraction | OR audit-trail before/after snapshots | Query audit trail for mutations + extract approval actor |
| Destruction schedule tracking | OR lifecycle state transitions | Objects transition to `status: marked-for-destruction`, deletion is a state change |
| GDPR data subject access | OR audit-trail query API | Filter by subject ID + exclude PII fields |
| Activity events | Nextcloud Activity app | Emit events via `IActivityManager` on approval/sign events |
| Retention policy | OR retention configuration | Consumed; shillinq does not enforce |
| Actor + before/after capture | OR audit-trail-immutable defaults | Automatic on every register write |
| RBAC on audit access | Nextcloud group system + OR ACL | `auditor` group sees all bookkeeping audits read-only; others see own objects |

**Net new code in implementation cycle**: 0 PHP services + 0 Vue files. Five manifest
patches + one extension to `validate-manifest.js` + Activity event emission on approval
lifecycle transitions (existing service integration).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Audit event capture | Consumed from OR audit-trail-immutable | ADR-022 |
| Signing trail UI | Manifest entry into OR's audit-log UI + format filter | No shillinq code |
| Destruction report UI | Manifest-driven lifecycle state filter | Standard renderer |
| Change history UI | Manifest entry into OR's audit-log UI + before/after format | No shillinq code |
| Compliance export | API call to OR audit-trail API + CSV/Excel export | Service integration only |
| Activity feed | Manifest-driven event emission to Nextcloud Activity | Standard Activity app integration |
| Audit-flag enforcement | CI check on schema metadata | Declarative gate |
| Destruction schedule lifecycle | Lifecycle state transition (not deletion) | Existing lifecycle service |

No service class authored in this envelope.

## Seed Data

None. The capability carries no seed data; audit events accumulate through normal
register operations and destruction schedules are created on demand.

### Example Objects (for manual testing)

When implementing, create these test objects to verify the five surfaces:

**1. Test Account with signing history**
- Create a General Ledger Account (1000-Assets) with three approval states:
  - Draft (created by Bookkeeper A)
  - Approved (signed by Supervisor B)
  - Final (signed by Accountant C)
- Verify signing trail shows all three with timestamps and approval comments.

**2. Test Invoice marked for destruction**
- Create an AP Invoice from 2016 (older than 7 years).
- Mark for destruction (compliance officer action).
- Verify destruction report shows the schedule with approval date.

**3. Test GL Entry with change history**
- Create a GL Transaction.
- Edit the amount three times (different bookkeepers).
- Verify change history shows all three before/after pairs.

**4. Test exports for external auditor**
- Generate compliance export for a date range.
- Verify CSV contains timestamp, object type, actor, operation.
- Verify PII fields (email, phone, address) are excluded.

**5. Test Activity feed for approval**
- Create an Approval Request.
- Approve/reject it.
- Verify Activity feed shows the approval event with actor and result.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Future registers omit the audit flag | CI check fails the PR; reviewer guidance flags audit-flag drift in any new register PR |
| OR audit-log UI re-skins and breaks the manifest filter URL | Manifest filter expressions are documented in the spec; a coordinated rev with the OR team is required for any URL shape change |
| Destruction schedule compliance is misunderstood as auto-deletion | Spec explicitly documents the state-transition model; implementation includes clear UX (button says "Mark for destruction", not "Delete") |
| GDPR export becomes a compliance burden | Export is RBAC-scoped to `auditor` group; implementation logs all exports in audit trail for accountability |
| Activity feed spam from high-volume objects (e.g., daily GL entries) | Activity events are emitted only for approval/signing decisions, not for every change. High-volume changes flow through the Change History surface instead |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `src/manifest.json` is patched with five new navigation entries and five side-panel
   templates for bookkeeping and procurement detail pages (additive).
2. The audit-flag CI check is extended to enumerate bookkeeping and procurement
   registers and assert the flag.
3. Lifecycle event handlers are wired to emit Nextcloud Activity events on approval
   transitions.
4. Compliance export API is called from the export button; export controller filters
   audit trail data and renders CSV/Excel.
5. ADR-000 is annotated with the audit-flag-on-every-bookkeeping-register rule and
   the destruction schedule lifecycle model.

Down-direction: registers are non-destructive — the audit flag, once on, captures
events; reverting removes the manifest entries but preserves the audit data in OR.
Destruction schedules are reversible (unmark for destruction) until the destruction
order is executed.

## Open Questions

1. **Destruction schedule UI vs. automated Archiefwet retention** — should destruction
   be manual or automatic? Resolved during implementing cycle's legal + UX review
   with legal counsel and compliance officer persona.
2. **Signing trail granularity** — document-level or line-item level? Resolved during
   the `opsx-ff` discovery cycle against Rekenkamer requirements.
3. **Activity feed permission scope** — should staff see only their own activity or
   should managers see team activity? Resolved during UX review with personas.
4. **Compliance export PII handling** — which audit fields are exportable without PII
   leakage? Coordinate with legal and external auditors during the implementing cycle.
5. **Batch destruction vs. per-record destruction** — should destruction be done as a
   single batch order or per-record? Resolved during legal + UX review.
