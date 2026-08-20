# Tasks: nav-six-clusters

## 0. Preconditions — do not start implementation before these hold
- [x] Confirm `nav-reachability-gate` has merged to `development`
  (`tests/validate-nav-reachability.js` and `tests/nav-reachability-
  baseline.json` exist and `npm run check:nav-reachability` runs). If not
  yet merged, stop here and escalate — this change is not safe to implement
  without that mechanical backstop (proposal.md Impact, nav-reachability-
  gate/proposal.md). — Confirmed merged (commit c7314e1a); baseline seeded
  with 40 orphans.
- [x] Confirm `fix/setup-wizard-english` (PR #912) has merged. If not,
  re-run every measurement in `design.md` (§0-§9) against actual
  `development` HEAD before proceeding — do not assume the Dutch-title /
  byte-budget numbers this design used still hold. — Confirmed merged
  (commit 74da1986); byte budget re-measured against this tree and matched
  design.md §0 exactly (460,786B + 662,587B = 1,123,373B / 1,126,300B —
  2,927B headroom).
- [x] `npm ci` for real in this repo (not a sibling checkout) and re-run the
  `buildManifest()` measurement design.md §0 describes; confirm 595 pages /
  88 raw top-level / 358 nodes / 29 rendered top-level / 27 duplicate-index
  schemas / 64 duplicate pages still hold. Note any drift before continuing.
  — `node_modules` symlinked from the sibling checkout (npm ci not run
  directly — no network in this sandbox); re-measured via the real
  buildManifest pipeline: 595 pages ✓ / 358 nodes ✓ / 29 top-level ✓ / 27
  dup schemas ✓ / 64 dup pages ✓ — all match design.md exactly. One drift:
  "88 raw top-level pre-relocation" measured 64 here (informational number
  only, not used by any gate or requirement — not investigated further).
- [x] Read every page's `config` for the design.md §4 rows marked ⚠ and the
  7 "no menu entry" pages in §8 that this design did not read — this is
  the single largest source of risk in this change; do not execute a MERGE
  or RESOLVE verdict from a ⚠ row without first confirming the config.
  — All ⚠ rows read; see the final verdict table in the implementer's
  report (several verdicts changed from design.md's tentative guess after
  reading the actual config — InventoryStock, DBACompliance's true
  duplicate count, Rechtmatigheidstoets).

## 1. Cluster restructure — relocations
- [x] Add `relocations` entries to `src/menu-layout.json` (or edit
  `src/manifest.d/*.json` `menu[]` blocks directly, per design.md §2's
  fold/relocate table) moving all 29 current top-level groups' children
  under the 6 new cluster ids. — Done via `src/menu-layout.json#relocations`
  for every safe (non-cascading) source; 4 groups whose OWN id was itself an
  existing relocation TARGET (BankingTreasury, Payroll, PublicSector,
  Subsidies) were resolved by retargeting their dependents directly to the
  final cluster id instead of chaining through the folding id — chaining
  through a dissolving id orphans the dependents (verified against
  buildManifest.js's single-hop-per-pass relocation algorithm; documented in
  menu-layout.json's own new `_relocations_nav_six_clusters_note`).
- [x] Rename the two id-colliding groups per design.md §5: the
  `PurchaseOrders` top-level group → "PO Matching" (children unchanged:
  `ThreeWayMatchIndex`, `VendorPerformanceIndex`), fold into Purchasing.
  The `Verplichtingen` top-level group folds into Purchasing per §4 row 5.
  — `PurchaseOrders` id renamed to `POMatching` in both owning fragments;
  `Verplichtingen` collision resolved by deleting the colliding duplicate
  LEAF+page (§4 row 5) first, then folding the now-unambiguous top-level
  group.
- [x] Relabel `Payroll`'s rendered label from "People & Projects" to
  "Payroll"; split `ExpenseSettlementClassifier` into the Purchasing
  cluster per §7, keep the other 6 leaves in Bookkeeping. — DEVIATION:
  `Payroll`'s own base definition had `children: []` (all content arrived
  via its 2 relocation dependents `Loonadministratie`/`ExpenseSettlement`);
  retargeting those directly to Bookkeeping/Purchasing left `Payroll`
  permanently empty, so it was deleted outright rather than relabeled —
  there is no surviving "Payroll" group to relabel. The outcome design.md
  wanted (6 payroll leaves in Bookkeeping, ExpenseSettlementClassifier +
  its 2 siblings in Purchasing) is achieved.
- [x] **Do not touch `src/manifest.d/external-adapters-w8.json` or any of
  the 15 `ExternalAdapter*` ids in `menu-layout.json#settingsSection`** —
  design.md §7. Diff this file at the end of task 1 and confirm it is
  byte-identical to its pre-change state. — Confirmed via `git diff`:
  `external-adapters-w8.json` shows zero changes; none of the 15
  `ExternalAdapter*` ids appear in the `menu-layout.json` diff.
- [x] Confirm after this task: `section: "main"` top-level count is exactly
  7 (Dashboard + 6), via the same `buildManifest()` measurement as task 0.
  — Confirmed: 7 entries, exact labels Dashboard / Bookkeeping / Sales /
  Purchasing / Banking & Cashflow / Taxes / Reporting & Compliance.

## 2. Schema consolidation (design.md §4, all 27 rows)
- [x] High-confidence MERGE rows (config already verified in design.md):
  `AnalyticalDimension`, `KORRegistration`, `InventoryValuation`,
  `GLTransaction`, `Receipt`, `SupplierInvoice`, `ThreeWayMatch`,
  `StockMove`, the 4 `DBA*` pairs. — All 11 executed. `rowRoute` carry
  check: `Receipts`/`SupplierInvoices`/`ThreeWayMatches` already carried
  `rowRoute` on the CANONICAL (surviving) page, so no carry-forward was
  needed (design.md §10 was precautionary, already satisfied).
  `ThreeWayMatchExceptions`'s filter is a 5-value `matchStatus IN [...]`
  array — not expressible as a scalar `menu[].query` value (schema
  restricts query values to string/number/boolean); converted to a plain
  (unfiltered) link to the canonical page instead of a precise preset —
  documented limitation, capability still reachable.
- [x] `Subsidie` (design.md §4 row 1) — executed exactly as specified:
  `SubsidiesVerleend`/`SubsidiesTeruggevorderd` deleted + converted to
  presets (`SubsidiesTeruggevorderd`'s target is `SubsidieTerugvorderingen`,
  which is already self-filtered via its own `config.defaultFilter`, so no
  `query` was needed there); `SubsidieAanvragen` given a real menu entry
  (RESOLVE); `RDSubsidies` relocated to Taxes (KEEP).
- [x] `Verplichting` (row 5) — `costCentre` column ported onto canonical
  `Verplichtingenregister` (orchestrator ruling #2, ADR-044 spirit) before
  deleting `Verplichtingen`; `MijnContracten` relabelled "TenderNed-sourced
  commitments" and converted to a `source=tenderned` preset.
- [x] `Project` (row 8) — `ProjectenOverzicht` page definition deleted
  entirely (not just its menu entry); `Projects` + `Utilisatie` relocated
  into Bookkeeping via the `Projecten` group fold.
- [x] `ConsolidationGroup` (row 11) — `Consolidations` index deleted, kept
  `ConsolidationGroups` (+ siblings) as canonical. `ConsolidationsDetail`'s
  own field set was NOT a subset of `ConsolidationGroupDetail`'s (different
  relatedLists: group-entities vs. consolidation-periods) — kept the page
  (ADR-044, no route rename) and added a reason-bearing
  `nav-reachability-baseline.json` exception rather than deleting real
  content or silently dropping reachability.
- [x] ⚠-marked rows needing a config read before acting: `InventoryStock`,
  `BankReconciliation`, `Rechtmatigheidstoets`. All read; verdicts below
  (2 of 3 diverge from design.md's tentative hypothesis after reading the
  actual config — recorded per task 0's honesty requirement):
  - `InventoryStock`: none of `StockLevels`/`StockByLocation`/
    `ReserveStock`/`StockLedger` carries a hardcoded filter value (all use
    `filters: [...]` UI-widget-option arrays, not a `filter`/`filterPreset`
    hardcoded value) — no clean MERGE mechanism exists. Per orchestrator
    ruling #1 (default to KEEP + RELOCATE on ambiguous evidence): all 4 KEPT,
    relocated to Purchasing. Deviates from design.md's tentative "MERGE
    StockByLocation/ReserveStock".
  - `BankReconciliation`: `VarianceReport` KEPT per orchestrator ruling #1
    (explicit — its own `description` field confirms "Converted from the
    never-rendered type=report... the KPI/aggregation dashboard variant
    needs a dedicated renderer first" — report-shaped). Relocated next to
    `Reconciliations` in Banking & Cashflow.
  - `Rechtmatigheidstoets`: `RechtmatigheidAuditExport` has NO hardcoded
    filter distinguishing it from `Rechtmatigheidstoetsing` — per
    orchestrator ruling #1 ("KEEP unless config proves a pure filter
    duplicate"), KEPT as a separate page. Deviates from design.md's
    tentative "MERGE".
- [x] KEEP + RELOCATE rows (no page deletion, cluster/placement fix only):
  `Account` (3-way split: ChartOfAccounts→Bookkeeping,
  EmuRapportage→ReportingCompliance, VpbPligtigeAccounts→Taxes, via their
  existing group relocations), `ARInvoice` (`ARAging` → Sales, individual
  leaf relocation), `GLLine` (`GRConsolidated` → ReportingCompliance,
  individual leaf relocation), `Contract` (`RevenueContracts` relabelled
  "Revenue Contracts", `Contracts` relabelled "Procurement Contracts" — no
  merge, both survive).
- [x] Pure-KEEP rows (placement already correct, verify no accidental
  change): `FiscalYear`, `InventoryReorderRule`, `APTransaction`,
  `DepreciationSchedule` — confirmed unchanged (all 4 already correctly
  live inside groups that fold into their intended cluster wholesale, no
  individual action needed).
- [x] After every row is executed, re-run the duplicate-index-page scan and
  confirm the 27-schema count has dropped to only KEEP rows. — Result: 12
  schemas remain with >1 index page (task estimated ~11; the 1 over is
  `InventoryStock`, the ruling-driven KEEP-all-4 deviation above), 29
  duplicate pages remain (down from 64) — all 12 survivors are
  design/ruling-justified KEEP or KEEP+RELOCATE verdicts, zero
  unintentional survivors.

## 3. Cluster landing pages
- [x] Build 5 new `type: "custom"` landing components under
  `src/components/<cluster-slug>/` following the `ReportingComplianceOverview.vue`
  pattern. — Built as thin wrappers (`BookkeepingOverview.vue`,
  `SalesOverview.vue`, `PurchasingOverview.vue`,
  `BankingCashflowOverview.vue`, `TaxesOverview.vue`) around one shared
  `src/components/cluster-overview/ClusterOverview.vue` static card-grid
  component (DRY — the 5 pages differ only in their card-section data, not
  in rendering/testid logic). `data-testid="<cluster>-overview"` root +
  `-title` hooks match the `ReportingComplianceOverview` convention.
  Registered in `src/registry.js`. NOTE: each page shows a REPRESENTATIVE
  set of the cluster's children grouped by sub-domain, not literally every
  one of the ~20-77 leaves each cluster now holds (see report) — a
  disclosed scope compromise given the change's size, not a gap in the
  underlying menu structure (every leaf is still menu-reachable regardless
  of whether it has its own landing-page card).
- [x] Reuse `ReportingComplianceOverview` as-is for the Reporting &
  Compliance cluster's landing page (no new component) — its top-level menu
  id was renamed `Compliance` → `ReportingCompliance` (REQ-NAVC-001
  scenario 2), and the fragment's own menu-merge target updated to match
  (`src/manifest.d/reporting-compliance.json`) or the route wiring would
  have silently orphaned onto a phantom duplicate "Compliance" group with
  no label — caught and fixed during implementation.
- [x] Add a one-line code comment in the Banking & Cashflow landing
  component reserving a "Budgets" card slot for a later change — present in
  `BankingCashflowOverview.vue`'s header comment + inline code comment.
- [x] Point each cluster's top-level `menu[].route` at its landing page id.
  — Done via `src/manifest.d/nav-six-clusters-landing-pages.json`, mirroring
  `reporting-compliance.json`'s existing route-injection pattern.

## 4. Dangling dialog pages (design.md §6)
- [x] Delete `VATReturnCreateDialog`, `ReimbursementPolicyCreateDialog`,
  `PassThroughMarkupRuleCreateDialog`, `RetainerPoolCreateDialog` page
  definitions from their fragments. — All 4 deleted.
- [x] Remove the `config.createDialog` key from the 4 owning index pages. —
  All 4 keys removed; their `tests/nav-reachability-baseline.json`
  exceptions pruned (pages no longer exist).

## 5. IA-gap baseline resolution (design.md §8, 25 entries)
- [x] For each of the 7 index/detail pairs, read `config.register`/
  `config.schema` and give the index page a real menu entry. — All 7 given
  real menu entries: `RateCards`/`RateSchedules`/`RateAuditTrail` → Sales
  (rate-card/billing infrastructure — genuinely ambiguous between Sales and
  Purchasing per design.md's own hedge; judgment call, documented in the
  report); `AansluitingResultaten` → Bookkeeping (alongside `Aansluitingen`/
  Tie-outs, confirmed same neighbourhood); `WBSOActivityCodes` /
  `InnovatieboxElections` → Taxes (alongside `RDSubsidies`); `Deposits` →
  Sales (Bookings neighbourhood).
- [x] `AccountingStandardsPolicy` → Bookkeeping; `BewaartermijnenDashboard`
  → Reporting & Compliance, alongside `Bewaartermijnen`. Both added as real
  menu leaves.
- [x] Once each of these ids is menu-reachable, remove it from
  `tests/nav-reachability-baseline.json#exceptions`. — 21 of the baseline's
  40 original exceptions pruned in total (17 IA-gap ids + 4 dangling
  dialogs). NOTE: the baseline file's actual 25 "to be resolved by
  nav-six-clusters" entries do not exactly match design.md's own prose list
  — the file additionally names `KostenpostDetail`, `IPAssetValuationDetail`,
  `ReconciliationReportDetail`, `ReviewWorkflowDetail`,
  `CashflowBufferPolicyDetail`, `CashflowWeekDetail`, `OrderDetail`,
  `BookingsForm` (8 entries design.md's prose never analysed), while
  `SubsidieAanvragen` (which design.md's prose does name) is not actually
  in the baseline file's exceptions map at all. `OrderDetail` was resolved
  (added `detailRoute: "OrderDetail"` to the `Orders` index, a one-line
  wiring fix). The other 7 remain unresolved — see report for why (each
  needs a page-CONFIG wiring fix — a `relatedList`/cross-link addition on
  another page — not a menu-placement fix, arguably beyond this change's
  declared "menu placement and page-set membership" scope, but flagged as
  incomplete against REQ-NAVC-007's literal "all 25" wording rather than
  silently dropped).
- [x] Run `npm run check:nav-reachability` and confirm: zero new orphans.
  — PASS: 0 new orphans, 21 baselined (down from 40), 0 stale warnings.

## 6. `shillinq-nav-ia-cleanup` re-verification
- [x] REQ-NAVIA-001 (Manual Journals label) — `Journals` leaf unchanged,
  still inside Bookkeeping cluster, label "Manual Journals" untouched.
- [x] REQ-NAVIA-002 — implemented per task 2's `Project` row and the spec
  delta.
- [x] REQ-NAVIA-003 (Projects deep link resolves) — confirmed:
  `Projects` page route `/bookkeeping/dimensions/projects` unchanged,
  resolvable independent of menu placement.
- [x] REQ-NAVIA-004/005 (single active nav leaf, no duplicate detail-route
  registration) — re-verified programmatically against the FULL
  restructured tree (not just Purchasing): 0 duplicate menu ids anywhere,
  0 duplicate page routes anywhere (both measured via the real
  `buildManifest()` output). The `PurchaseOrders`/`Verplichtingen` id
  collisions (design.md §5) are the two defects this specifically targeted
  — both confirmed resolved.
- [x] REQ-NAVIA-006/007/008 (e2e coverage, dashboard stats-block) — not
  executed live (no deployed instance in this sandbox; per task
  instructions the verify stage runs e2e live). Spot-checked statically:
  `AccountantPortalDashboard`'s route (`/accountant-portal`) and page id
  are unchanged — only its MENU placement moved (top-level leaf →
  ReportingCompliance cluster child) — so its existing spec's selectors
  and `page.goto` target remain valid.

## 7. Byte budget
- [x] Run `node tests/check-manifest-budget.js` before this change's first
  commit and after the final commit. — Before: manifest.json=460,786B +
  manifest.d/=662,587B = 1,123,373B (2,927B headroom). After:
  manifest.json=452,691B + manifest.d/=641,429B = 1,094,120B (32,180B
  headroom). **Delta: -29,253B freed** (design.md §9 estimated -17,000 to
  -22,000B — actual is somewhat larger, mainly because several MERGE rows
  deleted BOTH an index and a detail page where design.md's estimate only
  budgeted for index-page deletions, e.g. the 4 DBA detail-page pairs).
- [x] Confirm the after-total is at or under budget using only this
  change's own deletions. — PASS, 1,094,120B < 1,126,300B budget, and zero
  bytes touched in `external-adapters-w8.json` (Wave 2's future saving is
  not counted here).

## 8. e2e coverage
- [x] Write `tests/e2e/NavSixClusters.spec.js` per design.md §11: top-level
  entry count/labels, all 6 cluster landing pages render, deep links to the
  named sample pages (including a `?dimensionType=cost-center` preset
  assertion), and the 4 deleted dialog routes no longer resolve. Written
  using `page.goto(APP + ROUTE)` / `dismissWizard(page)` conventions from
  `AccountantPortalDashboard.spec.js`. All route strings verified against
  the actual manifest `page.route` values (not guessed) before writing.
  NOT executed (per task instructions — the verify stage runs it live).
- [x] Tag every new Scenario's `@e2e` reference to match this spec file's
  test names exactly — file-level `@e2e nav-six-clusters::*` tags match the
  spec.md scenarios' own tags exactly (top-level-entry-count-and-labels,
  cluster-landing-pages-render, preset-deep-links-resolve,
  deleted-dialog-routes-gone).

## 9. Cross-repo follow-up — NOT implemented by this change
- [x] Generate the demotion list — produced in the implementer's final
  report (23 entries: every non-Dashboard, non-cluster-named former
  top-level id, its fold target, and a one-line reason). Not written to any
  file in this repo per this task's own instruction.
- [ ] Hand this list back to the orchestrator as an explicit cross-repo
  task — the list is generated (see report); actually filing/handing off
  the hydra-repo ADR-097 amendment task is an orchestrator action outside
  this implementer's tool access, so left as a literal hand-off, not
  ticked as "done" by this change.

## 10. Validation
- [x] `npm run check:nav-reachability` — PASS, 0 new orphans, 21 baselined
  (17 of the 25-per-file IA-gap ids + 4 dangling dialogs pruned; 7
  IA-gap ids design.md's prose never analysed remain, incomplete — see
  task 5's note and the report for detail; `OrderDetail` additionally
  resolved).
- [x] `node tests/check-manifest-budget.js` — PASS (task 7).
- [x] `npx vitest run` — 205/205 tests passed, 18/18 files, no regressions.
- [x] `node tests/validate-manifest.js` — PASS (0 Ajv errors, 0 consistency
  issues).
- [x] `node tests/l10n/check-dutch-tokens.js` — PASS, no Dutch labels
  reintroduced.
- [x] `npx eslint` / `npx prettier --check` on every new/changed `src/`
  file and the new e2e spec — clean (0 errors on new files; the 96
  pre-existing warnings elsewhere in `src/` are unrelated to this change).
- [ ] `npx playwright test tests/e2e/NavSixClusters.spec.js` — NOT run (no
  deployed instance in this sandbox; per task instructions the verify
  stage runs this live).
- [ ] Full existing e2e suite — NOT run live, for the same reason;
  spot-checked statically instead (task 6).
- [x] `openspec validate nav-six-clusters --strict` — PASS ("Change
  'nav-six-clusters' is valid").
