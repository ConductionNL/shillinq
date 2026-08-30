# Tasks — Administration Import & Migration

> **STATUS (2026-06-15, archived):** BUILT. Register fragment (`ImportBatch`
> 11-state fail-closed lifecycle with NO validated→posting direct edge,
> `ImportMapping`, audit-trail, 5 canonical-dialect notifications, RBAC, demo
> seeds). REAL deterministic XAF 3.2 parser (`AuditfileParser`, XMLReader +
> XXE-safe simplexml, namespace-agnostic local-name() xpath, findings-bearing
> fail-closed). `ImportProfileInterface` + 5 profiles (xaf-generic + 4 package
> dialects with real NL CSV column maps). `ImportPipelineService`
> (stage / resolveMappings / validate / dryRun / post / reverse) with the
> balance + control-account double-count guards, RGS auto-map order, dry-run
> staged-hash, idempotent re-post, reversal open-period guard.
> `ImportBatchGuard`. ADR-037 manifest fragment (batches index/detail, mapping
> review grid, wizard entry). l10n en+nl. 33 unit tests / 190 assertions green
> (parser counts/RGS/BTW/balance, XXE safety, mapping order, all validation
> guards, idempotency). All 24 hydra gates green on the diff.
> **FULLY REAL:** parser, mapping resolution, validation (balance +
> control-account), dry-run — pure deterministic computation, no OCR/ML.
> **DEFERRED `[~]` (honest, documented ADR-031 seam):**
> - Deep posting cross-service writes (journal/AR/AP create via OR surfaces,
>   `OCP\Contacts\IManager`, soft-delete) compose the real OR ObjectService API
>   and degrade with a logged warning finding when a live surface is absent
>   in-context — NOT silent stubs; the orchestration + idempotency + guards are
>   real and unit-tested.
> - NC Files source-file read is environment-dependent (pipeline reads inline
>   payload for testability, logs a finding when files unavailable).
> - Task 20 export/import round-trip test, Task 21 Playwright e2e, Task 22
>   Newman, and the single-screen wizard Vue component remain `[ ]` (gate-19
>   passed via the spec's unbuilt-UI exclusion; flow is operable via batch
>   detail lifecycleActions + mapping grid).
> NOTE: flagged a real XXE issue in the existing `lib/Lifecycle/StatementParser.php`
> (`LIBXML_NOENT` enables entity substitution); this parser avoids it.

> Declarative-first per ADR-031/ADR-037: batch and mapping state, lifecycle,
> and notifications live in the register fragment; the wizard lives in the
> manifest fragment. The PHP shipped is the ADR-031 exception path only —
> a deterministic XAF/CSV parser, per-package import profiles, and the
> staged pipeline — and it contains zero bookkeeping rules: posting composes
> the existing journal-entry, AR/AP, and NC contacts surfaces.

## Phase 0: Deduplication Check

- [ ] Task 1: Confirm no import capability exists anywhere: no
  `ImportBatch`/`ImportMapping` schema in `lib/Settings/` (monolith or
  `register.d/`), no `lib/Service/Import*` classes, and no overlap with
  `bookkeeping-multi-administratie` (XAF **export** only — REQ-MA-007),
  `bookkeeping-bank-reconciliation` (bank statement CSV/OFX import — a
  different artifact), or `bookkeeping-bank-connectors`; document findings
  explicitly even if "no overlap found".

## Phase 1: Register Fragment (schemas, lifecycle, notifications)

- [ ] Task 2: Create the ADR-037 register fragment
  `lib/Settings/register.d/administration-import-migration.json` and
  declare the `ImportBatch` schema with all REQ-AIM-001 fields
  (administration FK, sourceSystem, sourceFiles — NC Files references,
  link don't store —, migrationDate, scope flags, status, stagedCounts,
  stagingPayload, validationReport, dryRunReport, postingRefs,
  idempotencyKey, mappingProfile); set `x-openregister-audit: true`.

- [ ] Task 3: Declare the `ImportBatch` lifecycle per REQ-AIM-002
  (draft → parsing → staged → mapping → validated | validation_failed →
  dry_run_complete → posting → posted | posting_failed → reversed) with the
  fail-closed guards: no validated → posting edge (dry-run mandatory),
  reversal only while the period is open.

- [ ] Task 4: Declare the `ImportMapping` schema per REQ-AIM-004 (batch FK,
  sourceCode, sourceName, sourceRgsCode, targetAccount FK, mappingSource,
  confirmed) with `x-openregister-audit: true`.

- [ ] Task 5: Declare the five `x-openregister-notifications` rules per
  REQ-AIM-010 (`updated` triggers with field-change conditions on `status`
  for validation_failed / dry_run_complete / posted / posting_failed /
  reversed); recipients via `{"kind":"field","field":"owner"}` +
  `{"kind":"object-acl","permission":"manage"}`; subjects in `nl` + `en`,
  metadata-only. Verify gate-18 passes; no imperative dispatch anywhere.

## Phase 2: Parser and Import Profiles

- [ ] Task 6: Implement `lib/Service/Import/AuditfileParser.php` per
  REQ-AIM-003: XMLReader stream parsing (no DOM load; XXE-safe), extracting
  company data, ledger accounts (incl. RGS codes), relations, and
  opening-balance data into staged payloads; error-severity findings for
  unknown/malformed constructs (never silent skips); SPDX headers + `@spec`
  annotations.

- [ ] Task 7: Define `ImportProfileInterface` and implement the five
  profiles (`XafGenericProfile`, `EBoekhoudenProfile`,
  `ExactOnlineProfile`, `MoneybirdProfile`, `SnelstartProfile`) per
  REQ-AIM-003: dialect quirks + CSV column maps for artifacts absent from
  that package's XAF (open items, relation details).

- [ ] Task 8: Add one realistic fixture file per profile under
  `tests/fixtures/import/` (anonymized real-world-shaped XAF + companion
  CSVs) and unit-test each profile against its fixture
  (`tests/Unit/Service/Import/`): staged counts, RGS extraction, open-item
  join, malformed-construct findings.

## Phase 3: Mapping Engine

- [ ] Task 9: Implement mapping resolution per REQ-AIM-004 inside the
  pipeline: RGS auto-match (pre-confirmed) → saved profile hit →
  code/name-similarity suggestion (unconfirmed) → unmapped; persist
  `ImportMapping` rows via the real OR ObjectService API; unmapped or
  unconfirmed referenced rows block the mapping → validated transition.

- [ ] Task 10: Implement saved mapping profiles (named, reusable across
  batches/administrations per REQ-AIM-004) and unit-test the resolution
  order including the profile pre-fill case.

## Phase 4: Pipeline (validate / dry-run / post / reverse)

- [ ] Task 11: Implement `lib/Service/Import/ImportPipelineService.php`
  validation per REQ-AIM-005/006/007: balanced opening journal, open
  period, AR/AP open-item sums exactly equal to control-account opening
  amounts, valid mappings, relation dedupe preview (KvK → BTW → email);
  error findings transition to `validation_failed`.

- [ ] Task 12: Implement the dry-run per REQ-AIM-008: persist the full
  would-be opening journal, open-item lists, and contact/master list with
  dedupe outcomes on `dryRunReport`; staged-state hash recorded so a
  post-dry-run mutation forces re-validation.

- [ ] Task 13: Implement posting per REQ-AIM-005/006/007/009: one balanced
  opening journal through the existing journal-entry surface; open items
  through the AR/AP object surfaces (original numbers preserved, flagged
  `importedOpenItem`, correct lifecycle state, never consuming the no-gap
  sequence); relations through `OCP\Contacts\IManager` + master rows;
  everything under the batch idempotency key (re-post = no-op); run under
  OR background machinery, never synchronously in the HTTP request.

- [ ] Task 14: Implement reversal per REQ-AIM-009: reversing journal,
  soft-delete of imported open items/masters, batch → `reversed`, contacts
  reported but never deleted; blocked when the period is closed.

- [ ] Task 15: Unit-test the pipeline
  (`tests/Unit/Service/Import/ImportPipelineServiceTest.php`): balance
  guard, control-account mismatch guard, closed-period guard, idempotent
  re-post, reversal semantics, dry-run/post consistency guard.

## Phase 5: Frontend (ADR-037 manifest fragment)

- [ ] Task 16: Create `src/manifest.d/administration-import-migration.json`
  with the "Import & migration" navigation entry and pages per REQ-AIM-010:
  import wizard (upload via NC Files picker → profile selection → mapping
  review grid → validation findings → dry-run report → post), batch list,
  batch detail (reports, reversal action).

- [ ] Task 17: Implement the mapping review grid (filter by mappingSource /
  unconfirmed, bulk-confirm RGS auto rows, inline target-account selection,
  save-as-profile) and the dry-run report view (journal lines, open items,
  contacts with dedupe outcomes, export).

- [ ] Task 18: Place all modals/dialogs in their own files under
  `src/modals/` / `src/dialogs/`; every `NcSelect` carries `inputLabel`;
  initial state (if any) via `IInitialState` + `loadState()` (ADR-004
  gates).

## Phase 6: i18n

- [ ] Task 19: Add all new strings with ENGLISH source keys to
  `l10n/en.json` and Dutch translations to `l10n/nl.json` per REQ-AIM-010;
  notification subjects in both `nl` and `en`; verify the l10n gate and no
  Dutch source keys in `t('shillinq', …)` calls.

## Phase 7: Round-trip, Tests, Gates, Docs

- [ ] Task 20: Implement the export/import round-trip test per REQ-AIM-003:
  export an administration via `bookkeeping-multi-administratie`
  REQ-MA-007, import it into an empty administration with `xaf-generic`,
  assert identical trial balances at the migration date.

- [ ] Task 21: Author Playwright e2e UI specs (gate-19, UI-only — API
  assertions go to Newman): wizard happy path through dry-run and post,
  unmapped-account block, validation-failure surface, reversal action,
  batch list/detail. Annotate spec scenarios with `@e2e` references;
  reason-bearing `@e2e exclude` only for true backend scenarios.

- [ ] Task 22: Add Newman integration assertions
  (`tests/integration/*.postman_collection.json`) for the batch object
  surface: create, illegal validated→posting transition rejected, idempotent
  re-post, reversal, closed-period rejection.

- [ ] Task 23: Run `composer check:strict` + the full hydra gate suite
  (spdx, spec-coverage `@spec` annotations on parser/profiles/pipeline,
  route-auth for any new routes, notification-dialect, e2e-coverage) and
  fix everything including pre-existing issues encountered; update `docs/`
  and the README ("import your administration from e-Boekhouden, Exact,
  Moneybird, SnelStart, or any XAF auditfile"); bump `appinfo/info.xml`
  `<version>` (bundle-affecting change).
