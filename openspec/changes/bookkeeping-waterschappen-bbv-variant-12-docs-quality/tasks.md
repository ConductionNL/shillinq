# Tasks — Member 12: docs + quality

Sourced from the giant's Phase 7 (Documentation & Cleanup) and Phase 8
(CI/CD & Quality Gates).

## Documentation

- [ ] Add PHPDoc to `ComplianceService.php` with `@spec` tag
- [ ] Add Vue component JSDoc (description, props, events) to the BBV components
- [ ] Add a README snippet explaining the BBV variant scope and usage

## Deduplication check (ADR-012)

- [ ] Verify no duplicate GL-account linkage implementation elsewhere in Shillinq
- [ ] Verify no existing compliance dashboard or budget-mapping UI
- [ ] Verify the aggregation logic is not reimplemented in another spec

## Code style & linting

- [ ] Run `composer check:strict` — all checks pass
- [ ] Run `npm run lint` — all Vue/JS checks pass
- [ ] Verify SPDX headers on all new files (inside the main docblock)
- [ ] Verify translation-key consistency

## Hydra mechanical gates

- [ ] `hydra-gate-route-auth` — all routes have proper auth attributes
- [ ] `hydra-gate-semantic-auth` — auth attributes match body requirements
- [ ] `hydra-gate-nc-input-labels` — form inputs have associated labels
- [ ] `hydra-gate-modal-isolation` — all modals in separate files
- [ ] All other Hydra gates pass with zero findings
