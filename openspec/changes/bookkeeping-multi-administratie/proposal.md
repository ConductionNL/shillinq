# Proposal: bookkeeping-multi-administratie

`kind: refactor` — foundational multi-tenancy refactor introducing `administratie`
as a first-class isolation boundary across all financial schemas. No feature
addition; reshapes the core data model for holding/werkmij and multi-client
accounting firms.

## Summary

Introduce **multi-administratie (multi-tenant) architecture** for Shillinq as the
foundational refactor enabling per-company bookkeeping within a single OpenRegister
installation. This change declares five new registers (`Administratie`,
`AdministratielidMaatschap`, `IntercompanyJournaalpost`, `ConsolidatieMapping`,
`AdministratieMigratie`), extends ALL financial schemas additively with mandatory
`administratie` foreign key, declares per-administratie `chart_of_accounts` /
`boekjaar` / `btw_regime` / `presentatievaluta` independence, wires administratie-aware
RBAC (user may have different roles per administratie), adds administratie-switcher
UI component, and pre-positions consolidation-mapping hooks for the future
`bookkeeping-consolidatie` spec. This is the prerequisite refactor for both
holding-controller views and multi-client accounting firm tenancy.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure,
OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step
seeding. It is the largest single refactor in Shillinq's roadmap, affecting 60+ entity
schemas across all bookkeeping tiers (T1–T4).

## Motivation

On 2026-05-22, every Shillinq installation runs exactly one administration per
OpenRegister database. For a mid-market accounting firm with 50+ client-administraties,
this means 50 separate OpenRegister instances, 50 backups, 50 access-control matrices,
and no cross-administratie reporting. For a personal holding (Beheer B.V.) owning
Werk B.V., the holding-controller cannot see both administraties side-by-side without
logging out and back in.

The Dutch MKB majority operates in holding-werkmij structures (law firms, engineering
firms, real-estate holding, family offices) or decentral-government structures
(municipalities acting as penvoerder for gemeenschappelijke regelingen with multiple
administraties). Both need: isolated chart-of-accounts per administratie, user
role-per-administratie, fast in-session administratie-switcher, consolidated P&L
across administraties, and intercompany-transaction audit trail.

This proposal refactors Shillinq's core to make `administratie` a first-class isolation
boundary — equivalent in design to the "tenant" pattern in modern SaaS. Administraties
within one installation share infrastructure (backup, RBAC engine, consolidation rules)
but NOT financial data (journaalposten, balances, KvK identity). This unblocks both
the multi-client accounting firm use case and the holding-werkmij consolidated view
use case simultaneously.

## Affected Projects

- [x] Project: shillinq — adds 5 new registers/schemas (`Administratie`,
  `AdministratielidMaatschap`, `IntercompanyJournaalpost`, `ConsolidatieMapping`,
  `AdministratieMigratie`); adds mandatory `administratie` FK to 60+ existing
  financial schemas; adds administratie-aware RBAC context on user login; adds
  administratie-switcher UI component under `src/components/`; adds per-administratie
  backup / export / archival lifecycle; extends `Account` schema with
  `administrationId` FK; updates repair steps for `administratie` seeding on
  fresh install.
- [ ] Project: openregister — no source changes; this change consumes `x-openregister-lifecycle`
  (administratie archival state), `x-openregister-relations` (FK validation),
  `x-openregister-rbac` (per-administratie roles), and multi-key data-isolation
  patterns.
- [ ] Project: all other bookkeeping specs (T1–T4) — each MUST migrate existing
  financial schemas to include `administratie: "uuid|ref:Administratie"` as mandatory
  field during the parallel refactor cycle.

## Scope

### In Scope

- One new capability spec (`bookkeeping-multi-administratie`) — see the `specs/`
  folder.
- `Administratie` register: juridisch-onafhankelijke boekhouding entity with
  kvk_nummer, btw_nummer, loonheffingsnummer, boekjaar-config, chart-of-accounts
  reference, fiscale-eenheid references (VPB, BTW), consolidatie-destination, and
  backup/archival lifecycle state.
- `AdministratielidMaatschap` register: user-to-administratie mapping with role
  (`eigenaar`, `controller`, `boekhouder`, `inkijker`, `accountant_extern`,
  `salarisadministrateur`, `debiteurenadmin`, `crediteurenadmin`) and access
  restrictions (grootboek account ranges, journal-posting rights, year-closing rights).
- `IntercompanyJournaalpost` register: paired journaalpost across two administraties
  with automatic dual-side boeking, intercompany-numbering, status tracking
  (concept → gekoppeld → bevestigd_beide → eliminatie_geboekt), valuta-omrekening,
  and consolidatie-elimination flags.
- `ConsolidatieMapping` register: rekening-mapping from dochter to moeder
  chart-of-accounts for consolidatie-exports; eliminatie-rekening references; valuta-
  omrekening method.
- `AdministratieMigratie` register: asset/contract/werknemer overdracht between
  administraties with boekwaarde vs. marktwaarde, juridische grondslag audit trail,
  and paired journaalpost references on both sides.
- Mandatory `administratie` FK on all financial schemas: `Journaalpost`, `Factuur`,
  `Crediteur`, `Debiteur`, `GrootboekRekening`, `Budget`, `Verplichting`, `VastActief`.
- Administratie-aware RBAC: on login, user retrieves list of administraties + roles;
  UI renders administratie-switcher component; all subsequent queries filtered by
  active administratie context.
- Administratie-switcher component: dropdown or pill-bar under the app header,
  allowing same-session administratie switch without re-login.
- Per-administratie backup / export / archival: `administratie.backup_schema` (dagelijks,
  wekelijks, aanvragen); `administratie.status` (actief, gearchiveerd, in_liquidatie,
  opgeheven); incremental backup per administratie; Auditfile XAF export per
  administratie.
- Per-administratie audit trail: `auditTrail` field on each entity filtered by active
  administratie; cross-administratie audit queries for holding-controllers.

### Out of Scope

- **Implementation code** — this is a spec-only change. Schema declarations and RBAC
  wiring are deferred to the implementing cycle.
- **Consolidation reporting** — REQ-MA-005 (mapping + elimination) pre-positions the
  hooks, but the actual consolidated P&L/balance-sheet rendering is a separate spec
  (`bookkeeping-consolidatie`).
- **Migration tooling from 1:1 instance model to multi-administratie** — deferred to
  an `openspec` migration-utility change after implementation lands.
- **Fiscal-unit enforcement** — the schema captures `fiscale_eenheid_vpb` and
  `fiscale_eenheid_btw` references, but VPB/BTW consolidation logic is delegated to
  the `bookkeeping-vat-return` and `bookkeeping-tax-filing` specs.
- **KvK pre-fill via OpenConnector** — out of scope for spec; the implementing cycle
  may wire the integration.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-multi-administratie`** — declares the five new administratie-family
registers, defines per-administratie independence (chart-of-accounts, boekjaar,
btw-regime), specifies administratie-aware RBAC, intercompany-journaalpost semantics,
consolidatie-mapping pre-positioning, and administratie-migratie audit trail.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-MA-*` for traceability.

## New Dependencies

None beyond existing OR abstractions. Consumes `x-openregister-lifecycle`,
`x-openregister-relations`, `x-openregister-rbac`, data-isolation patterns.

## Impact

- `lib/Settings/shillinq_register.json` — adds 5 schemas (`Administratie`,
  `AdministratielidMaatschap`, `IntercompanyJournaalpost`, `ConsolidatieMapping`,
  `AdministratieMigratie`); adds mandatory `administratie` FK patches on 60+ existing
  financial schemas.
- `src/components/AdministratieSwitcher.vue` — new UI component for in-session
  administratie switching.
- `lib/Migration/` — updates repair steps to seed default `Administratie` on fresh
  install; idempotent migration for existing single-administratie installs.
- `lib/Authorization/` — extends RBAC context to track active administratie + user's
  roles per administratie; all queries filter by active administratie.
- `src/manifest.json` — adds Administraties index/detail navigation under
  `Configuration > Administraties`.
- All query/API layers — filter by active `administratie` context.
- Backup/export routines — execute per-administratie, not per-installation.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (administratie state
  transitions: actief → gearchiveerd → opgeheven), `x-openregister-relations` (FK
  validation across registers), `x-openregister-rbac` (role-per-administratie),
  multi-tenant data isolation.
- **All T1–T4 bookkeeping specs** — each must add `administratie` as mandatory FK to
  its schemas during the parallel refactor cycle. This is a **coordinated refactor
  across all sibling specs**.
- **`bookkeeping-consolidatie` spec** (future) — consumes `ConsolidatieMapping` and
  `IntercompanyJournaalpost` to render geconsolideerde jaarrekening.
- **OpenConnector — KvK Handelsregister** — potential integration to pre-fill
  administratie details on creation (deferred to implementation).

## Risks

### Risk 1: Query-layer migration burden across 60+ schemas

**Severity**: High
**Mitigation**: The `administratie` FK is declarative on each schema; OR's relation
engine enforces it. The implementing cycle provides a standardized query-filtering
pattern (e.g., `QueryBuilder::filterByAdministration($administrationId)`) reusable
across all layers. A hydra gate (`hydra-gate-administratie-isolation`) scans all
query methods to confirm they filter by `administratie` — no query returns data from
multiple administraties.

### Risk 2: Backup/export infrastructure change

**Severity**: Medium
**Mitigation**: Incremental backup per administratie is a straightforward extension
of existing backup routines. The Auditfile XAF exporter is per-administratie by
design (one export = one KvK entity). Rollback is a config change on
`administratie.backup_schema`.

### Risk 3: Fiscal-unit consolidation correctness (VPB/BTW)

**Severity**: Medium
**Mitigation**: The schema captures `fiscale_eenheid_vpb` and `fiscale_eenheid_btw`
references; the actual VPB/BTW consolidation logic is delegated to the
`bookkeeping-vat-return` and `bookkeeping-tax-filing` specs. This spec provides the
data structure; downstream specs implement the rules.

### Risk 4: Existing single-administratie installs break on deploy

**Severity**: High
**Mitigation**: The repair step creates a default `Administratie` for each existing
OpenRegister on first run, migrating all orphaned data (journaalposten without
`administratie` FK) to the default administratie. The migration is idempotent and
tested in the implementing cycle's CI gate.

## Rollback Strategy

This is a foundational refactor. Rollback is a dedicated migration (separate `opsx`
change), not a simple revert. Specifically:

1. Revert the commit; delete the change folder.
2. Run a data-migration script (deferred to a migration utility spec) that strips
   `administratie` FK from all schemas and re-forks existing single-administratie data
   back to separate OpenRegister instances.

No automatic rollback. High coordination cost. Mitigation: thorough spec review,
implementation testing, and a 4-week pilot in a staging multi-client environment
before production roll-out.

## Open Questions

1. **Parallel refactor timing** — Will all T1–T4 specs get `administratie` FK in a
   single coordinated cycle, or will each spec add it incrementally as it lands?
   Settlement: Coordinated single cycle (hydra gate enforces it).
2. **Data-isolation pattern in OR** — Does OR have native multi-tenant query
   filtering, or must Shillinq implement it via a standardized `FilterByAdministration`
   pattern in each query layer? Settlement: Deferred to implementation discovery,
   likely a combination (OR provides the FK constraint, Shillinq layers provide the
   filter).
3. **Fiscal-unit consolidation scope** — Is VPB/BTW consolidation (REQ-MA-008) in
   scope for this spec, or deferred to the `bookkeeping-vat-return` spec? Settlement:
   Deferred; this spec provides the schema, downstream specs implement the rules.
4. **Consolidated reporting user experience** — How does the holding-controller see
   both administraties in one view? Separate columns in pivot tables, or merged
   balances? Settlement: Deferred to `bookkeeping-consolidatie` spec's UX review.
