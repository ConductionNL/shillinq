# Design: nav-six-clusters

## 0. Measurement method and baseline

All counts below come from calling the real
`buildManifest(base, fragments, menuLayout)` — never a re-implementation, the
same rule `nav-reachability-gate/design.md` §1 states for its own gate —
against a byte-identical copy of this repo's `src/manifest.json` +
`src/manifest.d/*.json` (81 fragments, sorted by filename, `README.md`
excluded) + `src/menu-layout.json`, run in a sibling checkout
(`fix/setup-wizard-english`, PR #912) that has `node_modules` installed. This
repo's own `node_modules` is not installed. **Re-run this measurement against
a real `npm ci` in this repo before implementing** — the same caveat
`nav-reachability-gate/design.md` §3 already recorded for the identical
inputs.

**This design measures against PR #912's outcome, not `development` HEAD.**
#912 (open as of 2026-08-19) translates 15 Dutch manifest titles to English
and resolves `Aansluitingen` → **"Tie-outs"**, confirmed a genuinely distinct
feature from `Reconciliations` (bank-statement reconciliation) rather than a
duplicate — see `openspec/changes/setup-wizard-english/notes-for-nav-six-
clusters.md` on that branch. This change depends on #912 landing first (in
addition to depending on `nav-reachability-gate`, proposal.md). If #912 has
not merged when this change is implemented, re-run every measurement in this
document — do not assume the numbers below still hold.

Byte budget at the #912 baseline: `manifest.json` 460,786B + `manifest.d/`
662,587B = 1,123,373B against a 1,126,300B budget — **2,927 bytes of
headroom**, confirmed by running `node tests/check-manifest-budget.js` on the
#912 branch.

## 1. Counting basis (ADR-097 exact definition, applied)

ADR-097 Decision 1: "Counted over `menu[]` entries in `section: main`,
excluding `type: caption` dividers, on the effective manifest — after
`manifest.d` fragments merge and `menu-layout.json` relocations apply."

Measured:

| | Count |
|---|---:|
| Pages | 595 |
| Raw top-level menu groups (pre-relocation, `mergeMenuItems` output) | 88 |
| Total menu nodes at every depth (post-relocation) | 358 |
| **Top-level entries, `section: main`, excl. captions (post-relocation)** | **29** |
| Schemas with >1 `type: index` page | 27 |
| Duplicate index pages (sum across those 27 schemas) | 64 |
| Settings-foldout entries (`menu-layout.json#settingsSection`) | 57 |

This change's target, per the approved taxonomy: **Dashboard (ADR-097-exempt)
+ exactly 6 top-level entries**, counted the same way, verified by the same
script this design used (`tests/nav-reachability-gate`'s effective-manifest
builder is the closest existing tool; `hydra`'s `gate-65 navigation-budget`,
when it lands, will re-verify from outside this repo).

## 2. Cluster → children mapping (top 2 levels)

The 29 current top-level groups fold into Dashboard + 6 clusters as follows.
"Relocate" means the group's children move under the target cluster with no
page or menu-node deleted. "Fold" means the group dissolves entirely (its own
top-level identity goes away) with children distributed as noted. Byte/page
deletions are listed separately in §4 (schema consolidation) and §9 (budget).

| Current top-level (label) | Children | → Cluster | Notes |
|---|---:|---|---|
| `Dashboard` | 0 | *(exempt)* | Unchanged |
| `BankingTreasury` | 16 | 4 Banking & Cashflow | Direct relocate |
| `Cashflow` | 3 | 4 Banking & Cashflow | Direct relocate; sibling to BankingTreasury's children |
| `Bookkeeping` | 58 | 1 Bookkeeping | Direct relocate (largest single group) |
| `Consolidation` | 3 | 1 Bookkeeping | Fold — replaces the thinner `Consolidations` leaf already inside Bookkeeping (§4, `ConsolidationGroup`) |
| `Ifrs16Leases` | 4 | 1 Bookkeeping | Direct relocate |
| `DualGaap` | 5 | 1 Bookkeeping | Direct relocate |
| `Ifrs15Revenue` | 6 | 1 Bookkeeping | Direct relocate |
| `Projecten` ("Projects") | 2 | 1 Bookkeeping | Fold — `ProjectenOverzicht` merges into Bookkeeping's `Projects` (§4, `Project`; supersedes REQ-NAVIA-002), `Utilisatie` relocates alongside it |
| `Payroll` (labelled "People & Projects") | 7 | 1 Bookkeeping | Fold, split: 6 core payroll leaves relocate here (journal-entry-adjacent); `ExpenseSettlementClassifier` relocates to Purchasing instead (§7) |
| `Sales` | 20 | 2 Sales | Direct relocate |
| `Orders` | 0 (leaf) | 2 Sales | Direct relocate |
| `RecurringInvoicing` | 0 (leaf) | 2 Sales | Direct relocate |
| `PaymentRequests` | 0 (leaf) | 2 Sales | Direct relocate — task brief names it explicitly under Sales |
| `Purchasing` (labelled "Purchasing & Inventory") | 35 | 3 Purchasing | Direct relocate (includes Inventory) |
| `AccountsPayableT2` | 5 | 3 Purchasing | Direct relocate |
| `PurchaseOrders` (top-level id collision, see §5) | 2 | 3 Purchasing | Fold — group RENAMED "PO Matching" to resolve the id collision with the `PurchaseOrders` leaf already inside Purchasing; children (`ThreeWayMatchIndex`, `VendorPerformanceIndex`) relocate under it |
| `Verplichtingen` (top-level id collision, see §5) | 4 | 3 Purchasing | Fold into the Purchasing `Verplichtingen`/Commitments area (§4, `Verplichting`) |
| `Contracts` | 3 | 3 Purchasing | Direct relocate; `Contracts` page retitled "Procurement Contracts" to disambiguate from `RevenueContracts` (§4, `Contract`) |
| `BankingTreasury` sub-item `BankReconciliation` | — | 4 Banking & Cashflow | Already covered above |
| `Belastingen` ("Taxes") | 32 | 5 Taxes | Direct relocate |
| `PublicSector` | 19 | 6 Reporting & Compliance | Direct relocate |
| `Overheid` ("Government") | 4 | 6 Reporting & Compliance | Fold — task brief: "Public sector" + "Government" merge into cluster 6 |
| `Subsidies` ("Subsidies & Funds") | 11 | 6 Reporting & Compliance | Direct relocate |
| `Compliance` ("Reporting & Compliance") | 13 | 6 Reporting & Compliance | This group's own landing page (`ReportingComplianceOverview`) becomes the CLUSTER landing page — no new component needed for this cluster, see §3 |
| `ContinuousControlsMonitoring` | 6 | 6 Reporting & Compliance | Direct relocate |
| `Sustainability` | 5 | 6 Reporting & Compliance | Direct relocate |
| `AccountantPortal` | 0 (leaf) | 6 Reporting & Compliance | Demoted from top-level to a landing card, per task brief ("NOT a top-level role entry") |
| `DBACompliance` (full duplicate, see §5) | 6 | 6 Reporting & Compliance | Fold — 4 of 6 children are duplicates of existing Compliance-cluster DBA pages and are deleted (§4); `DBARisicoflags` (unique) relocates in as new content |
| `Administratie` ("Administration") | 1 | 6 Reporting & Compliance | Fold — single child `Bewaartermijnen` (retention periods) is a compliance topic |

That accounts for all 29. Six clusters, each landing on an ADR-097 D4 domain
name from the approved taxonomy; Dashboard exempt; **7 rendered top-level
entries total** (Dashboard + 6), at the ADR-097 counting basis.

**A "Budgets" leaf is reserved but not created** in Cluster 4 (Banking &
Cashflow) — task brief: a later change adds it. No placeholder page or menu
node ships from this change; the reservation is documentation only (this
paragraph + a one-line comment in the Cluster 4 landing component).

## 3. Landing page pattern (ADR-097 Decision 4)

Precedent: `Compliance`'s existing `ReportingComplianceOverview`
(`src/components/reporting/ReportingComplianceOverview.vue`, registered
`type: "custom"` in `src/registry.js`) — a category-grouped card grid over a
static catalogue, with `data-testid` hooks (`reporting-overview`,
`reporting-overview-title`, …) for Playwright. Its own header comment
documents why it is `type: "custom"` and not `index`/`dashboard` (no single
OpenRegister collection backs a catalogue-of-report-types), and carries a
reason-bearing `@spec exclude` for a capability with no canonical spec — that
precedent does NOT apply here, since this change's spec **is** the canonical
`nav-clusters` capability; each new landing page gets real `@spec` /
`@e2e` tags.

Each of the 5 NEW cluster landing pages (Bookkeeping, Sales, Purchasing,
Banking & Cashflow, Taxes — Reporting & Compliance reuses
`ReportingComplianceOverview` as-is) follows the same shape:

- `type: "custom"`, one Vue component under `src/components/<cluster-slug>/`,
  registered in `src/registry.js`.
- Cards grouped by the cluster's own children (the "domains within the
  domain" — e.g. Bookkeeping's landing groups Ledger / Journals / Dimensions
  / Fiscal Years / Dual GAAP & IFRS / Consolidation / Payroll as card
  sections), each card linking to its index page — with a `?query=` param
  where §4's table names a preset.
- `data-testid="<cluster>-overview"` / `"<cluster>-overview-title"` root
  hooks, matching the `ReportingComplianceOverview` convention, so the e2e
  spec in §11 has stable selectors.
- The route becomes the cluster's top-level `menu[].route` (matching how
  `Compliance`'s top-level node already carries `route:
  "ReportingComplianceOverview"` today) — this is what makes the top-level
  entry itself navigable rather than a bare non-clickable group header.

No new page TYPE is introduced; this is the same `type: "custom"` mechanism
already in production for `Compliance`.

## 4. The 27-schema consolidation table

Verdict legend: **MERGE** = duplicate pages deleted, canonical page kept,
`menu[].query` preset link(s) added per ADR-097 Decision 5. **RELOCATE** =
no pages deleted, cluster placement corrected. **KEEP** = pages are
genuinely distinct capabilities sharing a schema by coincidence — no menu or
page change beyond cluster placement. **RESOLVE** = an orphaned page (not
menu-reachable today) gets a real menu entry, satisfying
`nav-reachability-gate`'s ratchet (§8) — nothing is deleted.

Confidence is marked ✅ (page `config` read directly, verdict evidence-based)
or ⚠ (structural/naming reasoning only — **implementer must read the page
config before acting**, same honesty bar `nav-reachability-gate/design.md`
§3 held itself to for its own orphan categorization).

| # | Schema | Pages (count) | Verdict | Detail |
|---|---|---|---|---|
| 1 | `Subsidie` | 6: `RDSubsidies`, `SubsidiesOverzicht`, `SubsidiesVerleend`, `SubsidiesTeruggevorderd`, `SubsidieAanvragen`, `SubsidieTerugvorderingen` | Mixed ✅ | Canonical = `SubsidiesOverzicht` ("Subsidies"). **MERGE**: `SubsidiesVerleend`→preset `state=granted`, delete page. **MERGE**: `SubsidiesTeruggevorderd` and `SubsidieTerugvorderingen` are the same "reclaimed" view — configs differ only in `visibility.administrationType` scoping and column set; keep the richer `SubsidieTerugvorderingen` (has `counterpartyName`/`reclaimedAmount`) as canonical for that preset, delete `SubsidiesTeruggevorderd`. This ALSO resolves `SubsidieTerugvorderingen`'s current orphan status in the same edit. **RESOLVE**: `SubsidieAanvragen` has a materially different column set (`counterpartyName`/`schemeName`/`direction`/`requestedAmount`) — not a pure filter duplicate; keep as its own page, give it a real menu/landing-card entry. **KEEP + RELOCATE**: `RDSubsidies`'s `detailRoute` is `RDSubsidieDetail` (not `SubsidieDetail` like the other 5) and its `documentationUrl` is `people-projects/overview`, not `public-sector/overview` — it is a genuinely separate WBSO/R&D capability, not a role lens of the generic Subsidies list. Relocate to Cluster 5 Taxes (WBSO/R&D adjacency) rather than merging into Cluster 6. |
| 2 | `InventoryStock` | 4: `StockLevels`, `StockByLocation`, `ReserveStock`, `StockLedger` | Mixed ⚠ | Canonical = `StockLevels`. **MERGE** (verify config): `StockByLocation`→preset `view=byLocation`, `ReserveStock`→preset `view=reserved`. **KEEP**: `StockLedger` is very likely a distinct transaction-history report (different column shape expected: running balance) — verify before merging further. |
| 3 | `AnalyticalDimension` | 3: `CostCenters`, `KostenDragers`, `AnalyticalDimensions` | **MERGE** ✅ | Confirmed: all three filter the SAME field, `dimensionType` (`cost-center` / `cost-object` / `custom`) — textbook ADR-097 Decision 5. Canonical index = `AnalyticalDimensions`, with `CostCenters`/`KostenDragers` becoming presets (`dimensionType=cost-center` / `cost-object`). Their DETAIL pages (`CostCenterDetail`, `KostenDragerDetail`) are unaffected — out of scope (no route renames). |
| 4 | `Account` | 3: `EmuRapportage`, `ChartOfAccounts`, `VpbPligtigeAccounts` | **KEEP + RELOCATE** ✅ | Three genuinely different domain views (EMU public-sector reporting extract, general COA, Vpb-liable subset) — NOT role-lens duplicates, correctly distributed: `ChartOfAccounts`→Cluster 1, `EmuRapportage`→Cluster 6, `VpbPligtigeAccounts`→Cluster 5. No pages deleted. |
| 5 | `Verplichting` | 3: `Verplichtingen`, `MijnContracten`, `Verplichtingenregister` | Mixed ✅ | Canonical = `Verplichtingenregister` (richer: `kind`, `total_amount_excl_vat`, `mandate_applied`) but it is MISSING `Verplichtingen`'s `costCentre` column — **before deleting `Verplichtingen`, add `costCentre` to the canonical or confirm cost-centre is visible via the `AnalyticalDimensions` cross-link**. `MijnContracten`'s "My contracts" label is misleading: its filter is `source=tenderned` (a DATA-SOURCE filter, not a caller-identity/RBAC scope) — this is a role lens, not an ADR-097 Decision 3 personal surface. **MERGE**: relabel to "TenderNed-sourced commitments" and convert to preset `source=tenderned`. |
| 6 | `KORRegistration` | 3: `KorAanmelding`, `KorDashboard`, `KorOpzegging` | **MERGE** ✅ | Confirmed: all three filter `status` on the same schema, differing only in which status values are offered (draft/active vs active/ended vs active/lock-in) — a lifecycle-stage duplicate (ADR-097 Decision 4+5 combined). Canonical = `KorDashboard`, presets for intake (`KorAanmelding`) and cancellation (`KorOpzegging`) flows. |
| 7 | `InventoryValuation` | 2: `InventoryValuation`, `InventoryValuations` | **MERGE** ✅ | Near-identical column sets confirmed (singular vs plural id is itself the tell). Keep `InventoryValuation`, delete `InventoryValuations`. |
| 8 | `Project` | 2: `Projects`, `ProjectenOverzicht` | **MERGE** ⚠ | Supersedes `shillinq-nav-ia-cleanup` REQ-NAVIA-002 (proposal.md, spec delta in `specs/shillinq-nav-ia-cleanup/spec.md`). Canonical = `Projects`, relocated into Cluster 1 Bookkeeping. `ProjectenOverzicht` deleted; `Utilisatie` (the `Projecten` group's other child, different schema) relocates alongside as a companion card, not merged. |
| 9 | `GLTransaction` | 2: `GeneralLedger`, `InventoryPostingHistory` | **MERGE** ✅ | Confirmed: `InventoryPostingHistory` is `GeneralLedger` filtered `subLedgerType=inventory` — textbook duplicate. Canonical = `GeneralLedger` (Cluster 1). `InventoryPostingHistory` page deleted; a query-preset deep link (`subLedgerType=inventory`) is surfaced as a card inside Purchasing (Cluster 3) pointing at the Cluster-1 canonical page — a legitimate cross-cluster link, not a duplicate page. |
| 10 | `GLLine` | 2: `GRConsolidated`, `BudgetToProgrammeLinker` | **KEEP + RELOCATE** ✅ | Confirmed genuinely different purposes: `GRConsolidated` uses `aggregation: consolidatedTrialBalance` (a computed rollup for *Gemeenschappelijke Regeling* joint-authority reporting — "GR" ≠ generic corporate consolidation); `BudgetToProgrammeLinker` is a mapping-completeness tool (`mappingStatus` thresholds), already correctly in the settings foldout. No merge. `GRConsolidated` relocates from Bookkeeping → Cluster 6 (public-sector content, was misplaced). |
| 11 | `ConsolidationGroup` | 2: `Consolidations`, `ConsolidationGroups` | **MERGE** ⚠ | The `Consolidation` top-level group (`ConsolidationGroups` + `ConsolidationPeriods` + `ConsolidatedReports`) is the fuller feature; the older `Bookkeeping > Consolidations` leaf is the thinner duplicate. Canonical = `ConsolidationGroups` (folds in via §2's `Consolidation` fold). Delete `Consolidations`. |
| 12 | `ARInvoice` | 2: `AccountsReceivable`, `ARAging` | **KEEP + RELOCATE** ✅ | Confirmed: `ARAging` uses `aggregation: arAging` (a computed bucket report), not a plain filter — a genuinely distinct report type, not a duplicate. `ARAging` currently sits under `Bookkeeping`, detached from `AccountsReceivable` under `Sales` — relocate `ARAging` → Cluster 2 Sales, next to its sibling, matching how `APAgingT2` already sits next to Accounts Payable. |
| 13 | `FiscalYear` | 2: `FiscalYears`, `YearEndCloseChecklist` | **KEEP** ⚠ | Config vs. process-checklist — different purposes sharing a schema for the FK relationship. `FiscalYears` stays in the settings foldout; `YearEndCloseChecklist` relocates into Cluster 1 main content. No merge. |
| 14 | `Receipt` | 2: `Receipts`, `ReceiptsMissingDocument` | **MERGE** ✅ | Confirmed: `ReceiptsMissingDocument` is `Receipts` filtered `sourceDocumentUri=null`. **`Receipts` carries a load-bearing `_note_rowRoute` explaining `config.rowRoute` is required for `CnPageRenderer.onRowOpen()` row-click navigation to work (the detail page is overlaid `type:"custom"`) — this key MUST survive on whichever page id remains canonical (§10).** Canonical = `Receipts`, preset `sourceDocumentUri=null`, delete `ReceiptsMissingDocument`. |
| 15 | `InventoryReorderRule` | 2: `ReorderRules`, `LowStockAlerts` | **KEEP** ⚠ | Config editor (settings foldout) vs. an alert-monitoring view — different purposes. No merge. |
| 16 | `SupplierInvoice` | 2: `SupplierInvoices`, `SupplierInvoicesMissingDocument` | **MERGE** ✅ | Same pattern as `Receipt`: confirmed `sourceDocumentUri=null` filter duplicate. **`SupplierInvoices` carries `rowRoute: SupplierInvoiceDetail` — same load-bearing-config caveat as #14, MUST survive on the canonical page (§10).** Canonical = `SupplierInvoices`, preset, delete `SupplierInvoicesMissingDocument`. |
| 17 | `ThreeWayMatch` | 2: `ThreeWayMatches`, `ThreeWayMatchExceptions` | **MERGE** ✅ | Confirmed: `ThreeWayMatchExceptions` is `ThreeWayMatches` filtered `matchStatus in [exception_*, fraud_alert]`. **Both carry `rowRoute: ThreeWayMatchDetail` — MUST survive on the canonical page (§10).** Canonical = `ThreeWayMatches`, preset, delete `ThreeWayMatchExceptions`. |
| 18 | `BankReconciliation` | 2: `Reconciliations`, `VarianceReport` | **MERGE** ⚠ | `VarianceReport`'s own `_note` confirms it is "converted from a would-be `type:report` (unregistered) to a plain filtered index" over the same schema — a status filter (`reconciliationStatus`), matching ADR-097 Decision 5. Canonical = `Reconciliations` (relocated to Cluster 4). Recommend preset `reconciliationStatus=closed-with-variance`; verify with product before deleting, since its own note frames it as report-shaped. |
| 19–22 | `DBAOpdracht`, `DBAPortfolioRisico`, `DBAEvidenceDossier`, `DBAModelovereenkomst` | 8 total: `DBAIntakeWizard`/`DBAOpdrachten`, `DBAPortfolioDashboard`/`DBAPortfolioRisicos`, `DBAEvidenceBrowser`/`DBAEvidenceDossiers`, `DBAModelovereenkomstRegister`/`DBAModelovereenkomsten` | **MERGE ×4** ✅ | Confirmed: every pair is a near-byte-identical duplicate (same schema, same core columns, minor label/order variance) — the entire `DBACompliance` top-level group duplicates the `Compliance` cluster's existing DBA cards. Canonical = the `Compliance`-cluster page in each pair (already correctly named and placed); delete the `DBACompliance`-group counterpart in each pair. `DBARisicoflags` (the `DBACompliance` group's 6th child) has no duplicate — it is genuinely new content, relocates into Cluster 6 as-is. |
| 23 | `APTransaction` | 2: `APTransactions`, `APAgingT2` | **KEEP** ⚠ | Same shape as `ARAging` — an aging report is a distinct column layout, not a filter duplicate. No merge. |
| 24 | `DepreciationSchedule` | 2: `DepreciationSchedules`, `DepreciationExpense` | **KEEP** ⚠ | Schedule list vs. computed period-expense report — likely distinct. Verify before merging. |
| 25 | `Contract` | 2: `RevenueContracts`, `Contracts` | **KEEP (relabel)** ✅ | Confirmed materially different columns: `Contracts` carries procurement fields (`contractType`, `tags`, repository search); `RevenueContracts` carries IFRS-15 fields (`fixedConsideration`, `signedAt`). Genuinely distinct capabilities sharing a schema — like `Account` (#4), not a role lens. **Both are currently titled identically "Contracts"**, a real UX bug (indistinguishable in menus/breadcrumbs): relabel `RevenueContracts` → "Revenue Contracts" (label-only, id/route unchanged). `Contracts` stays in Cluster 3 (relabeled "Procurement Contracts" per §2); `RevenueContracts` stays under Ifrs15Revenue → Cluster 1. |
| 26 | `Rechtmatigheidstoets` | 2: `Rechtmatigheidstoetsing`, `RechtmatigheidAuditExport` | **MERGE** ⚠ | Near-identical columns confirmed (assessment view vs. an assessor/export-oriented view of the same rows) — verify with product whether the export variant needs a distinct download action rather than a plain preset before deleting. Tentative canonical = `Rechtmatigheidstoetsing`, preset `view=audit-export`. |
| 27 | `StockMove` | 2: `StockMovements`, `ReservedStock` | **MERGE** ✅ | Confirmed: `ReservedStock` is `StockMovements` filtered `filterPreset.lifecycleState=draft`. Canonical = `StockMovements`, preset `lifecycleState=draft`, delete `ReservedStock`. **Naming hazard**: this `ReservedStock` (schema `StockMove`) is easily confused with `ReserveStock` (#2, schema `InventoryStock`) — near-identical names, different schemas, different meanings. Rename the surviving preset's card label to "Stock reservations (movements)" to disambiguate from InventoryStock's "Reserved" preset. |

**Net page deletions from this table: 16 pages confirmed/likely
(`SubsidiesVerleend`, `SubsidiesTeruggevorderd`, `CostCenters`,
`KostenDragers`, `KorAanmelding`, `KorOpzegging`, `InventoryValuations`,
`ProjectenOverzicht`, `InventoryPostingHistory`, `Consolidations`,
`ReceiptsMissingDocument`, `SupplierInvoicesMissingDocument`,
`ThreeWayMatchExceptions`, 4× DBA duplicates), plus up to 6 more pending
config verification (`StockByLocation`/`ReserveStock` partial,
`Verplichtingen`, `MijnContracten`-as-page-object retained-but-relabeled so
not a deletion, `VarianceReport`, `RechtmatigheidAuditExport`,
`ReservedStock`) — **~20 pages** is the working estimate carried into §9.**

## 5. Structural defects this change also fixes

Beyond the 27-schema table:

1. **`PurchaseOrders` id collision** — a top-level group and a `Purchasing`
   leaf share the id `PurchaseOrders`. The group (children:
   `ThreeWayMatchIndex`, `VendorPerformanceIndex` — both `type: "custom"`
   summary widgets, not duplicate index pages) is renamed "PO Matching" on
   fold into Cluster 3, resolving the collision without touching its
   children's content.
2. **`Verplichtingen` id collision** — same shape, resolved by §2's fold and
   §4 row 5's consolidation.
3. **`DBACompliance` as a full duplicate group** — §4 rows 19-22.
4. **`Payroll` mislabeled "People & Projects"** — relabeled to "Payroll" (its
   actual content) on fold into Cluster 1; `ExpenseSettlementClassifier`
   splits off to Purchasing (§7) since its siblings (`ExpenseClaims`,
   `MileageLog`, `Receipts`) already live there.
5. **`ExternalConnections` phantom top-level** — confirmed via
   `src/manifest.json`: the base group has zero children; every candidate
   child is an `ExternalAdapter*` leaf already lifted straight into the
   settings foldout by `menu-layout.json#settingsSection`, so
   `applyMenuRemovals`'s empty-shell rule prunes the group before it ever
   renders. It is not one of the 29 rendered top-levels today and needs no
   action from this change — noted here so the implementer does not go
   looking for a group that (correctly) isn't there.
6. **`ARAging` / `GRConsolidated` misplacement** — §4 rows 10 and 12.
7. **`shillinq-nav-ia-cleanup` REQ-NAVIA-002 is stale** — §4 row 8; spec
   delta in `specs/shillinq-nav-ia-cleanup/spec.md`.

## 6. The 4 dangling `config.createDialog` pages

`VATReturnCreateDialog`, `ReimbursementPolicyCreateDialog`,
`PassThroughMarkupRuleCreateDialog`, `RetainerPoolCreateDialog`: each is
named in another page's `config.createDialog` key, but
`grep -rln createDialog src --include=*.vue --include=*.js` (excluding
`manifest.d`) returns **nothing** — no component anywhere reads that config
key. `nav-reachability-gate/design.md`'s hypothesis that these are "opened
from an action button on their owning index page" is not supported by the
code; they are genuinely unreachable.

**Decision: delete the 4 pages and their `config.createDialog` references.**
Reasoning:
- Wiring `config.createDialog` to actually open a dialog is a UI-behavior
  change, not a navigation/menu-placement change — out of this change's
  declared scope (proposal.md non-goals: "no route renames... only menu
  placement and page-set membership change" — deleting an already-dead page
  is a page-set change this change is chartered to make; adding new dialog
  behavior is not).
- ADR-044 no-functionality-loss does not bind here: these pages provide zero
  functionality today (nothing renders them), so deleting them loses
  nothing a user could reach.
- Frees ~4,427 bytes (measured: `VATReturnCreateDialog` 773B +
  `ReimbursementPolicyCreateDialog` 908B + `PassThroughMarkupRuleCreateDialog`
  1,198B + `RetainerPoolCreateDialog` 1,548B), which matters against the
  2,927-byte headroom.
- If a real "create X inline" UX is wanted later, that is a fresh,
  product-scoped feature change — not something this restructure should
  half-implement by leaving 4 dead pages in place "just in case."

## 7. Payroll & ExternalConnections placement (today vs. Wave 2)

Per the task brief: "the Payroll group and ExternalConnections placement
must still land somewhere sane in the 6 — put them where they belong today;
Wave 2 will shrink them."

- **Payroll**: 6 core leaves (`Werkgevers`, `Werknemers`, `Loonperiodes`,
  `Loonstroken`, `LHAfdrachten`, `Loonjournaalposten`) land in Cluster 1
  Bookkeeping — defensible because payroll posts journal entries into the
  GL and this repo has no dedicated HR/People cluster in the approved
  taxonomy. `ExpenseSettlementClassifier` lands in Cluster 3 Purchasing
  (expense-settlement is AP-adjacent, matching its existing Purchasing
  siblings). **This is flagged, not resolved** — per the fleet memory
  programme note ("onboarding→hrmq (NOT hermiq!)"), payroll administration
  is a likely future hand-off to the fleet's dedicated HR app; Wave 2 is the
  right place to decide whether shillinq keeps this content or re-homes it
  entirely.
- **ExternalConnections: explicitly frozen, do not touch.** Already a
  phantom top-level (§5.5) — every one of its 15 adapter-family children is
  already correctly in the settings foldout via
  `menu-layout.json#settingsSection`. **This change MUST NOT relocate,
  restructure, or delete any of these 15 entries, even though several sit
  inside clusters this change otherwise touches heavily (e.g. the
  `ExternalAdapterCcm`/`ExternalAdapterCsrd` entries are content-adjacent to
  Cluster 6, `ExternalAdapterBunq`/`ExternalAdapterMollie` to Cluster 4).**
  A confirmed Wave 2 change, `integration-config-to-openconnector`
  (branch `feat/integration-config-to-openconnector`, spec committed
  2026-08-19, `lib/Controller/ExternalAdaptersAdminController.php`'s
  `ADAPTERS` registry — re-verified: **15** adapter families, not 14; the
  `Cbs` directory alone holds two, `CbsBestandenAdapterInterface` and
  `CbsIv3AdapterInterface`, each with its own registry entry, `sourceSlug`,
  and nav page), already owns the full collapse of this surface: all 15
  `ExternalAdapterDetail` pages + the `ExternalAdaptersStatus` index fold
  into ONE roster page reusing the `ExternalAdaptersStatus` id/route
  (REQ-ICO-002/004), freeing a measured **~9,920 bytes**
  (`src/manifest.d/external-adapters-w8.json`: 10,922B → ~1,002B, per that
  change's own `design.md` §3/§7). That change lands AFTER this one.
  Touching any of these 15 entries here — even a pure relocation with no
  page deleted — would create a manifest-fragment conflict between the two
  branches for no navigational benefit, since Wave 2 deletes the whole
  group's page-level structure outright days or weeks later. Leaving them
  exactly where they are today is the lowest-churn, lowest-risk choice.
  **The ~9,920-byte saving is Wave 2's, not this change's — §9 does not
  count it, and this change's own byte budget must close on its own
  merits.**

## 8. `nav-reachability-gate` baseline — resolving the 25 IA-gap entries

`nav-reachability-gate` has not landed yet (proposal.md Impact). Once it
does, its seeded baseline is expected to carry the 25 entries the task brief
names as "to be resolved by nav-six-clusters" (the seven index/detail pairs
+ `SubsidieAanvragen` / `SubsidieTerugvorderingen` / `AccountingStandardsPolicy`
/ `BewaartermijnenDashboard`, per `nav-reachability-gate/design.md` §3's own
measurement). This change resolves them as follows:

- `SubsidieAanvragen`, `SubsidieTerugvorderingen` — §4 row 1 (RESOLVE /
  MERGE respectively).
- `RateCards`/`RateCardDetail`, `RateSchedules`/`RateScheduleDetail`,
  `RateAuditTrail`/`RateRecordDetail`, `AansluitingResultaten`/
  `AansluitingResultDetail`, `WBSOActivityCodes`/`WBSOActivityCodeDetail`,
  `Deposits`/`DepositDetail`, `InnovatieboxElections`/
  `InnovatieboxElectionDetail` — **not individually researched in this
  design** (they were not in the 27-schema duplicate list, meaning each is a
  genuinely singular index page with no menu entry anywhere, per
  `nav-reachability-gate/design.md` §3). Each needs a real menu/landing-card
  entry in whichever cluster its schema belongs to (Rate* → likely Cluster 2
  Sales or Cluster 3 Purchasing depending on what is being rated; Aansluiting*
  → Cluster 1 Bookkeeping, alongside `Aansluitingen`/"Tie-outs"; WBSO* →
  Cluster 5 Taxes, alongside `RDSubsidies` per §4 row 1; Deposits → Cluster 2
  Sales, `Bookings` neighbourhood; Innovatiebox* → Cluster 5 Taxes). **Task
  in tasks.md**: read each page's `config.register`/`config.schema` and pick
  the cluster before implementing; this design intentionally does not
  guess seven more page configs it has not read.
- `AccountingStandardsPolicy`, `BewaartermijnenDashboard` — single pages, no
  menu entry. `Bewaartermijnen` (retention periods, §2) already lands in
  Cluster 6; `BewaartermijnenDashboard` is very likely its companion
  dashboard and should land there too, pending a config read.
  `AccountingStandardsPolicy` — likely Cluster 1 Bookkeeping (dual-GAAP
  framework election is adjacent), pending a config read.

**After this change, `tests/nav-reachability-baseline.json` should have
these 25 entries removed (not added-to)** — the ratchet only tightens; a
baseline entry that becomes reachable is pruned, and `nav-reachability-
gate`'s own REQ-NAVR-003 already reports that as a WARN to prompt exactly
this pruning.

## 9. Byte-budget impact

Measured: 595 pages total 758,936 bytes, average 1,276 bytes/page, median
946 bytes/page (page bodies only — menu nodes, `_meta`, and fragment
wrapper overhead are additional but small per-entry).

**Explicitly excluded from this estimate: the ~9,920 bytes `integration-
config-to-openconnector` (Wave 2, §7) will free by collapsing the 15
`ExternalConnections` adapter pages into one roster page.** That saving
belongs to Wave 2's own PR, lands after this change, and is not this
change's to spend or report — this change touches zero bytes of
`src/manifest.d/external-adapters-w8.json` (§7) and its budget below must
close using only its own deletions, against the current, pre-Wave-2
headroom of 2,927 bytes.

- Deleting the 4 dangling dialog pages (§6): **-4,427 bytes**, high
  confidence.
- Deleting ~16 confirmed-duplicate pages (§4, "Net page deletions"
  paragraph) at the measured 946-byte median: **≈ -15,100 bytes**.
- Up to 6 more pages pending config verification, same median: **≈ -5,700
  bytes** additional if all are confirmed mergeable (do not count on this
  half — several rows are marked ⚠ precisely because they may turn out to
  be legitimately distinct).
- New landing-page components add zero manifest bytes (they are `type:
  "custom"` pages like `ReportingComplianceOverview` already is — one page
  entry each, ~5 new pages × ~400-600 bytes for a minimal custom-page
  definition ≈ **+2,000-3,000 bytes**).
- New `menu[].query` preset nodes (one per MERGE row's retired duplicate,
  replacing a full page definition with a lightweight menu node + query
  param) are far smaller than the pages they replace — a few dozen bytes
  each, not separately itemized here because they are already netted into
  the page-deletion figures above (a MERGE row deletes the whole page
  object AND replaces it with a much smaller menu-node reference).

**Net estimate: roughly -17,000 to -22,000 bytes freed**, moving headroom
from 2,927 bytes to somewhere in the 20,000-25,000 byte range — comfortably
covering "later programme changes need that headroom" (task context) without
this change itself needing to spend any of it. **This is an estimate, not a
committed number** — §4's ⚠-marked rows are real uncertainty; task in
tasks.md is to re-run `check:manifest-budget` before/after and report the
actual delta.

## 10. `rowRoute` / load-bearing config preservation guardrail

Several pages in the 27-schema table carry a `config.rowRoute` (or
`_note_rowRoute` explaining why one is required) because their detail page
is overlaid `type: "custom"` and the implicit `detailPageByRegisterSchema`
lookup `CnPageRenderer.onRowOpen()` relies on can't find it — confirmed on
`Receipts`, `SupplierInvoices`, `ThreeWayMatches`, and (inherited into the
merge) `ThreeWayMatchExceptions`. **Any MERGE that changes which page id is
canonical for these schemas MUST carry the `rowRoute` key forward onto the
surviving page** — dropping it silently breaks row-click navigation with no
error, exactly the class of defect `_note_rowRoute` already warns about once.
This is a mechanical checklist item in tasks.md, not a design decision.

## 11. e2e coverage plan

New Playwright spec (`tests/e2e/NavSixClusters.spec.js`, `@e2e nav-six-
clusters::*`, `@spec openspec/changes/nav-six-clusters/specs/nav-clusters/
spec.md#req-navc-*`):

- Asserts the rendered top-level nav shows exactly 7 entries (Dashboard + 6
  clusters) with the exact expected labels — REQ-NAVC-001.
- For each of the 6 cluster landing pages: navigates to its route, asserts
  the `data-testid="<cluster>-overview"` root renders and at least one card
  section is visible — REQ-NAVC-002.
- Deep-links directly to a sample of relocated/consolidated pages by route
  (bypassing the menu — matching `shillinq-nav-ia-cleanup` REQ-NAVIA-003's
  existing pattern for `Projects`) and asserts each resolves, not a 404:
  at minimum `GeneralLedger`, `AnalyticalDimensions` (with a
  `?dimensionType=cost-center` query-preset assertion), `KorDashboard`,
  `Receipts`, `Reconciliations`, `Aansluitingen` (Tie-outs), `Contracts`,
  `RevenueContracts` — REQ-NAVC-006.
- Asserts the 4 deleted dialog pages' OLD routes are genuinely gone (404 or
  redirect, per whatever this app's router does for an unregistered route) —
  proving the deletion actually happened, not just the menu entry.

**No `gotoAppRoute` helper exists in this repo today** (checked:
`grep -rl gotoAppRoute tests/e2e` returns nothing) — the task brief's
"use `gotoAppRoute` convention if present" does not apply; follow the
existing convention instead (`page.goto(APP + ROUTE)` with `APP =
'/apps/shillinq'`, `dismissWizard(page)` before assertions — both from
`tests/e2e/AccountantPortalDashboard.spec.js`, and per this repo's
first-open-modal lesson, `#firstrunwizard` covers the canvas on every fresh
Playwright context).

## 12. `shillinq-nav-ia-cleanup` supersession

`specs/shillinq-nav-ia-cleanup/spec.md` (this change's delta) MODIFIES
REQ-NAVIA-002 only. REQ-NAVIA-001, -003 through -008 are unaffected and this
change's tasks re-verify each still holds after the restructure (single
active nav leaf per route, `Projects` deep-link still resolves, no duplicate
detail-route registration, "Manual Journals" label unchanged, stats-block
card-nesting fix unaffected).

## 13. Cross-repo: the ADR-097 amendment (hydra repo, not this change)

ADR-097 Decision 1 requires "a seventh [entry] requires an amendment... 
naming what it demotes and why nothing could be." This change goes the other
direction (29 → 6), but the same accountability applies to *what folded into
what* — a reader of ADR-097 six months from now needs to find why
`DBACompliance`, `Consolidation`, `Ifrs16Leases`, etc. are no longer
top-level. `tasks.md` task 9 generates the demotion list (every one of the
23 non-Dashboard, non-cluster-named former top-levels, with its fold target)
and hands it back to the orchestrator as an explicit hydra-repo follow-up —
this change's own tasks do not edit anything under `hydra/openspec/
architecture/`.

## 14. Open questions for the implementer

1. Every ⚠-marked row in §4 needs its page `config` read before the merge is
   executed — this design read 16 of 27 schemas' actual JSON; the rest are
   structurally reasoned, and at least one (`Reconciliations`/
   `VarianceReport`) has its own code comment suggesting it may deserve
   report-shaped treatment rather than a plain preset.
2. The 7 "no menu entry anywhere" pages in §8 need their `config.register`/
   `config.schema` read to pick a cluster — not guessed here.
3. `Verplichtingenregister`'s missing `costCentre` column (§4 row 5) needs a
   product call: add the column, or confirm cost-centre is discoverable via
   the `AnalyticalDimensions` cross-link instead.
4. Whether WBSO content (currently split across `Subsidies` and, per this
   design, landing partly in Taxes via `RDSubsidies`) should eventually be
   ONE home is a product question this change does not force — the task
   brief names WBSO under both "Taxes" and (via Subsidies) "Reporting &
   Compliance" in its own domain descriptions.
5. §9's byte estimate has real uncertainty on the ⚠ rows — re-measure with
   `check:manifest-budget` before and after, and report the actual number in
   the PR description rather than this estimate.
