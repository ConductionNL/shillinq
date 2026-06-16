# Tasks — Member 12: docs + quality

Sourced from the giant's Phase 7 (Documentation & Cleanup) and Phase 8
(CI/CD & Quality Gates).

## Documentation

- [x] Add PHPDoc to `ComplianceService.php` with `@spec` tag
- [x] Add Vue component JSDoc (description, props, events) to the BBV components
- [x] Add a README snippet explaining the BBV variant scope and usage

## Deduplication check (ADR-012)

- [x] Verify no duplicate GL-account linkage implementation elsewhere in Shillinq
- [x] Verify no existing compliance dashboard or budget-mapping UI
- [x] Verify the aggregation logic is not reimplemented in another spec

## Code style & linting

- [x] Run `composer check:strict` — all checks pass
- [x] Run `npm run lint` — all Vue/JS checks pass
- [x] Verify SPDX headers on all new files (inside the main docblock)
- [x] Verify translation-key consistency

## Hydra mechanical gates

- [x] `hydra-gate-route-auth` — all routes have proper auth attributes
- [x] `hydra-gate-semantic-auth` — auth attributes match body requirements
- [x] `hydra-gate-nc-input-labels` — form inputs have associated labels
- [x] `hydra-gate-modal-isolation` — all modals in separate files
- [x] All other Hydra gates pass with zero findings
