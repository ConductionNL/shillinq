# Design — Member 12: docs + quality

## Scope

This `kind: code` member (chain tail) documents the BBV capability,
verifies deduplication, and runs the strict quality + Hydra gate suite.
It adds no new feature behaviour.

## Decisions carried from the giant (Phases 7–8)

- Documentation: PHPDoc with `@spec` tags, Vue JSDoc, README snippet.
- Deduplication (ADR-012): confirm no second GL-account-linkage,
  compliance dashboard, or budget-mapping UI exists in Shillinq; confirm
  the aggregation is not reimplemented in another spec.
- Quality: `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan),
  `npm run lint`, SPDX headers, translation-key consistency.
- Hydra gates: route-auth, semantic-auth, nc-input-labels,
  modal-isolation, and the full mechanical suite to zero findings.

## Reuse

No new code surface — this member consumes the gates and lint tooling
that already exist in the repo and the Hydra container.

## Security (ADR-005)

The gate pass is itself a security control: route-auth + semantic-auth +
no-admin-idor confirm the BBV endpoints carry correct authorisation; the
SPDX header in the main docblock (not line comments) satisfies licensing
without weakening any check.
