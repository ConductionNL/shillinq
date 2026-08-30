# Spec: nav-clusters (delta)

## ADDED Requirements

### Requirement: REQ-NAVC-001 — The effective main menu MUST render Dashboard plus exactly 6 top-level cluster entries

Counted per ADR-097 Decision 1 (`menu[]` entries in `section: "main"`,
excluding `type: "caption"` dividers, on the post-relocation effective
manifest): the rendered top-level entry count MUST be 7 — the ADR-097-exempt
`Dashboard` plus exactly 6 domain clusters named **Bookkeeping**, **Sales**,
**Purchasing**, **Banking & Cashflow**, **Taxes**, and **Reporting &
Compliance**. No other top-level entry may exist in `section: "main"`.

#### Scenario: The rendered menu shows exactly 7 top-level entries
- **GIVEN** the effective manifest built via `buildManifest(base, fragments,
  menuLayout)` from this repo's post-restructure `src/manifest.json` +
  `src/manifest.d/*.json` + `src/menu-layout.json`
- **WHEN** `menu[]` is filtered to `section: "main"` and `type !==
  "caption"`
- **THEN** exactly 7 entries remain, with ids `Dashboard`, `Bookkeeping`,
  `Sales`, `Purchasing`, `BankingCashflow`, `Taxes`, `ReportingCompliance`
  (or equivalent canonical ids chosen at implementation time — the
  requirement is the count and the domain names, not the literal ids)

@e2e nav-six-clusters::top-level-entry-count-and-labels

#### Scenario: No former top-level id survives as a top-level entry
- **GIVEN** the pre-restructure top-level id list (`BankingTreasury`,
  `Payroll`, `PublicSector`, `Belastingen`, `Administratie`, `Overheid`,
  `Subsidies`, `Compliance`, `Projecten`, `Cashflow`, `AccountantPortal`,
  `PaymentRequests`, `AccountsPayableT2`, `ContinuousControlsMonitoring`,
  `Consolidation`, `Sustainability`, `Ifrs16Leases`, `DualGaap`,
  `Ifrs15Revenue`, `PurchaseOrders`, `Verplichtingen`, `Contracts`,
  `DBACompliance`, `Orders`, `RecurringInvoicing`)
- **WHEN** the effective manifest's `section: "main"` top-level ids are
  inspected
- **THEN** none of those ids appears as a top-level entry (each has folded
  into one of the 6 clusters per design.md §2)

@e2e nav-six-clusters::top-level-entry-count-and-labels

### Requirement: REQ-NAVC-002 — Each cluster MUST have an ADR-097 Decision-4-shaped landing page

Each of the 6 top-level cluster entries MUST route to a `type: "custom"`
landing page rendering a category-grouped card grid (the "cards-collapse"
pattern already in production as `ReportingComplianceOverview`), grouping
the cluster's own children into card sections, each card linking to its
index page (with a `?query=` preset where design.md §4 specifies one). A
top-level cluster entry MUST NOT be a bare, non-clickable group header.

#### Scenario: A cluster's top-level menu node carries a route to its landing page
- **GIVEN** the `Bookkeeping` cluster's top-level menu node
- **WHEN** its `route` field is inspected
- **THEN** it names a `type: "custom"` page id whose component renders a
  card grid (not `route: null`/`undefined`)

@e2e nav-six-clusters::cluster-landing-pages-render

#### Scenario: A cluster landing page renders its cards
- **GIVEN** a user navigates to a cluster's landing route
- **WHEN** the page finishes loading
- **THEN** the `data-testid="<cluster>-overview"` root element is visible
  and at least one card-section is rendered

@e2e nav-six-clusters::cluster-landing-pages-render

### Requirement: REQ-NAVC-003 — Duplicate index pages over the same schema MUST be consolidated per ADR-097 Decision 5

Where design.md §4's schema table marks a schema **MERGE**, the duplicate
page(s) MUST be deleted and their capability MUST remain reachable from the
canonical page via a `menu[].query` preset (a menu node carrying `route:
<canonicalPageId>` and a `query` object matching the retired page's
hardcoded filter) or an equivalent landing-card deep link. Where the table
marks a schema **KEEP**, **KEEP + RELOCATE**, or **RESOLVE**, no page may be
deleted — only its cluster placement or menu reachability may change.

#### Scenario: A MERGE-verdict duplicate page is gone and its filter survives as a preset
- **GIVEN** schema `KORRegistration`'s three duplicate index pages
  (`KorAanmelding`, `KorDashboard`, `KorOpzegging`)
- **WHEN** the restructured manifest is inspected
- **THEN** exactly one page (`KorDashboard`) remains registered for that
  schema's index view, and two `menu[].query` preset nodes reach the same
  page filtered to the retired pages' status values

@e2e nav-six-clusters::preset-deep-links-resolve

#### Scenario: A KEEP-verdict pair is not merged
- **GIVEN** schema `Contract`'s two pages (`Contracts`, `RevenueContracts`),
  confirmed to carry materially different column sets (design.md §4 row 25)
- **WHEN** the restructured manifest is inspected
- **THEN** both pages still exist as separate registered pages, and
  `RevenueContracts`' title no longer collides with `Contracts`' title

@e2e nav-six-clusters::preset-deep-links-resolve

### Requirement: REQ-NAVC-004 — Every page deletion MUST leave its capability reachable, verified by the reachability gate

Per ADR-044 no-functionality-loss: for every page deleted by this change
(design.md §4's MERGE rows and §6's 4 dangling dialog pages), the
capability that page provided MUST remain reachable from some surviving
page or menu entry, except where the deleted page provided zero reachable
functionality to begin with (the 4 dangling `config.createDialog` pages,
design.md §6, which nothing could open before deletion either).
`npm run check:nav-reachability` MUST report zero NEW orphans introduced by
this change's manifest edits.

#### Scenario: check:nav-reachability reports zero new orphans after the restructure
- **GIVEN** the restructured `src/manifest.json` + `src/manifest.d/*.json` +
  `src/menu-layout.json`
- **WHEN** `npm run check:nav-reachability` runs
- **THEN** it exits 0, and its new-orphan list is empty

@e2e exclude build-time script assertion, not a UI-observable behavior — verified by CI's `check:nav-reachability` leg, not Playwright

#### Scenario: A dangling dialog page's deletion is not a regression
- **GIVEN** `VATReturnCreateDialog` is deleted by this change
- **WHEN** the reachability check runs
- **THEN** `VATReturnCreateDialog` does not appear in the new-orphan list
  (it was never reachable before deletion — confirmed by `grep -rln
  createDialog src --include=*.vue --include=*.js` returning no reader of
  `config.createDialog` anywhere in the frontend)

@e2e exclude build-time script assertion, not a UI-observable behavior — verified by CI's `check:nav-reachability` leg, not Playwright

### Requirement: REQ-NAVC-005 — The system MUST NOT expose a top-level entry naming a role, record type, lifecycle stage, or single tool, and MUST cap depth at 2

The system MUST NOT name any of the 6 top-level cluster entries (or any
future top-level addition) after a role ("Manager portal"), a record type
("Invoices"), a lifecycle stage ("Planning"), or a single tool ("Import
wizard") — ADR-097 Decision 4. The system MUST NOT nest a menu node more
than one level of children below a top-level cluster — ADR-097 Decision 6.

#### Scenario: No cluster name is a record type, role, or tool
- **GIVEN** the 6 cluster labels (Bookkeeping, Sales, Purchasing, Banking &
  Cashflow, Taxes, Reporting & Compliance)
- **WHEN** each is checked against ADR-097 Decision 4's four disallowed
  shapes
- **THEN** none matches — each names a business domain

@e2e nav-six-clusters::top-level-entry-count-and-labels

#### Scenario: No menu node exceeds depth 2
- **GIVEN** the restructured effective `menu[]`
- **WHEN** every node's depth from a top-level cluster is measured
- **THEN** no node's children carry their own `children` array (max depth:
  top-level → leaf, or top-level → group → leaf)

@e2e exclude structural manifest assertion, not independently observable in the rendered UI beyond what top-level-entry-count-and-labels already covers — verified by `check:nav-reachability`'s manifest walk and a manifest-structure unit test, not a separate Playwright scenario

### Requirement: REQ-NAVC-006 — Relocations are preferred over removals; any `menu-layout.json#removals` entry MUST be reachability-gate-verified

This change SHOULD implement cluster consolidation via `menu-layout.json#
relocations` (moving nodes, never deleting routes) wherever possible. Where
a duplicate page is deleted (REQ-NAVC-003 MERGE rows), the deletion happens
in `src/manifest.d/*.json` (removing the page and its menu leaf entirely,
since the leaf never existed independently of the page it rendered) rather
than via `menu-layout.json#removals` — `removals` retires a menu ENTRY whose
page still exists elsewhere, which does not apply to a genuinely deleted
duplicate page. If any `removals` entry is added by this change, it MUST
correspond to a route that remains reachable from a surviving entry,
verified by `npm run check:nav-reachability` before the change is
considered complete — repeating the exact per-id check whose absence
orphaned 140 pages the last time `menu-layout.json#removals` was populated
without it (`menu-layout.json`'s own `_removals_note`).

#### Scenario: No removals entry is added without a passing reachability check
- **GIVEN** this change's final `src/menu-layout.json`
- **WHEN** `removals` is non-empty
- **THEN** `npm run check:nav-reachability` has been run against the exact
  same commit and reports zero new orphans

@e2e exclude build-time script assertion — verified by CI's `check:nav-reachability` leg, not Playwright

### Requirement: REQ-NAVC-007 — `check:nav-reachability` MUST pass with zero new orphans, and the 25 baselined IA-gap entries MUST be resolved and pruned

The 25 page ids `nav-reachability-gate`'s seeded baseline flags as
"to be resolved by nav-six-clusters" (design.md §8: the seven index/detail
pairs, `SubsidieAanvragen`, `SubsidieTerugvorderingen`,
`AccountingStandardsPolicy`, `BewaartermijnenDashboard`) MUST each become
menu-reachable by this change (a real menu entry or landing-card deep link,
not a `menu[].query` preset alone unless the page in question is itself the
MERGE-target survivor). Once reachable, each id MUST be removed from
`tests/nav-reachability-baseline.json`'s `exceptions` map — a baseline entry
for an id that is no longer orphaned is a stale exception per
`nav-reachability-gate` REQ-NAVR-003, and this change is the one responsible
for pruning these 25 specifically.

#### Scenario: All 25 IA-gap baseline entries are pruned
- **GIVEN** `tests/nav-reachability-baseline.json` as seeded by
  `nav-reachability-gate`, carrying the 25 IA-gap ids in its `exceptions`
  map
- **WHEN** this change's restructured manifest is checked via `npm run
  check:nav-reachability`
- **THEN** none of the 25 ids appears in the reachability check's orphan
  list, and none of the 25 ids remains a key in `tests/nav-reachability-
  baseline.json#exceptions`

@e2e exclude build-time script + fixture-file assertion, not a UI-observable behavior — verified by CI's `check:nav-reachability` leg, not Playwright

#### Scenario: A resolved IA-gap page is reachable from the UI
- **GIVEN** `SubsidieAanvragen`, previously unreachable from any menu
- **WHEN** a user opens the Reporting & Compliance cluster landing page and
  looks for the Subsidies section
- **THEN** a card or link reaches `SubsidieAanvragen` (directly, or via a
  `?state=...` preset if design.md §4's per-page config read finds it should
  be a preset rather than its own card)

@e2e nav-six-clusters::preset-deep-links-resolve

### Requirement: REQ-NAVC-008 — `check:manifest-budget` MUST pass and the byte-impact MUST be measured and reported

This change MUST run `node tests/check-manifest-budget.js` before and after
the restructure and report both totals (and the delta) in the PR
description. The after-total MUST be at or under the existing budget.

#### Scenario: Manifest budget passes after the restructure
- **GIVEN** the restructured `src/manifest.json` + `src/manifest.d/*.json`
- **WHEN** `node tests/check-manifest-budget.js` runs
- **THEN** it exits 0 (`PASS`)

@e2e exclude build-time script assertion, not a UI-observable behavior — verified by CI's `check:manifest-budget` leg, not Playwright

### Requirement: REQ-NAVC-009 — The change MUST ship e2e coverage for the cluster count, landing pages, and preserved deep links

Per design.md §11, `tests/e2e/NavSixClusters.spec.js` MUST assert: (a) the
rendered top-level menu shows exactly 7 entries with the expected labels;
(b) each of the 6 cluster landing pages renders its card grid; (c) a sample
of relocated and consolidated pages (at minimum `GeneralLedger`,
`AnalyticalDimensions` with a `dimensionType` query preset, `KorDashboard`,
`Receipts`, `Reconciliations`, `Aansluitingen`, `Contracts`,
`RevenueContracts`) still resolve by direct route navigation; (d) the 4
deleted dangling dialog pages' routes no longer resolve.

#### Scenario: The full e2e spec passes
- **GIVEN** the restructured, deployed shillinq frontend
- **WHEN** `tests/e2e/NavSixClusters.spec.js` runs
- **THEN** every scenario in the spec passes: entry count/labels, all 6
  landing pages, all sampled deep links, and the 4 deleted routes'
  non-resolution

@e2e nav-six-clusters::top-level-entry-count-and-labels
@e2e nav-six-clusters::cluster-landing-pages-render
@e2e nav-six-clusters::preset-deep-links-resolve
@e2e nav-six-clusters::deleted-dialog-routes-gone

### Requirement: REQ-NAVC-010 — Non-goals

This change MUST NOT change any OpenRegister schema, MUST NOT rename any
page `id` or `route` (every surviving page keeps its existing route — only
menu placement and page-SET membership change), MUST NOT perform a deep
re-home of the Payroll content beyond the placement recorded in design.md §7
(a dedicated HR home is Wave 2 scope), MUST NOT make ANY change — including a
mere relocation — to the `ExternalConnections` surface (the 15
adapter-family settings-foldout entries and `src/manifest.d/external-
adapters-w8.json`), and MUST NOT author the ADR-097 amendment itself
(design.md §13 — that is a hydra-repo artifact, handed back to the
orchestrator as a cross-repo follow-up task).

#### Scenario: No page route changes
- **GIVEN** this change's full diff
- **WHEN** every page's `route` field before and after is compared
- **THEN** every route that existed before this change still exists,
  unchanged, after it (pages deleted per REQ-NAVC-003/§6 are the only
  exception, and each is accounted for in design.md §4/§6)

@e2e exclude structural manifest diff assertion, not independently UI-observable beyond what `preset-deep-links-resolve` and `deleted-dialog-routes-gone` already cover — verified by manifest diff review, not a separate Playwright scenario

#### Scenario: ExternalConnections is untouched, to avoid a cross-branch manifest conflict
- **GIVEN** this change's full diff and the confirmed Wave 2 change
  `integration-config-to-openconnector` (branch `feat/integration-config-to-
  openconnector`), which owns the full collapse of this surface
- **WHEN** `src/manifest.d/external-adapters-w8.json` and the 15
  `ExternalAdapter*` ids in `menu-layout.json#settingsSection` are inspected
  before and after this change
- **THEN** neither the fragment file's content nor any of the 15 ids' menu
  placement changed — this change made zero edits there

@e2e exclude structural manifest diff assertion — verified by manifest diff review (confirming zero touched lines in `external-adapters-w8.json`), not a Playwright scenario
