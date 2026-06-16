# Tasks — Audit Trail

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-audit-trail`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-audit-trail` capability spec already exists and that no `lib/Db/Audit*` or `lib/Service/Audit*` classes are present in shillinq (per ADR-022 anti-pattern enumeration) — clean per `audit-pattern-scan.md`; two pre-existing PO-3-way slice-11 files (`lib/Service/AuditExportService.php`, `src/components/three-way-match/AuditTrailDetail.vue`) are CONSUMERS of OR's audit trail, not parallel storage
- [x] Task 2: Author `specs/bookkeeping-audit-trail/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: none` header, `REQ-AT-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; explicitly cite ADR-022 forbiddance of app-local audit — spec carries REQ-AT-001..006 (audit-flag, hash chain, top-level UI, per-object side panel, retention-from-OR, disposition-as-audited-event); each requirement carries 1..2 GIVEN/WHEN/THEN scenarios
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions — proposal cites `../../specs/nextcloud-app/spec.md`, lists Affected Projects (shillinq + OR-as-consumed), Scope (in / out), 2 risks with mitigations, Rollback strategy, 2 Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table; document the two-affordance audit UX (top-level nav + per-detail-page side panel) and the CI enforcement of the audit flag — design carries Reuse Analysis (6 reuse rows), Decisions D1..D3, Declarative-vs-imperative table, Migration Plan and Risks
- [x] Task 5: Audit every existing bookkeeping register (`Account`, `GLTransaction`, `GLLine`, `JournalEntry`) and confirm/add `x-openregister-audit: true` per REQ-AT-001 — `lib/Settings/register.d/add-shillinq-audit-trail.json` declares `x-openregister-audit-trail: { enabled: true }` (canonical OR form) on 138 bookkeeping schemas; the 4 PO-3-way schemas were already opted in via `bookkeeping-purchase-order-3way-11-audit-trail-export.json`; T2 schemas not yet declared (`VendorMaster`, `APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatementLine`, `ReconciliationMatch`) MUST carry the flag at their own change's apply cycle and the Task 8 CI check enforces this
- [x] Task 6: Add Bookkeeping > Audit Trail navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to bookkeeping object types per REQ-AT-002; `node tests/validate-manifest.js` exits 0 — `BookkeepingAuditTrail` page (`type: logs`, route `/bookkeeping/audit-trail`) added with `source` pointing at OR's `/api/audit-trails` filtered to the union of T1+T2 + currently-declared bookkeeping object types; menu entry added under `Bookkeeping` parent at `order: 95`; manifest bumped to 1.3.5; validate-manifest.js PASS
- [x] Task 7: Add the audit side-panel manifest binding to every bookkeeping `type: detail` page (filtered to the object's UUID) per REQ-AT-003 — every bookkeeping `type: detail` page (57 pages) gained a `config.sidebarProps.tabs[]` entry with id `audit`, label `Audit Trail`, a data-widget pointing at OR's `openregister-audit-trail` component (per-object endpoint `/api/objects/shillinq/:schema/:id/audit-trails`), `collapsed: true` so the panel ships closed by default per Open Question 1; manifest bumped to 1.3.6; validate-manifest.js PASS; non-bookkeeping detail pages (bookings, inventory, products, barcodes, …) deliberately untouched
- [x] Task 8: Extend `tests/validate-manifest.js` (or add a sibling `validate-registers.js`) to assert `x-openregister-audit: true` on every register tagged as bookkeeping; CI fails if a future register PR omits the flag — sibling `tests/validate-registers.js` added; enumerates every schema across the main register + `lib/Settings/register.d/*.json` and asserts `x-openregister-audit-trail.enabled === true` on every schema not in the explicit `NON_BOOKKEEPING` opt-out (35 inventory/bookings/notification entries — every new schema is bookkeeping-by-default); current state: 142/142 bookkeeping schemas pass; `package.json` exposes `npm run check:registers`
- [x] Task 9: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note citing the audit-flag-on-every-bookkeeping-register rule and the ADR-022 anti-pattern forbiddance — note inserted at the top of the Entities section between the OpenRegister built-in fields preamble and the first entity; cites REQ-AT-001 / `tests/validate-registers.js`, enumerates the forbidden file patterns from ADR-022, and points readers at the archived change folder for the full capability spec

## Verification

`openspec validate` must exit clean on the change folder. Architecture reviewer confirms ADR-022 compliance (no `lib/Db/Audit*`, no `lib/Service/Audit*`, no parallel audit table). CI check (Task 8) passes. No source code changes outside `openspec/changes/add-shillinq-audit-trail/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting audit event emission on every register's create/update/lifecycle transition (pre-declared on Task 5); CI gate test for the new validate-registers extension (pre-declared on Task 8); Playwright MCP browser tests for the top-level audit nav + per-object side panel (pre-declared on Tasks 6 + 7); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/audit-trail.md` per ADR-030 journeydoc convention and commits an audit-side-panel + top-level audit-log screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Audit Trail`, `Activity`, `Changed by`, `From`, `To`, `Open audit log`.
