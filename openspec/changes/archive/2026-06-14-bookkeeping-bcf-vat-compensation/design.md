# Design — BCF VAT Compensation

## Context

**BCF (Btw-compensatiefonds)** is the Dutch fund that compensates municipalities, water boards, and other public bodies for non-recoverable VAT on qualifying outputs (public services, regulatory activities). The annual entitlement is substantial (~€3M for a medium-sized gemeente).

The BCF claim flow is a quarterly process:
1. **Draft phase** — operator reviews BBV account mappings and applies `bcfCompensable` flag + `compensablePercentage` per mixed-use split
2. **Submission** — operator locks the claim and submits via DigiKoppeling (automated quarterly via `ScheduledWorkflow`)
3. **Acceptance** — Belastingdienst reviews and accepts the claim (typically within 30 days)
4. **Settlement** — Belastingdienst processes payment (typically 30-60 days post-acceptance); system receives webhook event

This is one of five T3 capability splits per ADR-032 spec-sizing (alongside VAT filing, BBV compliance, general ledger, period close).

**The change is declarative-only** — no PHP service classes, no imperative state machine, no local HTTP client.

## Goals

1. Declare the `BcfClaim` register with a `draft → submitted → accepted → settled` lifecycle per ADR-031 (`x-openregister-lifecycle`)
2. Define compensable-VAT aggregation as a filtered sum projection over GL postings, weighted by per-account `compensablePercentage`
3. Declare the quarterly DigiKoppeling submission as an OR `ScheduledWorkflow` consuming the `digikoppeling-bcf` source per ADR-019
4. Implement settlement webhook handling via OR's generic webhook router (no app-local glue)
5. Wire manifest navigation for municipal accountant role with appropriate visibility predicates

## Non-Goals

- No app-local BCF state-machine service class
- No app-local DigiKoppeling HTTP client (consumed via OpenConnector)
- No parallel "BCF accounts" table (flagging lives on `BbvAccountMapping` per ADR-022)
- No email notifications (may be added post-release)
- No historical claim recovery (forward-only per `REQ-BCF-003`)

## Key Decisions

### Decision D1: `BcfClaim` Lifecycle Declarative via x-openregister-lifecycle

**Context**: BCF claims follow a strict state machine: `draft → submitted → accepted → settled`. Each transition has guards (preconditions) and side effects (DigiKoppeling submission, webhook handling).

**Options**:
1. ✅ **Declarative** — Define transitions in `x-openregister-lifecycle` with precondition queries
2. ❌ Imperative — Implement a PHP state-machine service class

**Decision**: **Declarative** — The lifecycle is textbook declarative. Preconditions are:
- `draft → submitted`: 
  - `totalCompensableAmount > 0` (non-empty claim)
  - `claimQuarter is closed` (no mid-quarter edits)
  - Approval workflow gate (requires `bcf-administrator` role)
- `submitted → accepted`: Automatic on Belastingdienst review (external actor)
- `accepted → settled`: Triggered by webhook event (automatic)

**Why**: Moves the state machine into the register metadata where it's visible, auditable, and reusable without custom code.

### Decision D2: Compensable Flagging Lives on BbvAccountMapping, Not a New Register

**Context**: The BCF aggregation must identify which GL postings are compensable (based on the account's purpose). Two design options:

**Options**:
1. ✅ **Extend BbvAccountMapping** — Add `bcfCompensable: boolean` (default false) + `compensablePercentage: int 0-100` (default 100)
2. ❌ Create a parallel `BcfAccount` register — separate entity mapping accounts to BCF rules

**Decision**: **Extend BbvAccountMapping** — Avoids parallel-link-table anti-pattern (ADR-022). The BBV account mapping is already the single source of truth for account classification; BCF compensability is a property of that classification.

**Implementation**: During the `bookkeeping-bbv-compliance` implementation, the per-account `bcfCompensable` flag is set based on account purpose (e.g., public service = compensable, commercial = not). Operator can override per administration via the mapping editor.

### Decision D3: DigiKoppeling Submission via OpenConnector ScheduledWorkflow

**Context**: BCF claims must be submitted quarterly to Belastingdienst via DigiKoppeling (a secure Dutch government API gateway).

**Options**:
1. ✅ **ScheduledWorkflow + OpenConnector source** — Declare a cron-driven workflow that invokes `digikoppeling-bcf` source
2. ❌ Embed HTTP client in shillinq — Add guzzle client + Bearer token management in app code

**Decision**: **ScheduledWorkflow + OpenConnector** — Per ADR-019 (external systems integration). The `digikoppeling-bcf` source is registered in OpenConnector (separate change). Shillinq declares a `ScheduledWorkflow` that:
- Runs quarterly (configurable cron expression, default: first business day of Q+1)
- Filters for `BcfClaim` records in `submitted` state for the closed quarter
- Invokes OpenConnector source with claim data
- OpenConnector handles retry, certificate validation, and response routing

**Why**: Centralizes DigiKoppeling complexity in OpenConnector. Shillinq never holds credentials or HTTP logic.

### Decision D4: Settlement Transition via Webhook (Declarative Routing)

**Context**: Belastingdienst sends a webhook event when a claim is accepted + settled. The event must transition `BcfClaim.state` from `accepted` to `settled`.

**Options**:
1. ✅ **OR webhook handler + declarative routing** — OpenRegister's generic webhook router reads the event, updates the object
2. ❌ Implement a webhook receiver in shillinq — Custom PHP controller + event dispatcher

**Decision**: **OR webhook handler** — OpenRegister provides a generic webhook handler that:
- Receives POST from OpenConnector
- Routes to the appropriate register/schema based on event `type`
- Updates the object's state field
- Records the event in audit trail

Shillinq declares nothing — the webhook handler is configured at the OR level. The `digikoppeling-bcf` source's response payload includes `objectId`, `state: settled`, and timestamp; OR's router applies the update.

**Why**: No shillinq-specific code, consistent webhook handling across all apps, audit trail is automatic.

### Decision D5: Compensable-VAT Aggregation via x-openregister-aggregations

**Context**: `BcfClaim.breakdown` and `BcfClaim.totalCompensableAmount` are derived from GL postings in the quarter. The aggregation rule is:

```
totalCompensableAmount = SUM(
  GLLine.amount 
  WHERE GLLine.periodId = claimQuarter
    AND GLLine.account → BbvAccountMapping.bcfCompensable = true
  MULTIPLY BbvAccountMapping.compensablePercentage / 100
)
```

This is a filtered sum with weight join.

**Options**:
1. ✅ **x-openregister-aggregations** — Declare the projection in metadata; OR computes on save
2. ❌ Computed property in PHP — Service class that queries GL and maps on every read

**Decision**: **x-openregister-aggregations** — Per ADR-031 (declarative projections). The aggregation is declared once in the register JSON:

```json
{
  "x-openregister-aggregations": [
    {
      "name": "compensable-vat-breakdown",
      "type": "sum",
      "sourceRegister": "general-ledger",
      "sourceSchema": "GLLine",
      "filter": {
        "periodId": { "$ref": "#/properties/claimQuarter" },
        "account.bcfCompensable": true
      },
      "weight": "account.compensablePercentage",
      "targets": {
        "breakdown": "details",  // Array of line items
        "totalCompensableAmount": "sum"  // Scalar
      }
    }
  ]
}
```

OR computes the aggregation on save, populating `breakdown` (array) and `totalCompensableAmount` (sum).

**Why**: Transparent, auditable, no business logic in PHP, reusable across reports.

### Decision D6: RBAC — Role-Based Approval Gate on Submit

**Context**: BCF submissions are sensitive — incorrect claims can block municipality funding. Only authorized staff should approve submissions.

**Options**:
1. ✅ **Approval-workflow gate on transition** — Declare `draft → submitted` requires approval-workflow task assigned to `bcf-administrator` role
2. ❌ Simple role check — Anyone with `bcf-administrator` can submit

**Decision**: **Approval-workflow gate** — Per ADR-022 (fine-grained authorization). The transition precondition is:

```json
{
  "requires": {
    "approval-workflow": {
      "chain": "bcf-claim-submit-approval",
      "requiredApprovals": 1
    }
  }
}
```

This enforces that:
- Operator drafts claim
- Operator submits → approval workflow task created
- BCF administrator receives task, reviews claim breakdown, approves
- Workflow completion → transition to `submitted` + cron picks it up

**Why**: Prevents erroneous/fraudulent submissions, audit trail of approver, separates preparer from approver per SOX principles.

## Reuse Analysis

| Capability Needed | What Already Exists | Reuse Strategy |
|---|---|---|
| `BcfClaim` lifecycle state machine | `x-openregister-lifecycle` (ADR-031) | Declared on schema; transitions + preconditions in metadata |
| Compensable-VAT sum aggregation | `x-openregister-aggregations` (ADR-031) | Cross-schema projection over T1 GL with `BbvAccountMapping.bcfCompensable` filter + `compensablePercentage` weight |
| Approval gate on submit transition | OR approval-workflow (ADR-022) | Consumed via `requires.approval-workflow` precondition in lifecycle |
| Audit trail (immutable) | OR audit-trail-immutable (ADR-022) | Consumed automatically on every state change |
| Compensable flag management | `BbvAccountMapping` (from `bookkeeping-bbv-compliance`) | Extended with `bcfCompensable + compensablePercentage` fields; no new register |
| Quarterly submission | OR `ScheduledWorkflow` + n8n adapter (ADR-031) | Cron-driven; references `digikoppeling-bcf` source |
| DigiKoppeling HTTP + cert handling | OpenConnector `digikoppeling-bcf` source (ADR-019) | Symbolic reference; source registration separate |
| Settlement webhook routing | OR generic webhook handler (ADR-031) | No app-local glue; webhook updates object state |
| RBAC (field-level + object-level) | OR authorization + PropertyRbacHandler (ADR-022) | Per-schema roles: `bcf-viewer`, `bcf-operator`, `bcf-administrator` |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (ADR-024) | 1 menu entry under `Overheid` with visibility predicate |

**Net new code in implementation**: 1 schema (`BcfClaim`) + field extension on `BbvAccountMapping` + 1 manifest entry + 1 `ScheduledWorkflow` registration in repair step + UI pages (index/detail) + seed data.

**No new PHP service classes** (state machine, HTTP client, webhook receiver all declarative).

## Declarative vs. Imperative Decision Matrix

| Behaviour | Decision | Why | Trade-off |
|---|---|---|---|
| State machine (`draft → submitted → accepted → settled`) | Declarative (`x-openregister-lifecycle`) | Textbook fit: clean DAG, preconditions, guard conditions | Zero flexibility if transition rules change (but they're regulatory, so immutable is a feature) |
| Compensable-VAT aggregation | Declarative (`x-openregister-aggregations`) | Standard projection-filter with weight join | Aggregation must live in schema metadata (not in reports layer); computed on save, not on-demand |
| Approval gate on submit | Declarative (`requires.approval-workflow`) | Standard precondition, separates preparer from approver | Adds latency (approval task ≠ instant transition) |
| Quarterly submission to DigiKoppeling | `ScheduledWorkflow` + OpenConnector source | ADR-019 standard for external systems | Shillinq has no visibility/control over retry logic (owned by OpenConnector) |
| Settlement webhook processing | OR webhook handler (declarative routing) | Standard webhook routing, no app-local glue | Cannot add custom logic (e.g., send email on settlement) without extending OR |

**Decision**: All logic is **declarative**. No PHP service classes are authored.

## Seed Data

No seed data ships with this change. The `bcfCompensable` flag + `compensablePercentage` defaults come from sibling `bookkeeping-bbv-compliance`'s `rgs-to-bbv-mapping.json` seed data (maps RGS account numbers to BBV taakvelden with default compensability). Operator overrides per administration via the mapping editor.

Example:
- **RGS 3610** (Personnel costs, public service) → BBV **1100** → `bcfCompensable: true`, `compensablePercentage: 100`
- **RGS 3650** (Personnel costs, commercial) → BBV **1100** → `bcfCompensable: false`, `compensablePercentage: 0`

This seed data is in the `bookkeeping-bbv-compliance` change, not here.

## Risks & Trade-offs

| Risk | Severity | Mitigation |
|---|---|---|
| Mixed-use compensable percentage accuracy | Low | Per-mapping `compensablePercentage` field (default 100); operator-editable with approval workflow; audit trail records each change for court review |
| Settlement webhook reliability (late/missing) | Low | Missing webhooks do not block; fallback: operator can manually transition `accepted → settled` via detail page with timestamp + approver. Webhook events are logged for audit. |
| Pre-existing periods on first install | Low | Claim window is forward-only: `claimQuarter ≥ install date` per `REQ-BCF-003`. Pre-existing claims cannot be filed through this system (municipalities use legacy process or one-shot import). |
| Aggregation stale on GL edits | Low | Aggregation is recomputed on `BcfClaim.save()`; post-submission edits to GL do NOT update the claim (state is locked). If GL errors found post-submission, operator creates a revised claim for the next quarter. |
| Quarterly schedule miss (e.g., weekend server outage) | Very Low | `ScheduledWorkflow` is idempotent; if cron misses a firing, the next-scheduled run will process the previous quarter's claims (via `claimQuarter` filter). No claims are skipped. |

## Migration Plan

This change is **additive** (declarative metadata only). When implementation lands in the `opsx-apply` cycle:

### Up (New Deployment / Feature Release)

1. Implementation PR adds schema to `lib/Settings/shillinq_register.json`:
   ```json
   {
     "register": "bcf-claims",
     "schema": "BcfClaim",
     "title": "BCF VAT Compensation Claims",
     "properties": { ... }
   }
   ```

2. Repair step registers `ScheduledWorkflow` for quarterly submission (idempotent):
   ```php
   $this->workflowEngineRegistry->register('bcf-claim-quarterly-submit', [...]);
   ```

3. `BbvAccountMapping` gains 2 optional fields (additive, backward-compatible):
   - `bcfCompensable: boolean` (default: false)
   - `compensablePercentage: int` (default: 100, range 0-100)

4. `src/manifest.json` adds navigation entry (visible to municipal admins only)

5. Seed data: `rgs-to-bbv-mapping.json` (in `bookkeeping-bbv-compliance` change) sets BCF flags per RGS account

### Down (Rollback / Feature Removal)

1. Revert implementing PR
2. Repair step in down-direction:
   - Unregisters `ScheduledWorkflow`
   - Does NOT delete existing `BcfClaim` objects (register cleanup is separate)
3. Existing claims remain queryable via API (non-destructive)
4. Operator may export claims for archival before downgrade

**Zero breaking changes** — schema additions are optional fields, register additions do not cascade.

## Open Questions

1. **Settlement webhook payload shape** — What does OpenConnector's `digikoppeling-bcf` source return when Belastingdienst settles a claim? Confirm the payload includes `objectId`, `state: settled`, and timestamp. Resolved during OpenConnector source registration (`opsx-ff` cycle).

2. **Quarterly cadence boundary** — Does `claimQuarter` field use quarter ID (e.g., `2026-Q1`) or date (YYYY-MM-DD of the first day of the quarter)? Must align with T2 period boundaries. Confirm with period-close spec.

3. **Compensable rates by VAT tier** — BCF compensates only certain VAT rates (typically 21%, sometimes 9% for hospitality). Rate determination happens in T3 `bookkeeping-vat-btw-filing` (GL posting metadata). Confirm that the `bcfCompensable` flag in `BbvAccountMapping` can be rate-aware, or if a simpler per-account boolean suffices. Current design assumes per-account boolean (simpler).

4. **Handover to OR webhook handler** — How does OpenRegister's generic webhook handler know to update `BcfClaim.state`? Does the OpenConnector source emit a CloudEvents-formatted event with `type` + `objectId`? Confirm routing rules during OR webhook handler review.

## Next Steps

- **Spec review** (current): ADR-031 compliance, architectural patterns, risk mitigation
- **Municipality accountant peer review**: Confirm shape vs. Belastingdienst "handreiking" (guidance document)
- **OpenConnector source registration** (`opsx-ff` cycle): Define `digikoppeling-bcf` source and webhook event shape
- **Implementation cycle** (`opsx-apply` cycle): Schema registration, UI scaffolding, tests, docs, i18n
