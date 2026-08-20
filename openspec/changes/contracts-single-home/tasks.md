# Tasks — contracts-single-home

## 1. HEAD re-verification (before any code)
- [x] Re-confirm `SettingsService::deepMergeConfig()` behaviour against HEAD
      (list-array concat, keyed-object recurse, scalar overwrite) by dumping
      the merged config for `components.schemas.Contract` and diffing it
      against the description in design.md §D1 — the design must match the
      code, not the other way round.
      **Confirmed**: code at `lib/Service/SettingsService.php:1563-1582`
      matches design.md §D1 exactly (list arrays `array_merge`d, keyed
      objects recursed, scalars overwritten). Live-instance schema id 41
      (slug `Contract`, register `shillinq`/264) carried the literal
      12-entry concatenated `required` array described in the proposal,
      confirming the defect was live, not hypothetical.
- [x] Query the OR API (or the live dev instance's object list) for any
      **operator-created** `Contract` objects whose shape matches the
      IFRS-15 field set (`customerId`/`fixedConsideration`/`lifecycleState`
      present), as distinct from the register.d fragment seed objects. This
      determines whether the migrator's live run has real rows to move
      (design.md §D8-2).
      **Confirmed via OR API (http://localhost:8080, admin:admin)**: exactly
      1 live object under schema id 41 (slug `Contract`) in the shillinq
      register (264) — `contract-handoff-demo-2026`
      (id `f1b0a910-be6a-4858-9682-825ba89b1b2f`), the
      `semantic-invoice-consume.json` fragment seed. It is CLM-shaped
      (`contractType=sales`, `status=draft` present) with IFRS-15 leftover
      fields, so per the migrator's discriminator it correctly stays under
      `Contract` — 0 genuine IFRS-15-shaped `Contract` objects exist live.
      The register `2425` (shillinq-default) has 0 `Contract` objects. The
      IFRS-15 fragment's own 4 seed objects and CLM's 3 demo seeds do not
      appear to have been imported into this instance at all (0 objects
      under `PerformanceObligation`/`TransactionPrice`/etc.) — the migrator's
      live run has no real rows to move on this instance.
- [x] Check whether `RevenueContract` collides (case-insensitively,
      instance-wide) with any other installed app's schema slug on the
      shared dev instance, per the `abstract-order-primitive` `Order`/
      `OrderPrimitive` precedent (design.md §D8-1). If it collides, pick a
      different name and update every artifact in this change before
      shipping.
      **Confirmed no collision**: `GET .../api/schemas/RevenueContract` →
      404 "Schema not found"; a full paginated scan of all installed schemas
      (15 pages, 200/page) found no slug matching `revenuecontract`
      case-insensitively anywhere on the instance.

## 2. Schema rename (register.d)
- [x] In `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json`: rename
      `components.schemas.Contract` → `components.schemas.RevenueContract`,
      `"slug": "Contract"` → `"slug": "RevenueContract"`, update `title` and
      `description` to say "revenue contract" where they currently say
      "contract" ambiguously; update `x-spec` paths if they encode the slug.
      `x-spec` paths point at the change/spec directory, not the schema
      slug — no edit needed there.
- [x] In the same file, retarget the 4 seed objects whose `@self.schema` is
      `"Contract"` to `"RevenueContract"`.
- [x] Update the 10 FK-description strings across `PerformanceObligation.
      contractId`, `TransactionPrice.contractId`, `PriceAllocation.
      contractId`, `RevenueRecognitionEvent.contractId`, `ContractAsset.
      contractId`, `ContractLiability.contractId`, `ContractModification.
      parentContractId`, `VariableConsiderationAdjustment.contractId`,
      `ContractCostAsset.contractId`, `RevenueWaterfall.contractId` — field
      *names* stay the same, only the prose "FK to the parent Contract" /
      "FK to the Contract" becomes "FK to the parent RevenueContract" /
      "FK to the RevenueContract".
- [x] Confirm `lib/Settings/register.d/contract-lifecycle-management.json`
      needs no edits (CLM's `Contract` is already the canonical shape) —
      re-read it after the IFRS-15 rename to confirm its
      `x-openregister-lifecycle`/`required`/`x-openregister-notifications`
      no longer share a merge target with a second full definition.
      Confirmed no edits needed; verified via the new
      `tests/validate-registers.js` check (§5) reporting exactly one full
      `Contract` definition post-fix.
- [x] In `lib/Settings/register.d/semantic-invoice-consume.json`: no schema
      key change (still `"Contract"`, still targets CLM). Remove
      `customerId`, `signedAt`, `fixedConsideration`, `lifecycleState` from
      the `contract-handoff-demo-2026` seed object (IFRS-15 leftovers that
      only existed because of the merged `required` list) and confirm the
      remaining fields still satisfy CLM's `required` (`contractNumber`,
      `title`, `contractType`, `status` — all already present).
      `configuration.implements: ["https://openregister.app/ns#Contract"]`
      is unchanged.
      Also fixed a discovered runtime dependency on the old slug beyond the
      register.d files: `lib/Service/RevenueCutoffService.php::fetchContracts()`
      called `setSchema('Contract')` for the REQ-IFRS15-007 nightly cut-off
      — updated to `setSchema('RevenueContract')` (not in the original D4
      inventory; found by grepping `setSchema('Contract')` across `lib/`).
      Likewise `lib/Portal/PortalContributionProvider.php`'s customer-portal
      `contracts` collection used `'schema' => 'Contract'` with
      `scopeField: 'customerId'` — a field only IFRS-15's shape has — so it
      was silently riding the same merge ambiguity; retargeted to
      `RevenueContract` (also not in the original D4 inventory).

## 3. Manifest rename (manifest.d)
- [x] In `src/manifest.d/bookkeeping-ifrs15-revenue.json`: change every
      `page.config.schema` value from `"Contract"` to `"RevenueContract"` —
      the `RevenueContracts` index page, the `ContractDetail` page, and any
      `relatedLists[].schema` / `sidebarProps` widget `props.schema` that
      names `"Contract"` for the file-references and other data widgets.
      Menu `id`/`label`/`route`/`order` values are untouched (owned by
      `nav-six-clusters`).
      3 occurrences changed (index `config.schema`, detail `config.schema`,
      and the `openregister-file-references` sidebar widget's
      `props.schema`) — verified by grep the exact count of literal
      `"Contract"` schema-value occurrences in this file was 3, not the
      "six pages" figure in the proposal/design prose (which counts the six
      menu-linked pages this fragment owns overall, not literal schema-value
      occurrences). Also regenerated `src/manifest.d.shell.json` via
      `node scripts/generate-manifest-shell.js` — pre-existing drift from
      the `origin/development` merge (unrelated fragments changed after the
      shell was last generated), not caused by this change; the shell has no
      `schema` key so the rename itself contributes 0 bytes there.

## 4. Data migration
- [x] Add `RevenueContractRenameMigrator` (unit-tested, mirrors
      `SubsidieOrderConsolidationMigrator`'s shape): `mapObjectToRenamed
      Schema()` re-points `@self.schema` from `Contract` to
      `RevenueContract` for objects whose properties match the IFRS-15
      shape; `assertCountsMatch()` count-abort guard (source count ===
      target count post-move, abort with source intact on mismatch).
      `lib/Service/Migration/RevenueContractRenameMigrator.php`.
- [x] Unit test `RevenueContractRenameMigratorTest` covering: the
      discriminator correctly separates CLM-shaped vs IFRS-15-shaped
      `Contract` objects, the count-abort fires on any mismatch, and a
      second idempotent run no-ops.
      `tests/Unit/Service/RevenueContractRenameMigratorTest.php` — 13 tests,
      including the CLM-fields-take-precedence case (the
      `contract-handoff-demo-2026` shape) and idempotent-second-run.
- [x] Register the migrator in a repair step (mirroring
      `InitializeSettings`'s existing pattern), run-once, idempotent.
      `InitializeSettings::migrateRevenueContractObjects()`, called from
      `run()` right after `seedDefaultAdministration()`; fetches
      `Contract`-schema objects, discriminates via the migrator, and for
      each IFRS-15-shaped match `saveObject()`s it under `RevenueContract`
      (same uuid) then `deleteObject()`s the source row — wrapped so a
      count-mismatch or per-object failure is reported but never aborts the
      wider repair run.
- [x] Live-run verification: deferred to a live import per design.md §D3-4,
      with the `@e2e exclude` reasoning captured directly in the spec
      scenario (see §6 below) rather than silently skipped.
      Confirmed via the OR API check in §1 above: 0 live IFRS-15-shaped
      `Contract` objects exist on the shared dev instance today, so the
      repair step's live run is a true no-op there; its object-moving code
      path itself is exercised only by the unit-tested pure core
      (`migrateBatch`/`mapObjectToRenamedSchema`), not by an integration
      test against a live OpenRegister — consistent with the precedent's
      own deviation note.

## 5. Validator gate
- [x] Extend `tests/validate-registers.js` with a same-slug full-definition
      collision check: for every `components.schemas` key, flag when 2+
      source files each declare a body containing both `type` and
      `required` (design.md §D5). Error message names the colliding files,
      mirroring the existing `checkSlugCaseCollisions` output format.
      Scoped to `register.d` fragment files only (excludes the monolith
      `shillinq_register.json`), matching the Requirement's own wording
      ("two or more `register.d` source files") — see the report for why:
      including the monolith surfaced ~60 unrelated pre-existing
      collisions. Also added `PRE_EXISTING_SAME_SLUG_COLLISIONS`, an
      explicit ADR-020-style allowlist (mirroring this file's own
      `NON_BOOKKEEPING` pattern) of 20 already-existing fragment-vs-fragment
      collisions discovered the moment the check was switched on, each with
      a one-line cause comment — genuine pre-existing debt, out of this
      change's scope, that would otherwise hard-fail every future PR.
      `Contract` is deliberately NOT in that allowlist (it's the one this
      change fixes).
- [x] Run `node tests/validate-registers.js` before and after the rename:
      before = the new check fails on `Contract` (2 files); after = it
      passes (`Contract` has 1 full definition, `RevenueContract` has 1).
      Verified via a temporary revert-then-restore of the two edited
      register.d files (not a git operation — plain file edits, confirmed
      byte-identical to the fixed version after restoring): before-state
      exit code 1, reporting ONLY
      `"Contract" (slug "Contract") is fully declared by 2 files:
      bookkeeping-ifrs15-revenue.json, contract-lifecycle-management.json`
      (the 20 allowlisted collisions correctly suppressed); after-state
      exit code 0, `PASS — no NEW components.schemas key is declared as a
      full definition ... by 2+ register.d files`. Full outputs in the
      report.

## 6. Tests
- [x] Update `tests/Unit/Service/Ifrs15RevenueFragmentTest.php` — the
      literal `'Contract'` slug assertions (verified at lines 60, 144, 274)
      become `'RevenueContract'`.
- [x] Update `tests/Unit/Service/RevenueCutoffServiceTest.php` and
      `tests/Integration/Ifrs15RevenueIntegrationTest.php`'s schema-slug
      references the same way.
      While updating the integration test, found and fixed a pre-existing,
      unrelated wiring bug (not caused by this change, confirmed
      byte-identical against `origin/development`): `buildService()` built
      an in-memory `ObjectService` stub but injected a fresh, unconfigured
      `$this->createMock(ObjectServiceInterface::class)` into
      `RevenueCutoffService` instead — the exact ADR-083/ADR-084 double-drift
      pattern `DuckObjectServiceAdapter`'s own docblock documents.
      Reproduced red first — running the file directly (it is not wired
      into `phpunit-unit.xml`'s testsuite) showed 2 of 6 tests failing
      (e.g. `assertSame(1, $result['total'])` failing "0 is identical to
      1") because the injected mock answered every read empty regardless of
      the `$data` fixture. Fixed by wrapping the stub in
      `DuckObjectServiceAdapter`; confirmed green (6/6). Left the file OUT
      of `phpunit-unit.xml` (a deliberate,
      commented pre-existing scope decision for that config, not something
      this change should silently expand) — flagged in the report instead.
- [x] Spot-check `docs/user-guide/bookkeeping/contracts-and-pos.md` and
      `docs/user-guide/bookkeeping/contract-balances.md` for prose that
      needs "Contract" → "RevenueContract" (not a mechanical rename — read
      each occurrence in context).
      `contract-balances.md` needed no changes (no schema-slug references,
      only generic lowercase "contract" prose). `contracts-and-pos.md`
      updated: the nav breadcrumb, the "fresh `Contract` row" FK reference,
      and the intro clarified to name `RevenueContract` vs the generic
      `Contract`. Also fixed 2 further docs discovered stale by this rename
      (not in the original spot-check list, but directly invalidated by it):
      `docs/user-guide/bookkeeping/revenue-recognition-ifrs15.md` and
      `docs/api/revenue-recognition.md` (both had literal `` `Contract` ``
      schema references describing the IFRS-15 lifecycle/register).
      `docs/Integrations/semantic-handoff.md`'s "Current limits" section
      also described the pre-fix "merged Contract/ARInvoice" state as
      current fact — updated to say the Contract-side of that debt is now
      resolved, only the ARInvoice-side remains open (matching the
      semantic-invoice-consume spec delta's own NOTE).
- [x] New/extended gate-19 e2e (`tests/e2e/contracts-single-home.spec.ts`
      or an extension of `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts`),
      traceable to `specs/contracts-single-home/spec.md`'s two `@e2e` tags:
      - `contracts-single-home::clm-contracts-index-and-detail-render` —
        CLM `/contracts` index + detail render (new coverage — none exists
        today).
      - `contracts-single-home::revenue-contracts-index-and-detail-render-post-rename` —
        `/ifrs-15/contracts` index + `ContractDetail` render post-rename.
      Kind-discovery assertion if OR exposes one cheaply, else `@e2e
      exclude` with the reason recorded in the spec scenario.
      Written as a new file `tests/e2e/contracts-single-home.spec.ts`
      (index routes already deep-link-smoke-tested by `NavSixClusters.spec.js`;
      this file adds the previously-uncovered `:id` DETAIL routes for both
      schemas). NOT executed per the implementer's instructions (TypeScript
      syntax-checked with `tsc --noEmit` only). The kind-discovery scenario
      needed no new test: `semantic-invoice-consume`'s own spec delta
      already marks REQ-SIC-001's resolution scenario `@e2e exclude`
      (server-side, no UI surface) — confirmed OR does expose a cheap
      endpoint (`GET /api/schemas/resolve-by-implements`, tested live,
      currently `{"resolved":false}` because this fix is not yet imported
      into the running instance), but per the already-written spec delta
      this stays a server-side/unit-test concern, not a new e2e.
- [x] `openspec validate contracts-single-home --strict` passes.

## 7. Report
- [x] Byte/nav impact: confirm zero route/menu/manifest-byte-budget delta
      (only `schema` field values change inside existing page configs);
      record the actual before/after manifest byte count in the change's
      final report.
      Routes/menu: byte-identical (confirmed via `checkSlugCaseCollisions`-
      style before/after revert). Manifest bytes: before 1,094,118B, after
      1,094,139B (Δ+21B — three `"Contract"`→`"RevenueContract"` string
      substitutions in `manifest.d/`, 7 bytes each), budget 1,126,300B —
      both states PASS with wide headroom.
- [x] Hand back the pipelinq task list (design.md §D6) to the orchestrator
      for the cross-repo follow-up — no pipelinq artifacts are authored by
      this change.
      See the final report for the refreshed (re-verified against pipelinq
      HEAD) task list.
