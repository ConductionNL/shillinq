# Proposal: bookkeeping-bcf-vat-compensation

`kind: capability` per ADR-032 — a T3 operational feature for Dutch public sector bookkeeping.

## Summary

Introduce **BCF (Btw-compensatiefonds / VAT Compensation Fund) claim administration** as a T3 capability for municipalities and other Dutch public bodies. This spec defines the complete BCF claim lifecycle: creation, quarterly submission via DigiKoppeling, settlement tracking, and audit integration. Municipalities recover ~€3M/year in non-recoverable VAT through BCF claims; without this capability, budget impact is severe.

The feature provides:
- **BcfClaim register** with `draft → submitted → accepted → settled` lifecycle
- **Compensable VAT aggregation** across BBV-mapped accounts with per-account weighting
- **Quarterly DigiKoppeling submission** via OpenConnector integration
- **Settlement webhook handling** for automatic state transition on Belastingdienst acceptance
- **Audit trail & RBAC** for compliance and accountability

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- T3 `bookkeeping-vat-btw-filing` (VAT rate identification on GL postings)
- T3 `bookkeeping-bbv-compliance` (BBV account mapping with compensable flags)
- OpenConnector `digikoppeling-bcf` source (quarterly submission & settlement webhooks)

## Motivation

Dutch municipalities and public bodies are obligated to recover non-recoverable VAT through the btw-compensatiefonds (BCF) — an annual entitlement (~€3M for medium-sized gemeente). Without BCF claim administration:
- Budget impact: ~€3M/year unrecovered
- Compliance risk: missed filing deadlines
- Manual effort: error-prone spreadsheet workflows
- Audit trail: no immutable record for court/auditor review

BCF claim administration is a textbook fit for declarative metadata:
- Lifecycle is a clean state machine (`draft → submitted → accepted → settled`)
- Compensable-VAT aggregation is a filtered sum projection over GL postings
- Quarterly submission is an OR `ScheduledWorkflow` + webhook handling
- Settlement trigger is a received webhook event, not polling

## Affected Projects

- [x] **shillinq** — T3 bookkeeping capability
  - Adds 1 new register/schema (`BcfClaim`) to `lib/Settings/shillinq_register.json`
  - Extends `BbvAccountMapping` with `bcfCompensable` flag + `compensablePercentage` field
  - Adds 1 manifest navigation entry (`Overheid > BCF-claims`) in `src/manifest.json`
  - Registers quarterly DigiKoppeling-BCF `ScheduledWorkflow`

- [ ] **openregister** — consumes existing abstractions (`x-openregister-lifecycle`, `x-openregister-aggregations`, `ScheduledWorkflow`)
- [ ] **openconnector** — consumes existing `digikoppeling-bcf` source (registered separately)

## Scope

### In Scope

- Complete BCF claim capability spec (`bookkeeping-bcf-vat-compensation`)
- `BcfClaim` register with quarterly `draft → submitted → accepted → settled` lifecycle
- Arithmetic preconditions on claim submission (non-empty + quarter closed)
- Extension to `BbvAccountMapping`: `bcfCompensable: boolean` (default false) + `compensablePercentage: int 0-100` (default 100)
- Compensable-VAT aggregation as derived field via `x-openregister-aggregations`:
  - Filters GL postings by quarter + `bcfCompensable=true`
  - Weights by `compensablePercentage` per account
  - Sums to `totalCompensableAmount`
- Quarterly DigiKoppeling-BCF submission as `ScheduledWorkflow` (cron-driven)
- Settlement webhook from OpenConnector → automatic `accepted → settled` transition
- Manifest navigation under `Overheid` (visibility: municipal administrations)
- UI: BCF-claims index (list) + detail (view/edit draft, view submitted) pages
- Audit trail: OpenRegister immutable tracking on all state changes
- RBAC: `bcf-administrator` role for claim submission approval

### Out of Scope

- BBV taakveld (functional area) mapping — owned by `bookkeeping-bbv-compliance`
- VAT/BTW filing — owned by `bookkeeping-vat-btw-filing`
- Mixed-use account splits beyond `compensablePercentage` field — operator workflow
- Email notifications on settlement — application-level feature (may be added post-release)
- Historical claim recovery (claims must be forward-only: `claimQuarter ≥ install date`)

## Approach

**Tier T3 (Operations + NL Compliance Core)** with two primary artifacts:

1. **Data model spec** (`spec.md`):
   - `BcfClaim` entity definition with all properties
   - Lifecycle state machine with preconditions
   - Compensable-VAT aggregation rules
   - RBAC matrix (who can draft, submit, settle, view)

2. **Design spec** (`design.md`):
   - Declarative-vs-imperative decisions per ADR-031
   - Reuse analysis (OpenRegister lifecycle, aggregations, ScheduledWorkflow)
   - Migration plan (add schema, extend mapping, register workflow)

3. **Implementation tasks** (`tasks.md`):
   - Schema declaration in register JSON
   - Lifecycle preconditions and state machine
   - Aggregation query definition
   - ScheduledWorkflow registration
   - UI page scaffolding
   - Seed data generation
   - Tests (unit, integration, browser)

## New Dependencies

None in the platform layer. Consumes existing abstractions:
- OpenRegister: `x-openregister-lifecycle`, `x-openregister-aggregations`
- OpenConnector: symbolic reference to `digikoppeling-bcf` source (registered separately)

## Impact

**Configuration (additive)**:
- `lib/Settings/shillinq_register.json` — adds 1 schema (`BcfClaim`), extends 1 existing (`BbvAccountMapping`)
- `src/manifest.json` — adds 1 navigation entry with visibility predicate
- Migration: repair step registers quarterly `ScheduledWorkflow`

**Database (additive)**:
- New OpenRegister objects: `BcfClaim` (one per quarterly submission)
- New fields on `BbvAccountMapping`: `bcfCompensable`, `compensablePercentage`

**Operational**:
- Quarterly DigiKoppeling submission (fully automated via `ScheduledWorkflow`)
- Settlement webhook processing (automatic state transition)
- No new PHP service classes — all logic is declarative metadata

## Cross-Project Dependencies

- **OpenRegister** — relies on:
  - `x-openregister-lifecycle` for state machine declarativity
  - `x-openregister-aggregations` for compensable-VAT sum projection
  - Approval-workflow preconditions on submit transition
  - Audit-trail immutability

- **OpenConnector** — symbolic reference to:
  - `digikoppeling-bcf` source (quarterly submission)
  - Webhook handler for settlement transitions

## Risks

### Risk 1: Mixed-use account compensable percentage

**Severity**: Low  
**Mitigation**: Per-mapping `compensablePercentage` field (default 100 for fully-compensable, operator-editable for mixed-use splits). All changes audit-trailed for court review.

### Risk 2: Claim arithmetic edge cases

**Severity**: Low  
**Mitigation**: Submit precondition requires `totalCompensableAmount > 0` AND `quarter is closed` (prevents empty claims, lock-in prevents mid-quarter edits). Arithmetic correctness unit-tested on seeded GL+mapping fixtures.

### Risk 3: Settlement webhook reliability

**Severity**: Low  
**Mitigation**: Missing webhooks do not block. Manual fallback: operator can transition `accepted → settled` via detail page once Belastingdienst confirms (out-of-band check). Webhook events are logged for audit.

### Risk 4: Pre-existing periods on first install

**Severity**: Low  
**Mitigation**: Claim window is forward-only: `claimQuarter ≥ install date` per `REQ-BCF-003`. Pre-existing claims cannot be filed through this system; municipalities must use legacy process or a one-shot import if needed.

## Rollback Strategy

**During spec review** (before implementation): revert commit, delete change folder.

**After implementation** (in production):
1. Revert the implementing PR
2. Run repair step in down-direction: deletes `ScheduledWorkflow`, reverts schema extensions
3. Existing `BcfClaim` objects remain queryable (registers are non-destructive)
4. Operator may export claims for archival before downgrade

## Open Questions

1. **Settlement timing** — `accepted → settled` transition triggers on Belastingdienst's actual settlement payment (typically 30-60 days post-submit). Confirm webhook payload shape with OpenConnector source registration (`opsx-ff` cycle).

2. **Quarterly cadence boundary** — BCF claims align with T2 period boundaries (Q1=Jan-Mar, Q2=Apr-Jun, etc.). Confirm `claimQuarter` field is quarter ID (e.g. `2026-Q1`), not YYYY-MM-DD.

3. **Compensable rates by VAT tier** — BCF compensates only certain VAT rates (typically 21%, sometimes 9%). Flag determination happens in `bookkeeping-vat-btw-filing` (T3); confirm that spec's GL posting rate metadata feeds into `BbvAccountMapping.bcfCompensable` flag.

## Success Criteria

- ✓ Spec passes architecture review (ADR-031, ADR-019, ADR-022 compliance)
- ✓ Municipal accountant persona peer review confirms shape matches Belastingdienst "handreiking" guidance
- ✓ Compensable-percentage weighting produces accurate quarterly totals on seeded GL fixture
- ✓ OpenConnector integration ready (`digikoppeling-bcf` source registered)
- ✓ Implementation cycle (opsx-apply) delivers:
  - PHPUnit tests (lifecycle, aggregation, webhook routing)
  - Playwright browser tests (index/detail pages, state transitions)
  - User-facing docs + Dutch/English translations
  - Green `composer test` at CI gate
