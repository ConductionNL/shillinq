# Tasks — contracts-single-home

## 1. HEAD re-verification (before any code)
- [ ] Re-confirm `SettingsService::deepMergeConfig()` behaviour against HEAD
      (list-array concat, keyed-object recurse, scalar overwrite) by dumping
      the merged config for `components.schemas.Contract` and diffing it
      against the description in design.md §D1 — the design must match the
      code, not the other way round.
- [ ] Query the OR API (or the live dev instance's object list) for any
      **operator-created** `Contract` objects whose shape matches the
      IFRS-15 field set (`customerId`/`fixedConsideration`/`lifecycleState`
      present), as distinct from the register.d fragment seed objects. This
      determines whether the migrator's live run has real rows to move
      (design.md §D8-2).
- [ ] Check whether `RevenueContract` collides (case-insensitively,
      instance-wide) with any other installed app's schema slug on the
      shared dev instance, per the `abstract-order-primitive` `Order`/
      `OrderPrimitive` precedent (design.md §D8-1). If it collides, pick a
      different name and update every artifact in this change before
      shipping.

## 2. Schema rename (register.d)
- [ ] In `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json`: rename
      `components.schemas.Contract` → `components.schemas.RevenueContract`,
      `"slug": "Contract"` → `"slug": "RevenueContract"`, update `title` and
      `description` to say "revenue contract" where they currently say
      "contract" ambiguously; update `x-spec` paths if they encode the slug.
- [ ] In the same file, retarget the 4 seed objects whose `@self.schema` is
      `"Contract"` to `"RevenueContract"`.
- [ ] Update the 10 FK-description strings across `PerformanceObligation.
      contractId`, `TransactionPrice.contractId`, `PriceAllocation.
      contractId`, `RevenueRecognitionEvent.contractId`, `ContractAsset.
      contractId`, `ContractLiability.contractId`, `ContractModification.
      parentContractId`, `VariableConsiderationAdjustment.contractId`,
      `ContractCostAsset.contractId`, `RevenueWaterfall.contractId` — field
      *names* stay the same, only the prose "FK to the parent Contract" /
      "FK to the Contract" becomes "FK to the parent RevenueContract" /
      "FK to the RevenueContract".
- [ ] Confirm `lib/Settings/register.d/contract-lifecycle-management.json`
      needs no edits (CLM's `Contract` is already the canonical shape) —
      re-read it after the IFRS-15 rename to confirm its
      `x-openregister-lifecycle`/`required`/`x-openregister-notifications`
      no longer share a merge target with a second full definition.
- [ ] In `lib/Settings/register.d/semantic-invoice-consume.json`: no schema
      key change (still `"Contract"`, still targets CLM). Remove
      `customerId`, `signedAt`, `fixedConsideration`, `lifecycleState` from
      the `contract-handoff-demo-2026` seed object (IFRS-15 leftovers that
      only existed because of the merged `required` list) and confirm the
      remaining fields still satisfy CLM's `required` (`contractNumber`,
      `title`, `contractType`, `status` — all already present).
      `configuration.implements: ["https://openregister.app/ns#Contract"]`
      is unchanged.

## 3. Manifest rename (manifest.d)
- [ ] In `src/manifest.d/bookkeeping-ifrs15-revenue.json`: change every
      `page.config.schema` value from `"Contract"` to `"RevenueContract"` —
      the `RevenueContracts` index page, the `ContractDetail` page, and any
      `relatedLists[].schema` / `sidebarProps` widget `props.schema` that
      names `"Contract"` for the file-references and other data widgets.
      Menu `id`/`label`/`route`/`order` values are untouched (owned by
      `nav-six-clusters`).

## 4. Data migration
- [ ] Add `RevenueContractRenameMigrator` (unit-tested, mirrors
      `SubsidieOrderConsolidationMigrator`'s shape): `mapObjectToRenamed
      Schema()` re-points `@self.schema` from `Contract` to
      `RevenueContract` for objects whose properties match the IFRS-15
      shape; `assertCountsMatch()` count-abort guard (source count ===
      target count post-move, abort with source intact on mismatch).
- [ ] Unit test `RevenueContractRenameMigratorTest` covering: the
      discriminator correctly separates CLM-shaped vs IFRS-15-shaped
      `Contract` objects, the count-abort fires on any mismatch, and a
      second idempotent run no-ops.
- [ ] Register the migrator in a repair step (mirroring
      `InitializeSettings`'s existing pattern), run-once, idempotent.
- [ ] Live-run verification: deferred to a live import per design.md §D3-4,
      with the `@e2e exclude` reasoning captured directly in the spec
      scenario (see §6 below) rather than silently skipped.

## 5. Validator gate
- [ ] Extend `tests/validate-registers.js` with a same-slug full-definition
      collision check: for every `components.schemas` key, flag when 2+
      source files each declare a body containing both `type` and
      `required` (design.md §D5). Error message names the colliding files,
      mirroring the existing `checkSlugCaseCollisions` output format.
- [ ] Run `node tests/validate-registers.js` before and after the rename:
      before = the new check fails on `Contract` (2 files); after = it
      passes (`Contract` has 1 full definition, `RevenueContract` has 1).

## 6. Tests
- [ ] Update `tests/Unit/Service/Ifrs15RevenueFragmentTest.php` — the
      literal `'Contract'` slug assertions (verified at lines 60, 144, 274)
      become `'RevenueContract'`.
- [ ] Update `tests/Unit/Service/RevenueCutoffServiceTest.php` and
      `tests/Integration/Ifrs15RevenueIntegrationTest.php`'s schema-slug
      references the same way.
- [ ] Spot-check `docs/user-guide/bookkeeping/contracts-and-pos.md` and
      `docs/user-guide/bookkeeping/contract-balances.md` for prose that
      needs "Contract" → "RevenueContract" (not a mechanical rename — read
      each occurrence in context).
- [ ] New/extended gate-19 e2e (`tests/e2e/contracts-single-home.spec.ts`
      or an extension of `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts`),
      traceable to `specs/contracts-single-home/spec.md`'s two `@e2e` tags:
      - `contracts-single-home::clm-contracts-index-and-detail-render` —
        CLM `/contracts` index + detail render (new coverage — none exists
        today).
      - `contracts-single-home::revenue-contracts-index-and-detail-render-post-rename` —
        `/ifrs-15/contracts` index + `ContractDetail` render post-rename.
      Kind-discovery assertion if OR exposes one cheaply, else `@e2e
      exclude` with the reason recorded in the spec scenario.
- [ ] `openspec validate contracts-single-home --strict` passes.

## 7. Report
- [ ] Byte/nav impact: confirm zero route/menu/manifest-byte-budget delta
      (only `schema` field values change inside existing page configs);
      record the actual before/after manifest byte count in the change's
      final report.
- [ ] Hand back the pipelinq task list (design.md §D6) to the orchestrator
      for the cross-repo follow-up — no pipelinq artifacts are authored by
      this change.
