# Tasks: nav-six-clusters

## 0. Preconditions — do not start implementation before these hold
- [ ] Confirm `nav-reachability-gate` has merged to `development`
  (`tests/validate-nav-reachability.js` and `tests/nav-reachability-
  baseline.json` exist and `npm run check:nav-reachability` runs). If not
  yet merged, stop here and escalate — this change is not safe to implement
  without that mechanical backstop (proposal.md Impact, nav-reachability-
  gate/proposal.md).
- [ ] Confirm `fix/setup-wizard-english` (PR #912) has merged. If not,
  re-run every measurement in `design.md` (§0-§9) against actual
  `development` HEAD before proceeding — do not assume the Dutch-title /
  byte-budget numbers this design used still hold.
- [ ] `npm ci` for real in this repo (not a sibling checkout) and re-run the
  `buildManifest()` measurement design.md §0 describes; confirm 595 pages /
  88 raw top-level / 358 nodes / 29 rendered top-level / 27 duplicate-index
  schemas / 64 duplicate pages still hold. Note any drift before continuing.
- [ ] Read every page's `config` for the design.md §4 rows marked ⚠ and the
  7 "no menu entry" pages in §8 that this design did not read — this is
  the single largest source of risk in this change; do not execute a MERGE
  or RESOLVE verdict from a ⚠ row without first confirming the config.

## 1. Cluster restructure — relocations
- [ ] Add `relocations` entries to `src/menu-layout.json` (or edit
  `src/manifest.d/*.json` `menu[]` blocks directly, per design.md §2's
  fold/relocate table) moving all 29 current top-level groups' children
  under the 6 new cluster ids. Prefer `relocations` over inline fragment
  edits wherever a group is folding wholesale (matches the existing
  pattern already in `menu-layout.json`); edit fragments directly only
  where a group is being renamed or split (`Payroll`→"Payroll" split,
  `PurchaseOrders`→"PO Matching" rename, `Contracts`→"Procurement
  Contracts" rename).
- [ ] Rename the two id-colliding groups per design.md §5: the
  `PurchaseOrders` top-level group → "PO Matching" (children unchanged:
  `ThreeWayMatchIndex`, `VendorPerformanceIndex`), fold into Purchasing.
  The `Verplichtingen` top-level group folds into Purchasing per §4 row 5.
- [ ] Relabel `Payroll`'s rendered label from "People & Projects" to
  "Payroll"; split `ExpenseSettlementClassifier` into the Purchasing
  cluster per §7, keep the other 6 leaves in Bookkeeping.
- [ ] Confirm after this task: `section: "main"` top-level count is exactly
  7 (Dashboard + 6), via the same `buildManifest()` measurement as task 0.

## 2. Schema consolidation (design.md §4, all 27 rows)
- [ ] High-confidence MERGE rows (config already verified in design.md):
  `AnalyticalDimension`, `KORRegistration`, `InventoryValuation`,
  `GLTransaction`, `Receipt`, `SupplierInvoice`, `ThreeWayMatch`,
  `StockMove`, the 4 `DBA*` pairs. For each: delete the duplicate page(s)
  from their `manifest.d` fragment, add a `menu[].query` preset node
  pointing at the canonical page id with the retired page's filter as the
  query object, and **carry forward any `rowRoute`/`_note_rowRoute` key
  from whichever page had one onto the canonical page** (design.md §10 —
  mechanical checklist, do not skip: `Receipts`, `SupplierInvoices`,
  `ThreeWayMatches` all carry this).
- [ ] `Subsidie` (design.md §4 row 1): canonical `SubsidiesOverzicht`;
  merge `SubsidiesVerleend` and (`SubsidiesTeruggevorderd` +
  `SubsidieTerugvorderingen`, keeping the richer `SubsidieTerugvorderingen`
  columns) into presets; give `SubsidieAanvragen` its own real menu/card
  entry (RESOLVE, not merge — its columns differ materially); relocate
  `RDSubsidies` to Cluster 5 Taxes as its own page (KEEP, not merge — its
  `detailRoute`/`documentationUrl` show it is a separate capability).
- [ ] `Verplichting` (row 5): before deleting `Verplichtingen`, either add
  its `costCentre` column to canonical `Verplichtingenregister` or confirm
  cost-centre is reachable via the `AnalyticalDimensions` cross-link (open
  question 3). Relabel `MijnContracten` → "TenderNed-sourced commitments"
  and convert to a `source=tenderned` preset (its filter is data-source
  scoped, not caller-identity scoped — do not treat it as an ADR-097
  Decision 3 personal surface).
- [ ] `Project` (row 8): execute via the `shillinq-nav-ia-cleanup` spec
  delta (`specs/shillinq-nav-ia-cleanup/spec.md`) — delete
  `ProjectenOverzicht`'s page definition entirely (not just its menu
  entry), relocate `Projects` + `Utilisatie` into Bookkeeping.
- [ ] `ConsolidationGroup` (row 11): delete `Consolidations`, keep
  `ConsolidationGroups` (+ its siblings `ConsolidationPeriods`,
  `ConsolidatedReports`) as the canonical Bookkeeping consolidation section.
- [ ] ⚠-marked rows needing a config read before acting (design.md §4 /
  task 0's 4th item): `InventoryStock` (partial — `StockLedger` likely
  KEEP), `BankReconciliation` (`Reconciliations`/`VarianceReport`),
  `Rechtmatigheidstoets`. Read each, then apply MERGE or KEEP as the config
  actually supports — do not force a MERGE this design only hypothesized.
- [ ] KEEP + RELOCATE rows (no page deletion, cluster/placement fix only):
  `Account` (3-way split across clusters), `ARInvoice` (`ARAging` →
  Sales), `GLLine` (`GRConsolidated` → Cluster 6), `Contract` (relabel
  `RevenueContracts` → "Revenue Contracts", no merge).
- [ ] Pure-KEEP rows (placement already correct, verify no accidental
  change): `FiscalYear`, `InventoryReorderRule`, `APTransaction`,
  `DepreciationSchedule`.
- [ ] After every row is executed, re-run the duplicate-index-page scan
  (the same `register+schema` grouping this design used) and confirm the
  27-schema count has dropped to only the rows this task intentionally kept
  as KEEP (expect ~11 remaining schemas with >1 index page, all
  design-justified, zero unintentional survivors).

## 3. Cluster landing pages
- [ ] Build 5 new `type: "custom"` landing components under
  `src/components/<cluster-slug>/` (Bookkeeping, Sales, Purchasing, Banking
  & Cashflow, Taxes) following the `ReportingComplianceOverview.vue`
  pattern (design.md §3): category-grouped card grid, `data-testid="
  <cluster>-overview"` root, cards linking to index pages with `?query=`
  presets where design.md §4 specifies one. Register each in
  `src/registry.js`.
- [ ] Reuse `ReportingComplianceOverview` as-is for the Reporting &
  Compliance cluster's landing page (no new component) — just fold the
  additional content (§2's `Overheid`, `AccountantPortal`, `DBACompliance`
  survivors, `Sustainability`, `ContinuousControlsMonitoring`,
  `Administratie`) into its existing card categories.
- [ ] Add a one-line code comment in the Banking & Cashflow landing
  component reserving a "Budgets" card slot for a later change — do not
  create the page or menu node itself (design.md §2).
- [ ] Point each cluster's top-level `menu[].route` at its landing page id.

## 4. Dangling dialog pages (design.md §6)
- [ ] Delete `VATReturnCreateDialog`, `ReimbursementPolicyCreateDialog`,
  `PassThroughMarkupRuleCreateDialog`, `RetainerPoolCreateDialog` page
  definitions from their fragments.
- [ ] Remove the `config.createDialog` key from the 4 owning index pages
  (`VATReturns`, `ReimbursementPolicies`, `PassThroughMarkupRules`,
  `RetainerPools`) — confirm via `grep -rln createDialog src --include=
  *.vue --include=*.js` (excluding `manifest.d`) that removing the key
  breaks nothing (it already reads nothing).

## 5. IA-gap baseline resolution (design.md §8, 25 entries)
- [ ] For each of the 7 index/detail pairs (`RateCards`/`RateSchedules`/
  `RateAuditTrail`/`AansluitingResultaten`/`WBSOActivityCodes`/`Deposits`/
  `InnovatieboxElections`, each with its detail page), read
  `config.register`/`config.schema` and give the index page a real menu
  entry in the cluster its schema belongs to (design.md §8's provisional
  cluster guesses: Rate* → Sales or Purchasing depending on what is rated;
  `Aansluitingen`-family → Bookkeeping, alongside "Tie-outs"; WBSO* →
  Taxes, alongside `RDSubsidies`; `Deposits` → Sales/Bookings; Innovatiebox*
  → Taxes — verify each against its actual config, do not copy this list
  blind).
- [ ] `AccountingStandardsPolicy` → Bookkeeping (provisional, verify);
  `BewaartermijnenDashboard` → Reporting & Compliance, alongside
  `Bewaartermijnen` (provisional, verify).
- [ ] Once each of these 25 ids is menu-reachable, remove it from
  `tests/nav-reachability-baseline.json#exceptions`.
- [ ] Run `npm run check:nav-reachability` and confirm: zero new orphans,
  and a stale-exception warning for exactly these 25 pruned ids (proving
  they really did become reachable, not just deleted from the baseline
  file blind).

## 6. `shillinq-nav-ia-cleanup` re-verification
- [ ] REQ-NAVIA-001 (Manual Journals label) — confirm unaffected by the
  restructure (Journals stays inside Bookkeeping cluster, label unchanged).
- [ ] REQ-NAVIA-002 — implemented per task 2's `Project` row and the spec
  delta in `specs/shillinq-nav-ia-cleanup/spec.md`.
- [ ] REQ-NAVIA-003 (Projects deep link resolves after nav change) —
  confirm the `Projects` route (now inside Bookkeeping) still resolves by
  direct navigation.
- [ ] REQ-NAVIA-004/005 (single active nav leaf, no duplicate detail-route
  registration) — re-verify against the restructured Purchasing cluster
  specifically (`PurchaseOrders`/`GoodsReceipts` detail routes), since this
  is exactly the area this change touches most (the `PurchaseOrders`
  id-collision fix, §5).
- [ ] REQ-NAVIA-006/007/008 (e2e coverage, dashboard stats-block) — confirm
  existing e2e specs for these still pass unmodified.

## 7. Byte budget
- [ ] Run `node tests/check-manifest-budget.js` before this change's first
  commit (baseline) and after the final commit (result). Record both totals
  and the delta in the PR description — do not rely on design.md §9's
  estimate as the reported number.
- [ ] Confirm the after-total is at or under budget.

## 8. e2e coverage
- [ ] Write `tests/e2e/NavSixClusters.spec.js` per design.md §11: top-level
  entry count/labels, all 6 cluster landing pages render, deep links to the
  named sample pages (including at least one `?query=` preset assertion),
  and the 4 deleted dialog routes no longer resolve. Use `page.goto(APP +
  ROUTE)` / `dismissWizard(page)` conventions from `tests/e2e/
  AccountantPortalDashboard.spec.js` (no `gotoAppRoute` helper exists in
  this repo — do not invent a dependency on one).
- [ ] Tag every new Scenario's `@e2e` reference to match this spec file's
  test names exactly (gate-19 traceability).

## 9. Cross-repo follow-up — NOT implemented by this change
- [ ] Generate the demotion list: every one of the 23 non-Dashboard,
  non-cluster-named former top-level ids (design.md §2's table, all rows
  except `Dashboard` and the 6 cluster names) paired with its fold target
  and a one-line reason.
- [ ] Hand this list back to the orchestrator as an explicit cross-repo
  task: author the ADR-097 amendment in `hydra/openspec/architecture/`
  naming every demotion (design.md §13). Do not edit anything under
  `hydra/` from this change's own branch/PR.

## 10. Validation
- [ ] `npm run check:nav-reachability` — PASS, zero new orphans, 25 stale
  exceptions warned (task 5).
- [ ] `node tests/check-manifest-budget.js` — PASS (task 7).
- [ ] `npx vitest run` — full suite green, including any new unit coverage
  for the manifest edits.
- [ ] `npx playwright test tests/e2e/NavSixClusters.spec.js` — PASS.
- [ ] Full existing e2e suite — no regressions introduced by the
  restructure (spot-check `shillinq-nav-ia-cleanup`'s existing specs per
  task 6).
- [ ] `openspec validate nav-six-clusters --strict` — PASS.
