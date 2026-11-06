# Tasks: required-field-switch

## 1. Data and check

- [x] 1.1 Add `enabled` to `FieldRequirement`, schema 0.2.0, seeds switched on (REQ-RFS-001). Verify: `npm run check:registers`, `npm run check:seeds`, `npm run check:schema-l10n`.
- [x] 1.2 Skip a switched-off requirement in `FieldRequirements::active()` (REQ-RFS-001). Verify: PHPUnit `testASwitchedOffRequirementIsNotChecked`.
- [x] 1.3 `GET /api/field-requirements` lists the caller's active requirements with a title (REQ-RFS-001). Verify: PHPUnit on the controller and `listed()`.

## 2. Admin tab

- [x] 2.1 Declare the `toggle-table` widget under `adminTab` in the fragment and draw it on the Required fields tab (REQ-RFS-001). Verify: vitest `adminSettingsTabs.spec.js`.
- [x] 2.2 Move `<version>` in `appinfo/info.xml` and record the schema version in the register lock. Verify: `RegisterVersionMovesAppVersionTest`.
