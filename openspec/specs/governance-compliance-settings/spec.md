---
status: done
---

# governance-compliance-settings Specification

## Purpose
Relocates the governance-config and audit-log leaves out of the top-level transactional navigation into a Settings "Governance & Compliance" section while keeping every page directly routable via deep links. The relocated audit-trail, change-history, activity-feed, destruction-report, and compliance-export surfaces remain strictly read-only, and AllocationRules stays pure rule metadata with no allocation service execution introduced by the move.
## Requirements
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

