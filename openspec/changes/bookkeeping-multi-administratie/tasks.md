# Tasks — Multi-Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately
> out of scope here. The tasks below describe the work an `opsx-apply` cycle will
> execute against the `bookkeeping-multi-administratie` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm that no `Administratie`, `AdministratielidMaatschap`,
  `IntercompanyJournaalpost`, `ConsolidatieMapping`, `AdministratieMigratie` schemas
  already exist (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`,
  `adr-000-data-model.md`).

- [ ] Task 2: Author `specs/bookkeeping-multi-administratie/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundational refactor)` /
  `Depends on: none (foundational; blocks all downstream)` header, `REQ-MA-NNN`
  requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN,
  and explicit Excluded section.

- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and
  including Affected Projects (shillinq + 60+ schema refactors; openregister;
  downstream bookkeeping specs) / Scope (5 new registers + FK patches + UI component +
  RBAC + backup per-administratie) / Risks (query-layer migration burden, backup
  infrastructure, fiscal-unit correctness, single-administratie migration) / Rollback
  / Open Questions per hydra `rules.proposal`.

- [ ] Task 4: Author `design.md` with Decisions (administratie as first-class
  register, mandatory FK on all financial schemas, user roles per-administratie,
  administratie-switcher UI, intercompany-journaalpost mirroring, consolidatie-mapping
  pre-positioning, administratie-migratie audit trail) and Reuse Analysis table per
  hydra `rules.design`.

- [ ] Task 5: Declare the `Administratie` schema in `lib/Settings/shillinq_register.json`
  with REQ-MA-001/MA-002 fields (administrationCode, naam, rechtsvorm, kvk_nummer,
  btw_nummer, loonheffingsnummer, boekjaar_start_maand, afwijkend_boekjaar,
  presentatievaluta, functionele_valuta, btw_regime, btw_aangifte_frequentie,
  chart_of_accounts, moederadministratie, dochters, consolidatie_mapping,
  consolideren_in, consolidatiemethode, fiscale_eenheid_vpb, fiscale_eenheid_btw,
  actief_vanaf, actief_tot, status, backup_schema, data_retentie_jaren,
  default_taal); declare RBAC roles (eigenaar, controller, boekhouder, etc.);
  `administrationId` field is unique + indexed.

- [ ] Task 6: Declare the `AdministratielidMaatschap` schema with REQ-MA-003 fields
  (gebruiker FK, administratie FK, rol enum, toegangsbeperking_grootboek array,
  mag_journaalposten_boeken boolean, mag_jaarafsluiting_doen boolean, geldig_van,
  geldig_tot, verleend_door, verleend_op); composite unique key on
  (gebruiker, administratie); `x-openregister-relations` FK to both Administratie
  and User (scope deferred to impl).

- [ ] Task 7: Declare the `IntercompanyJournaalpost` schema with REQ-MA-004 fields
  (intercompany_nummer unique + indexed, datum, soort enum, bron_administratie FK,
  doel_administratie FK, bron_journaalpost FK, doel_journaalpost FK, bedrag,
  valuta, wisselkoers, omschrijving, btw_behandeling, geconsolideerd_elimineren,
  eliminatie_rekening, status enum, afwijking_bedrag); status transition logic
  (concept → gekoppeld → bevestigd_beide → eliminatie_geboekt) declared as
  `x-openregister-lifecycle` preconditions.

- [ ] Task 8: Declare the `ConsolidatieMapping` schema with REQ-MA-005 fields (naam,
  bron_administratie FK, doel_administratie FK, regels JSON array with
  bron_rekening/doel_rekening/omschrijving objects, eliminatie_rekening_intercompany,
  valutaomrekening_methode enum, geldig_van); store mapping as JSON (no separate
  line-item schema; declarative per ADR-031).

- [ ] Task 9: Declare the `AdministratieMigratie` schema with REQ-MA-006 fields
  (migratienummer unique + indexed, datum, bron_administratie FK, doel_administratie
  FK, soort enum, objecten UUID array, boekwaarde_overdracht, marktwaarde_overdracht,
  verschil_naar_resultaat, fiscale_behandeling enum, juridische_grondslag,
  documenten file-array, bron_journaalpost FK, doel_journaalpost FK, status enum);
  lifecycle transitions (voorbereid → uitgevoerd → geboekt_beide → teruggedraaid) as
  `x-openregister-lifecycle` preconditions.

- [ ] Task 10: Additively patch ALL financial schemas (Journaalpost, Factuur,
  Crediteur, Debiteur, GrootboekRekening, Budget, Verplichting, VastActief,
  Creditnota, Debitnota, etc. — audit `adr-000-data-model.md` for complete list)
  with mandatory `administratie: "uuid|ref:Administratie"` field; no backwards-
  compatibility shim (required field enforces correctness); existing schemas get
  `x-openregister-relations` FK to Administratie; indexed for query performance.

- [ ] Task 11: Wire administrative isolation at RBAC layer: extend login/session
  context to populate `sessionAdministrations` (list of accessible administraties +
  user's role in each), set `sessionActiveAdministratie` (default: first or last-used),
  track in session cookie; implement `AdministratieSwitcher` action to validate user
  has AdministratielidMaatschap record for target administratie before switch, update
  session context, redirect to home with new administratie context.

- [ ] Task 12: Implement administratie-aware query filtering on all query layers
  (PHP Repository/QueryBuilder, API endpoints, GraphQL resolvers): every SELECT
  MUST include `WHERE administratie = $activeAdministratie`; implement standardized
  `FilterByAdministration($uuid)` helper method on QueryBuilder; REQ-MA-001 scenario:
  queries must return empty if administratie not in user's accessible list; REQ-MA-001
  masked 404: non-administratie-owned data returns 404 not 403.

- [ ] Task 13: Author `src/components/AdministratieSwitcher.vue` component:
  render dropdown or pill-bar under app header; list all user's accessible
  administraties with current administratie highlighted; on selection, call API to
  validate access, update session, redirect to home; support keyboard navigation
  (arrow keys, enter); show count of accessible administraties; hide if user has
  access to only one administratie.

- [ ] Task 14: Update repair/migration step in `lib/Migration/` to create default
  `Administratie` on fresh install with seed data (administrationCode="ADM-001",
  naam="Standaard administratie" localized, rechtsvorm="bv" default, status="actief",
  boekjaar_start_maand=1, btw_regime="standaard", backup_schema="dagelijks",
  data_retentie_jaren=7); for existing installs upgrading, create default
  administratie and migrate all orphaned financial data (without administratie FK)
  to it idempotently (no duplicates on re-run).

- [ ] Task 15: Extend per-administratie backup routine: `Administratie.backup_schema`
  (dagelijks, wekelijks, aanvragen) routes backup execution; implement incremental
  backup per administratie (backup scheduler queries Administratie.backup_schema for
  each administratie, executes independently, no cross-administratie data in any
  backup file).

- [ ] Task 16: Implement administratie-scoped export/Auditfile XAF generation per
  REQ-MA-007: export endpoint accepts administratieId parameter; queries all data
  filtered by that administratie only; generates XAF XML per Auditfile standard with
  administratie's KvK as entity ID; ZIP includes jaarrekening, journaalposten,
  balances, attached documents; no cross-administratie leakage.

- [ ] Task 17: Implement administratie archival workflow per REQ-MA-007:
  `Administratie.status` transition from `actief` to `gearchiveerd` prevents writes
  (all POST/PUT/DELETE fail with "administratie gearchiveerd"); reads still work
  (pull historical data); archival timestamped in auditTrail; data_retentie_jaren
  counter starts on archival.

- [ ] Task 18: Wire intercompany-journaalpost mirroring per REQ-MA-004: on
  journaalpost creation with `doel_administratie` reference, system (1) creates
  IntercompanyJournaalpost record (status=concept), (2) auto-creates mirrored
  journaalpost in destination administratie with opposite debit/credit, (3) links both
  via intercompany_nummer; validation: both sides must balance (or afwijking_bedrag
  tracks variance); status transitions: concept (one-sided) → gekoppeld (both exist)
  → bevestigd_beide (both confirmed) → eliminatie_geboekt (consolidation processed).

- [ ] Task 19: Pre-position consolidation-mapping hooks per REQ-MA-005 (consumed by
  future `bookkeeping-consolidatie` spec): ConsolidatieMapping register is queryable;
  mapping rules are applied during consolidation export (deferred to that spec);
  intercompany-journaalpost field `geconsolideerd_elimineren` marks P&L lines for
  elimination at consolidation time; eliminate via `eliminatie_rekening` (accounting
  for elimination adjustments); no consolidation-export logic lives in this spec
  (purely schema + data structure).

- [ ] Task 20: Pre-position administratie-migratie hooks per REQ-MA-006 (asset/contract
  /employee transfer): AdministratieMigratie register stores transfer metadata
  (boekwaarde, marktwaarde, juridische_grondslag, fiscal_behandeling); UI allows
  initiating a migration (select object, target administratie, enter grondslag);
  system drafts paired journaalposten (not yet posted); user reviews and confirms;
  both posts post simultaneously or both roll back (atomic); status tracking
  (voorbereid → geboekt_beide) prevents mid-process edits; reversal supported
  (status=teruggedraaid undoes both posts).

- [ ] Task 21: Update RBAC configuration to support per-administratie roles: extend
  user profile to store list of (administratie, role) pairs; extend permission
  checks to accept `administrationId` parameter (e.g.,
  `canPostJournalEntry(administrationId, role)`); verify mag_journaalposten_boeken
  permission in the active administratie's AdministratielidMaatschap record.

- [ ] Task 22: Add administratie-scoped audit trail queries per REQ-MA-009: audit
  logs (auditTrail field on each entity) are filtered by active administratie in
  single-administratie queries; implement cross-administratie audit query (for
  holding-controllers) that aggregates auditTrail from all accessible administraties
  with administratie column; results grouped/flagged per administratie.

- [ ] Task 23: Add Administraties navigation + pages to `src/manifest.json`: entry
  under `Configuration > Administraties` with `type: index` page (list all
  administraties with status, kvk, boekjaar); matching `type: detail` page
  (view/edit administratie record with dochters list, moederadministratie link,
  consolidatie-mapping, backup schedule, archival control); manifest validation
  (`node tests/validate-manifest.js`) must exit 0.

- [ ] Task 24: Update `openspec/architecture/adr-000-data-model.md` with reconciliation
  notes: (1) introduce Administratie as foundational multi-tenant boundary, (2) note
  that all 60+ financial schemas now carry mandatory administratie FK, (3) describe
  AdministratielidMaatschap as user-administratie-role join, (4) explain
  IntercompanyJournaalpost mirroring semantics, (5) position ConsolidatieMapping as
  pre-positioned for consolidatie spec, (6) position AdministratieMigratie as
  asset-transfer audit trail.

## Verification

`openspec validate` must exit clean on the change folder. Peer review across three
dimensions:

1. **Bookkeeper persona**: Confirms administratie isolation (no cross-administratie
   data leakage in queries/reports), intercompany-journaalpost mirroring matches
   real-world practice, per-administratie backup/archival lifecycle makes sense,
   administratie-switcher UI is intuitive.

2. **Architecture reviewer**: Confirms ADR-024 (schemas registered in manifest),
   ADR-031 (declarative schema metadata, no service classes), ADR-018 (multi-tenant
   data isolation pattern), multi-administratie RBAC pattern, no `AllocationService`
   or parallel administratie storage (all or-managed).

3. **Refactor-impact reviewer**: Confirms all 60+ financial schemas have mandatory
   `administratie` FK, all queries filter by active administratie, backup/export
   routines are per-administratie, repair step migrates existing single-administratie
   data correctly.

No source code changes outside `openspec/changes/bookkeeping-multi-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests**:
  - Administratie CRUD (create, read, update, archive, delete).
  - AdministratielidMaatschap role assignment and revocation.
  - Query filtering by administratie (verify no cross-administratie leakage).
  - Intercompany-journaalpost mirroring (create one, verify two posts exist, status
    tracking, afwijking_bedrag reconciliation).
  - ConsolidatieMapping validation (dekking rule validation against chart-of-accounts).
  - AdministratieMigratie atomic dual-post (both post or both fail).
  - Administratie archival (read allowed, writes rejected).
  - Repair-step idempotency (running migration twice creates no duplicates).

- **Playwright MCP browser tests**:
  - Administratie-switcher component (render, keyboard nav, session context update).
  - Login → session retrieves all accessible administraties.
  - Administratie-scoped query results (filter by active administratie).
  - Masked 404 on unauthorized administratie access.
  - Export-scope validation (XAF export contains only target administratie data).
  - Administratie archival UI (status transition, button disabling).

- **Hydra gates** (automated CI checks):
  - `hydra-gate-administratie-isolation`: Scan all query methods for explicit
    `WHERE administratie = ?` filter; fail if any query lacks it.
  - `hydra-gate-administratie-fk-mandatory`: Scan all financial schemas in
    `adr-000-data-model.md` for mandatory `administratie` FK; fail if any missing.

- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/multi-administratie.md` per ADR-030 journeydoc
  convention: multi-administratie overview, administratie creation wizard walkthrough,
  user role assignment, administratie-switcher usage, intercompany-transaction flow,
  per-administratie backup/export.
- Screenshot: administratie-switcher dropdown.
- Screenshot: administratie list (index page).
- Screenshot: administratie detail (showing dochters, moederadministratie,
  consolidatie-mapping, archival control).

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Administration`, `Administratie`
- `Create Administration`, `Administratie aanmaken`
- `Archive Administration`, `Administratie archiveren`
- `Archived` (status), `Gearchiveerd`
- `Administrator`, `Eigenaar`
- `Controller`, `Controller`
- `Bookkeeper`, `Boekhouder`
- `Viewer`, `Inkijker`
- `External Accountant`, `Accountant extern`
- `Intercompany Transaction`, `Intercompany journaalpost`
- `Consolidation Mapping`, `Consolidatie mapping`
- `Fixed Asset Transfer`, `Activaoverdracht`
- `Switch Administration`, `Administratie wisselen`
- `Accounting Period`, `Boekhoudperiode`
- `Backup Schedule`, `Backup planning`
- `Data Retention`, `Gegevensretentie`
- `Fiscal Unit (VPB)`, `Fiscale eenheid (VPB)`
- `Fiscal Unit (VAT)`, `Fiscale eenheid (BTW)`
