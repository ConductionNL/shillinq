# Change: nav-six-clusters

## Why

ADR-097 (`hydra/openspec/architecture/adr-097-navigation-budget.md`) puts
shillinq at **16 raw top-level menu groups / 122 entries / 228 pages** in its
fleet census and singles it out, with scholiq, as needing structural work —
"not Decision 1 [count], Decision 5 [role-lens duplication]" — before anyone
starts moving menu entries. Decision 5 measurement is done (this change is
the "starts moving menu entries" step it gated on).

Measured against the **real** `buildManifest(base, fragments, menuLayout)`
pipeline (`@conduction/nextcloud-vue/src/utils/buildManifest.js`, same
mechanism `nav-reachability-gate` uses — never a re-implementation) run
against this repo's actual `src/manifest.json` + 81 `src/manifest.d/*.json`
fragments + `src/menu-layout.json` (sibling checkout with `node_modules`
installed, re-verify with a real `npm ci` before implementing, per the same
caveat `nav-reachability-gate/design.md` §3 already recorded for this
manifest):

- **595** pages, **88** raw top-level menu groups pre-relocation, **358**
  total menu nodes at every depth, **29** top-level entries in `section:
  "main"` (excluding `type: "caption"`) after `menu-layout.json` relocations
  apply — the ADR-097 counting basis exactly. Confirms the fleet-census row.
- **27 schemas carry more than one `type: "index"` page** — 64 duplicate
  index pages total (`shillinq::Subsidie` ×6, `shillinq::InventoryStock` ×4,
  `shillinq::AnalyticalDimension` / `Account` / `Verplichting` /
  `KORRegistration` ×3 each, 22 more ×2) — ADR-097 Decision 5's "same schema,
  different hardcoded filter, is one page" pattern, at the scale the ADR
  predicted.
- Beyond raw duplication, the effective menu contains **structural defects**
  that a naive "flatten to 6 groups" pass would carry forward unchanged:
  - **Two menu nodes share the id `PurchaseOrders`** — a top-level group (2
    children: `ThreeWayMatchIndex`, `VendorPerformanceIndex`) and, separately,
    a leaf inside the `Purchasing & Inventory` group pointing at the actual
    `PurchaseOrders` index page. Same shape for `Verplichtingen`
    (`Commitments`): a top-level group AND a `Purchasing` leaf share the id.
  - The top-level `DBACompliance` group (6 children: `DBAOpdrachten`,
    `DBAIntakes`, `DBAModelovereenkomsten`, `DBAPortfolioRisicos`,
    `DBAEvidenceDossiers`, `DBARisicoflags`) is a **full duplicate**, schema
    for schema, of the DBA cards already living under `Compliance` →
    `ReportingComplianceOverview` (`DBAIntakeWizard`, `DBAPortfolioDashboard`,
    `DBAEvidenceBrowser`, `DBAModelovereenkomstRegister`) — 4 of the 27
    duplicate-index schemas are this one group pair.
  - The `Payroll` top-level group id renders with the label **"People &
    Projects"** and holds only payroll content (`Werkgevers`, `Werknemers`,
    `Loonperiodes`, `Loonstroken`, `LHAfdrachten`, `Loonjournaalposten`,
    `ExpenseSettlementClassifier`) — a stale label from before "Projects" was
    split out into its own `Projecten` group.
  - The base manifest's `ExternalConnections` top-level group has zero
    children of its own; every adapter-status leaf that would populate it is
    lifted straight into the settings foldout by `menu-layout.json`'s
    `settingsSection`, so the group is invisible today (pruned as an
    empty-shell by `applyMenuRemovals`'s children-length rule) — "External
    Connections" is a phantom entry, not a live top-level.
  - `ARAging` (an Accounts Receivable aging report) sits under `Bookkeeping`,
    detached from `AccountsReceivable` itself, which lives under `Sales`.
    `GRConsolidated` ("GR" = *Gemeenschappelijke Regeling*, a joint
    public-body arrangement — confirmed by its sibling `GRDeelnemers`
    "Participants") sits under `Bookkeeping` though its content is
    public-sector reporting, not general ledger.
  - `openspec/specs/shillinq-nav-ia-cleanup/spec.md` REQ-NAVIA-002 states the
    `Project` schema's only nav home is `Bookkeeping > Projects`, with
    `ProjectenOverzicht` retired via `menu-layout.json#removals`. That premise
    is **stale**: `removals` is empty today (emptied 2026-08-10 per
    `menu-layout.json`'s own `_removals_note` — the 160-entry list, which
    would have carried this retirement, was withdrawn wholesale because 140
    of its entries orphaned pages). `buildManifest()` confirms
    `ProjectenOverzicht` is live today, alongside `Utilisatie`, under the
    `Projecten` group — the exact duplicate REQ-NAVIA-002 was written to
    prevent has silently come back.
  - **4 pages exist that nothing can open**: `config.createDialog` is set on
    four index pages (`VATReturns` → `VATReturnCreateDialog`,
    `ReimbursementPolicies` → `ReimbursementPolicyCreateDialog`,
    `PassThroughMarkupRules` → `PassThroughMarkupRuleCreateDialog`,
    `RetainerPools` → `RetainerPoolCreateDialog`), but no component anywhere
    in `src/` (`grep -rln createDialog src --include=*.vue --include=*.js`,
    excluding `manifest.d`) reads that config key. These are not
    "opened from an action button" as `nav-reachability-gate/design.md`'s
    hypothesis guessed — they are genuinely dead, unreachable pages.
- `nav-reachability-gate`'s ratchet baseline (once merged — see Dependency
  below) will carry 25 "IA gap" entries explicitly flagged as "to be resolved
  by nav-six-clusters": seven index/detail pairs with no menu entry anywhere
  (`RateCards`/`RateCardDetail`, `RateSchedules`/`RateScheduleDetail`,
  `RateAuditTrail`/`RateRecordDetail`, `AansluitingResultaten`/
  `AansluitingResultDetail`, `WBSOActivityCodes`/`WBSOActivityCodeDetail`,
  `Deposits`/`DepositDetail`, `InnovatieboxElections`/
  `InnovatieboxElectionDetail`), plus `SubsidieAanvragen`,
  `SubsidieTerugvorderingen`, `AccountingStandardsPolicy`, and
  `BewaartermijnenDashboard`. This restructure is the one place in the
  programme where that IA-gap work has a natural home — every one of these
  is a page that needs a landing-card query-preset link, not a fresh feature.
- `check:manifest-budget` has **2,927 bytes** of headroom (measured against
  the `fix/setup-wizard-english` baseline this change lands on top of — PR
  #912, open, translates 15 Dutch titles and disambiguates `Aansluitingen` →
  "Tie-outs" as a genuinely distinct feature from "Reconciliations", not a
  duplicate to merge — see design.md §0). Consolidating up to 64 duplicate
  index pages is this program's best opportunity to buy that headroom back
  for later changes, not spend the remainder.

## What Changes

- **ADDED** capability `nav-clusters` (full text in
  `specs/nav-clusters/spec.md`): the effective main menu collapses from 29
  top-level entries to Dashboard (ADR-097-exempt) + exactly 6 domain
  clusters — Bookkeeping, Sales, Purchasing, Banking & Cashflow, Taxes,
  Reporting & Compliance — each satisfying ADR-097 Decision 4 (names a
  domain, not a role/record-type/lifecycle-stage/tool) and each getting an
  ADR-097 Decision-4-shaped landing page (cards-collapse pattern, following
  `ReportingComplianceOverview`/`src/components/reporting/
  ReportingComplianceOverview.vue` as the existing precedent). Depth stays
  capped at 2. Full cluster→children mapping and the per-schema consolidation
  verdict for all 27 duplicate-index schemas are in `design.md`.
- **MODIFIED** `openspec/specs/shillinq-nav-ia-cleanup/spec.md` REQ-NAVIA-002
  (delta in `specs/shillinq-nav-ia-cleanup/spec.md`): replaces the stale
  "sole home is `Bookkeeping > Projects`" premise with the actual outcome of
  this change (`Project`'s canonical home moves into the Bookkeeping cluster
  under a consolidated `Projects` page; `ProjectenOverzicht` and `Utilisatie`
  fold into it as relocations, not removals — see design.md §5, schema
  `Project`).
- **REMOVED** the 4 dangling `config.createDialog` pages and their config
  references (`VATReturnCreateDialog`, `ReimbursementPolicyCreateDialog`,
  `PassThroughMarkupRuleCreateDialog`, `RetainerPoolCreateDialog` — see
  design.md §6 for why deletion, not wiring, is the correct call for a
  navigation-scoped change).
- **CHANGED** `src/manifest.json`, every affected `src/manifest.d/*.json`
  fragment, and `src/menu-layout.json` (relocations preferred throughout;
  `removals` used only where design.md's per-schema table shows the retired
  id's route survives through another surviving entry, per ADR-044 and
  gate-53's own lesson about the previous 140-page orphaning).
- **Explicitly out of scope** (full non-goals list in
  `specs/nav-clusters/spec.md` REQ-NAVC-010): no OpenRegister schema changes,
  no route/id renames (only menu placement and page-set membership change —
  every surviving page keeps its route), no Payroll *deep* re-homing (it
  lands in a defensible cluster now; a dedicated HR home is Wave 2, tracked
  separately — see design.md §7), **no `ExternalConnections` change of any
  kind — not even a relocation** (frozen as-is; a confirmed Wave 2 change,
  `integration-config-to-openconnector`, owns its full collapse — see
  design.md §7 and Impact), and the ADR-097 amendment itself is **not**
  authored by this change (hydra repo scope — see Impact).

## Impact

- Affected specs: new capability `nav-clusters`
  (`specs/nav-clusters/spec.md`); `shillinq-nav-ia-cleanup` REQ-NAVIA-002
  modified (`specs/shillinq-nav-ia-cleanup/spec.md`). REQ-NAVIA-001,
  -003..-008 are unaffected and this change's tasks re-verify each still
  holds (single-active-nav-leaf, deep-link preservation, no duplicate
  detail-route registration — the exact defect classes a menu restructure of
  this size is most likely to reintroduce).
- Affected code: `src/manifest.json`, `src/manifest.d/*.json` (most of the 81
  fragments touch at least one menu placement), `src/menu-layout.json`, up to
  6 new landing-page Vue components under `src/components/<cluster>/` +
  `src/registry.js` entries, `tests/e2e/*.spec.js` (new cluster-landing +
  deep-link specs).
- **Dependency: this change depends on `nav-reachability-gate` merging
  first.** `nav-reachability-gate` has not landed yet as of this writing
  (`tests/validate-nav-reachability.js` and `tests/nav-reachability-
  baseline.json` do not exist in this repo). A restructure this size is not
  safe to attempt without that mechanical backstop — `nav-reachability-gate/
  proposal.md` says so explicitly, and this change's own tasks.md task 0
  refuses to proceed past the design-freeze step until `npm run
  check:nav-reachability` exists and is green against the pre-restructure
  baseline.
- **Sequencing with `fix/setup-wizard-english` (PR #912, open).** This
  change's design assumes #912's outcome as its starting point (English
  titles fleet-wide, `Aansluitingen` → "Tie-outs" kept distinct from
  "Reconciliations", 2,927 bytes of manifest-budget headroom) rather than
  the Dutch-titled `development` HEAD measured here. If #912 has not merged
  by the time this change is implemented, re-run design.md's byte and
  duplicate-schema measurements against whatever `development` actually
  contains at that point — the counts in this proposal and design.md are
  dated 2026-08-19 and will drift.
- **Sequencing with `integration-config-to-openconnector` (branch
  `feat/integration-config-to-openconnector`, spec committed 2026-08-19).**
  This confirmed Wave 2 change owns the full collapse of the
  `ExternalConnections` surface (15 adapter-family pages + 1 index → 1
  roster page reusing the `ExternalAdaptersStatus` id/route, ~9,920 bytes
  freed) and lands AFTER this change. **This change deliberately makes zero
  edits to `src/manifest.d/external-adapters-w8.json` or to any of the 15
  `ExternalConnections` settings-foldout entries** — not even a relocation —
  specifically to avoid a manifest-fragment conflict between the two
  branches (design.md §7). The ~9,920-byte saving is Wave 2's own headroom
  contribution, not counted anywhere in this change's byte-budget estimate
  (design.md §9); this change's budget must close using only its own
  deletions against the current 2,927-byte headroom.
- **Cross-repo, explicitly flagged**: the ADR-097 amendment naming every
  demotion this change makes (Decision 1's "a seventh requires an
  amendment... naming what it demotes and why nothing could be" — inverted
  here, since this change goes from 29 to 6, but the same accountability
  applies to *what got folded into what*) is a **hydra-repo** artifact
  (`hydra/openspec/architecture/`), not a shillinq one. `tasks.md` task 9
  generates the demotion list and hands it back to the orchestrator as a
  cross-repo follow-up; it is not implemented by this change's own tasks.
