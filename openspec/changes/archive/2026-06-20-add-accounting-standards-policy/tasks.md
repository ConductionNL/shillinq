# Tasks: add-accounting-standards-policy

## 1. Schema (REQ-ASP-001)
- [x] Add register fragment `lib/Settings/register.d/add-shillinq-accounting-standards-policy.json` declaring the `StandardsPolicy` schema with `frameworks[]` (`key` enum, `enabled`, `precedence`), `administrationId`, `name`, `notes`.

## 2. Admin UI (REQ-ASP-002)
- [x] Add `StandardsPolicyEditor.vue` — enable + drag-rank the 8 frameworks, persist one `StandardsPolicy` object via the OpenRegister objects API.
- [x] Register `StandardsPolicyEditor` (kind: page) in `src/registry.js`.
- [x] Add the `AccountingStandardsPolicy` page + a `Settings → Accounting standards` section link in `src/manifest.json`.

## 3. Resolver (REQ-ASP-003)
- [x] Add `lib/Service/StandardsPolicyService.php` with `resolve(topic)` + pure `resolveFromPolicy(frameworks, topic)`.
- [x] Add `tests/Unit/Service/StandardsPolicyServiceTest.php` covering highest-enabled-wins, reordering, disabled-skip, empty.

## 4. Docs
- [x] `docs/standards/` reference section (overview + per-framework pages + comparisons) linking the policy.

## Out of scope (follow-up)
- [ ] Apply the resolved framework to real posting/valuation paths (`*-engine` change).
- [ ] Per-topic framework applicability beyond highest-enabled-wins.
