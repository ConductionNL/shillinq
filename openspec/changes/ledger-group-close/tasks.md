# Tasks: ledger-group-close

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Build

- [x] 1.1 In `lib/Service/SoftCloseExecutor.php` call `IntercompanyMatchingService::matchRelationPeriod` per relation of the administration, and add an eliminations index page in `src/manifest.d/` (REQ-ICE-011). Verify: PHPUnit red first with the real service; `npm run check:manifest`.
- [ ] 1.2 A consolidation run action on `ConsolidationPeriod` over `ConsolidationMappingService` that sums the members and applies the eliminations (REQ-CONS-011). Verify: PHPUnit for the two-administration scenario.
  - Open: needs a read of each member administration's mapped balances per account and period; no service provides one yet (ConsolidationMappingService only rewrites account numbers).
- [ ] 1.3 Run `FluxService` after the nightly soft close in `SoftCloseJob` and show the latest run on the flux analysis page (REQ-CLS-011). Verify: PHPUnit; Playwright sees a flagged account.
  - Open: `FluxService::run` takes the accounts with their actual and basis amounts from its caller; the nightly job needs the same per-account movement read as 1.2 to build them.
- [ ] 1.4 Live: two seed administrations with an intercompany sale; after the soft close see the elimination and the consolidated figures.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
