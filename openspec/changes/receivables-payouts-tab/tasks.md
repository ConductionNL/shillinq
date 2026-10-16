# Tasks: receivables-payouts-tab

## 1. Data

- [x] 1.1 `ProviderPayout` fragment as designed in `receivables-provider-payouts` design.md, with three seeds, declared in the register (REQ-RPT-001). Verify: `npm run check:registers`, `npm run check:seeds`, `npm run check:schema-l10n`, PHPUnit `ProviderPayoutSchemaTest`.
- [x] 1.2 Move `<version>` in `appinfo/info.xml` and record the schema in the register lock. Verify: `RegisterVersionMovesAppVersionTest`.

## 2. Page

- [x] 2.1 Provider payouts tab in `receivables-tabs`, counted from its list (REQ-RPT-001). Verify: vitest `receivablesBoard.spec.js`, `npm run check:manifest`.
