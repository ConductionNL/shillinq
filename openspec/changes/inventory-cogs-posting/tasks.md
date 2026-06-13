# Tasks — Inventory Auto-post COGS + Inventory-asset GL Entries

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `inventory-cogs-posting`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `inventory-cogs-posting` capability spec already exists,
  no `InventoryGLConfig` schema is declared in `lib/Settings/shillinq_register.json`,
  and no `lib/Service/Cogs*.php`, `lib/Service/InventoryGL*.php`, or
  `lib/Service/InventoryPosting*.php` PHP classes are present
  (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/inventory-cogs-posting/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` /
  `Depends on: bookkeeping-general-ledger, inventory-valuation-fifo-avg` header,
  `REQ-CG-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks
  with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline; reference the 16/22
  competitor demand evidence from the context brief

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and
  including Affected Projects / Scope / Risks (GR/IR two-step, conditional variance
  direction, unitCost null-guard) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (lifecycle action on
  InventoryValuation), D2 (InventoryGLConfig for account mapping), D3 (GR/IR two-step
  to avoid double-posting with AP), D4 (variance direction), D5 (glTransactionId
  back-reference); include Dutch seed data (3 InventoryValuation examples, 2
  InventoryGLConfig examples, 3 GLTransaction examples)

- [x] Task 5: Declare the `InventoryGLConfig` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CG-001 fields
  (`administrationId`, `cogsAccountNumber`, `inventoryAssetAccountNumber`,
  `grIrClearingAccountNumber`, `inventoryAdjustmentAccountNumber`, `isActive`,
  `description`); add FK validation rule asserting all four account numbers
  resolve to existing `Account` records in the same administration

- [x] Task 6: Extend the `InventoryValuation` schema in
  `lib/Settings/shillinq_register.json` with `glTransactionId` (back-reference
  to the materialised `GLTransaction`) and `postingEvent` enum
  (`saleDispatch`, `goodsReceipt`, `countVariance`, `returnDispatch`) fields

- [x] Task 7: Add `x-openregister-lifecycle` action `postCOGS` to `InventoryValuation`
  per REQ-CG-002 — fires on `saleDispatch` event; emits Dr COGS / Cr Inventory Asset
  `GLTransaction` with `journalCode: "inkoop"`, `subLedgerType: "inventory"`,
  `subLedgerRef: <UUID>`; guard: `unitCost != null && InventoryGLConfig.isActive == true`

- [x] Task 8: Add `x-openregister-lifecycle` action `postReceipt` to `InventoryValuation`
  per REQ-CG-003 — fires on `goodsReceipt` event; emits Dr Inventory Asset / Cr GR/IR
  clearing `GLTransaction` with `journalCode: "inkoop"`, `subLedgerType: "inventory"`;
  same guard conditions as Task 7

- [x] Task 9: Add `x-openregister-lifecycle` action `postVariance` to `InventoryValuation`
  per REQ-CG-004 — fires on `countVariance` event when `delta != 0`; determine Dr/Cr
  direction from sign of delta (positive: Dr Inventory Asset / Cr Inventory Adjustment;
  negative: Dr Inventory Adjustment / Cr Inventory Asset) with `journalCode: "memo"`;
  ADR-031 exception path used: `OCA\Shillinq\Lifecycle\InventoryPostingGuard::direction(int $delta): string`
  with unit test coverage in `tests/Unit/Lifecycle/InventoryPostingGuardTest.php`

- [x] Task 10: Add `"inventory"` to the `GLLine.subLedgerType` enum in
  `lib/Settings/shillinq_register.json` per T1 REQ-GL-009 extension; the existing
  `ap`, `ar`, `project`, `none` values MUST remain unchanged

- [x] Task 11: Seed `InventoryGLConfig` with default Dutch RGS account mappings for
  demo administrations:
  - `cogsAccountNumber: "7000"` (Kostprijs omzet)
  - `inventoryAssetAccountNumber: "1400"` (Voorraden)
  - `grIrClearingAccountNumber: "1800"` (GR/IR clearing)
  - `inventoryAdjustmentAccountNumber: "7100"` (Voorraadmutaties)
  Seeded in `objects` array of `shillinq_register.json` (2 demo administrations + 3 InventoryValuation examples + 3 GLTransaction examples)

- [x] Task 12: Add 2 manifest navigation entries to `src/manifest.json` per REQ-CG-006:
  - `Voorraad > Posting Configuratie` — `type: index` + `type: detail` on `InventoryGLConfig`
  - `Voorraad > Posting Historie` — `type: index` on `GLTransaction` filtered by
    `subLedgerType: "inventory"`, columns: `entryNumber`, `description`, `debitAmount`,
    `creditAmount`, `entryDate`, `subLedgerRef`

- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with:
  - New `InventoryGLConfig` entity entry (schema:Thing, primary spec: inventory-cogs-posting)
  - Additive fields on `InventoryValuation`: `glTransactionId`, `postingEvent`
  - Additive `"inventory"` value on `GLLine.subLedgerType`

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-
persona peer review (e.g. `/test-persona-janwillem` for Dutch SMB)
confirms the COGS + inventory-asset posting flow matches the ERPNext
Perpetual Inventory pattern recognisable to Dutch MKB accountants:
- Stock receipt → Dr Voorraden, Cr GR/IR
- Sale → Dr Kostprijs omzet, Cr Voorraden
- Count variance → Dr/Cr Voorraadmutaties ↔ Voorraden

Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance:
- No app-local GL-posting service class
- Account mapping held in `InventoryGLConfig` config register per ADR-022
- Lifecycle is declarative or ADR-031-exception-annotated guard
- Manifest carries the navigation per ADR-024
- No source code changes outside `openspec/changes/inventory-cogs-posting/`

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for:

- PHPUnit unit tests for all three lifecycle actions:
  - COGS posting on sale (REQ-CG-002): balanced entry, correct accounts,
    null-guard skip, inactive-config skip
  - Inventory asset + GR/IR posting on receipt (REQ-CG-003): balanced entry,
    correct accounts, GR/IR clears when AP invoice posts
  - Count-variance posting for positive and negative delta (REQ-CG-004):
    correct direction, zero-delta skip
- PHPUnit test confirming `GLLine.subLedgerType: "inventory"` resolves
  to the source `InventoryValuation` UUID
- PHPUnit test confirming T1 balance invariant (debit total = credit total)
  is enforced on every materialised inventory `GLTransaction`
- Playwright MCP browser tests for the 2 manifest navigation entries
  (Posting Configuratie index/detail, Posting Historie index)
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors `docs/user-guide/bookkeeping/inventory-cogs-posting.md`
per ADR-030 journeydoc convention, including:

- Step-by-step setup guide for `InventoryGLConfig` with the default
  Dutch RGS account codes
- Explanation of the three posting events with double-entry pairs
- Screenshots of the Posting Configuratie and Posting Historie pages
- Troubleshooting section covering null-unitCost skip events

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings
for:

- `Posting Configuratie` / `Posting Configuration`
- `Posting Historie` / `Posting History`
- `COGS Account` / `Kostprijs rekening`
- `Inventory Asset Account` / `Voorraden rekening`
- `GR/IR Clearing Account` / `GR/IR clearing rekening`
- `Inventory Adjustment Account` / `Voorraadmutaties rekening`
- `Posting Disabled` / `Boeking uitgeschakeld`
- `Unit Cost Missing` / `Kostprijs ontbreekt`
- `Sale Dispatch` / `Verkoopafgifte`
- `Goods Receipt` / `Goederenontvangst`
- `Count Variance` / `Telverschil`
