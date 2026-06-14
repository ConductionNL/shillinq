# Spec: governance-compliance-settings

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (information architecture / configuration surfacing)
**Depends on:**
- `src/menu-layout.json` (ADR-037 canonical nav layout — `removals` semantics:
  leaf drops from menu, page route survives).
- The existing `Settings` page (`type:"settings"`, route `/settings`) in
  `src/manifest.json` — extended with a `governance-compliance` section.
- The existing pages/schemas (unchanged): `AllocationRules` → `AllocationRule`
  (ADR-031 rule metadata, no service runs); `BookkeepingAuditTrail`,
  `BookkeepingChangeHistory`, `BookkeepingActivityFeed`,
  `BookkeepingDestructionReport`, `BookkeepingComplianceExport` (read-only
  logs/exports in the Bookkeeping group); `Bewaartermijnen` (retention terms,
  root `Administratie` group).
- `shillinq-delegate-signing` — owns `BookkeepingSigningTrail`, which is
  EXCLUDED from this change.

## ADDED Requirements

@e2e exclude unbuilt UI: the Settings "Governance & Compliance" sub-section
container is not yet rendered; the relocated pages themselves already exist and
stay routable.

### Requirement: REQ-GOVSET-001 — The system SHALL relocate the governance-config and audit-log leaves out of top-level navigation into a Settings "Governance & Compliance" section, keeping every page routable

The seven leaves — `AllocationRules`, `BookkeepingAuditTrail`, `BookkeepingChangeHistory`, `BookkeepingActivityFeed`, `BookkeepingDestructionReport`, `BookkeepingComplianceExport`, and `Bewaartermijnen` — MUST be removed from the transactional navigation tree by adding their leaf ids to `removals` in `src/menu-layout.json`, and MUST be
re-homed under a `governance-compliance` section declared in the existing
`Settings` (`type:"settings"`) page's `config.sections[]`. Each page's `route`
MUST continue to resolve directly (deep link) per the `menu-layout.json` `_meta`
"pages stay routable" rule. No schema, register fragment, page declaration, or
PHP is added or changed by this requirement.

#### Scenario: Governance leaves are gone from the Bookkeeping and Administratie groups

- **GIVEN** the rebuilt shillinq front-end with the updated `menu-layout.json`
- **WHEN** an operator opens the left navigation
- **THEN** `AllocationRules`, `BookkeepingAuditTrail`, `BookkeepingChangeHistory`,
  `BookkeepingActivityFeed`, `BookkeepingDestructionReport`, and
  `BookkeepingComplianceExport` MUST NOT appear under the Bookkeeping group, and
  `Bewaartermijnen` MUST NOT appear under the `Administratie` group

#### Scenario: Each relocated page is reachable from Settings → Governance & Compliance

- **GIVEN** the Settings page with the new `governance-compliance` section
- **WHEN** the operator opens Settings and selects "Governance & Compliance"
- **THEN** the section MUST list links to all seven relocated pages, and
  selecting each one MUST open the same page (same register + schema) that the
  old top-level leaf opened

#### Scenario: Deep links to relocated routes still resolve

- **GIVEN** the relocated leaves were dropped from the menu via `removals`
- **WHEN** a user navigates directly to a relocated route (e.g. the
  `AllocationRules` route or the `Bewaartermijnen` route)
- **THEN** the page MUST render exactly as before (route preserved, page not
  deleted)

### Requirement: REQ-GOVSET-002 — The relocated bookkeeping log and export surfaces SHALL remain strictly read-only

`BookkeepingAuditTrail`, `BookkeepingChangeHistory`, `BookkeepingActivityFeed`, `BookkeepingDestructionReport`, and `BookkeepingComplianceExport` are audit evidence; moving them into Settings MUST NOT add any create/update/delete control, and they MUST stay read-only views over their existing register objects.

#### Scenario: No write affordance is introduced by the move

- **GIVEN** any of the five log/export pages opened from Settings → Governance
  & Compliance
- **WHEN** the page is inspected for write affordances
- **THEN** it MUST present only read/filter/export-of-record actions, identical
  to its pre-move behaviour — no new create/edit/delete control is added by this
  change

### Requirement: REQ-GOVSET-003 — AllocationRules SHALL remain pure ADR-031 rule metadata with no service execution, only re-homed under Settings

`AllocationRules` (schema `AllocationRule`) is rule metadata declared per ADR-031 and no `AllocationService.allocate()` runs; relocating it into Settings MUST NOT introduce any allocation execution path, and it MUST stay editable rule config.

#### Scenario: Relocation adds no allocation service

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for a new `AllocationService` or any code that executes the
  `AllocationRule` metadata
- **THEN** none MUST exist; `AllocationRules` remains config-only, editable from
  Settings → Governance & Compliance

## REMOVED Requirements

### Requirement: REQ-GOVSET-900 — Governance-config and audit-log leaves SHALL NOT appear as top-level transactional navigation

These seven leaves SHALL NOT be surfaced as top-level transactional nav; their
home MUST be Settings → Governance & Compliance (REQ-GOVSET-001). This REMOVED block
records that the top-level menu placement is retired (the pages/routes are NOT
removed). `BookkeepingSigningTrail` is explicitly NOT part of this removal — it
is owned by `shillinq-delegate-signing`.

#### Scenario: Signing trail is untouched by this change

- **GIVEN** the updated `menu-layout.json` `removals`
- **WHEN** inspected for `BookkeepingSigningTrail`
- **THEN** `BookkeepingSigningTrail` MUST NOT be among the leaves removed by this
  change, and it MUST remain in the Bookkeeping group (its fate belongs to
  `shillinq-delegate-signing`)
