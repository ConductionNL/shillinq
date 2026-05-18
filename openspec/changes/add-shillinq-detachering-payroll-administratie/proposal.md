# Proposal: add-shillinq-detachering-payroll-administratie

`kind: config` per ADR-032 — the centre of mass is openconnector
source rows for salarisbureaus + a `SalarisFeed` raw-import
register + a declarative mapping from feed to balanced
`JournalEntry` + `OpdrachtgeversVerklaring` / `IB47Record`
registers for Wet DBA + IB47 + docudesk templates. No PHP service
classes are authored.

## Summary

Introduce the **detachering + payroll administratie** capability
for Shillinq as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Salarisbureau imports
(ADP / Loket / Visma / Nmbrs) ride existing openconnector OAuth2
+ REST patterns; the raw batch materialises as a `SalarisFeed`
register and maps via `x-openregister-mappings` to balanced
`JournalEntry` records of subtype `loonkosten`. For ZZP +
Wet DBA work, an `OpdrachtgeversVerklaring` register tracks the
Wet DBA position per opdracht + the standaard opdrachtgevers-
verklaring is rendered as a docudesk template. For freelance
betalingen, an `IB47Record` register collects the annual IB47
form payload (with BSN encrypted + RBAC-restricted) and submits
to the Belastingdienst via openconnector.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-accounts-payable-core`](../../specs/bookkeeping-accounts-payable-core/spec.md)
  — supplies the AP register the salarisbureau feed targets via
  the balanced JournalEntry mapping.

## Motivation

Without dedicated payroll-bridge primitives, every salarisbureau
needs a bespoke import script + hand-mapped journal entries, and
the Wet DBA + IB47 administratie lives in spreadsheets. Per ADR-019
+ ADR-022 + the parent envelope's design D12, every external
HTTP call MUST ride openconnector; mapping is declarative; documents
are rendered via docudesk. No app-local payroll client. No app-local
DBA service.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — declares 3 schemas (`SalarisFeed`,
  `OpdrachtgeversVerklaring`, `IB47Record`); declares the feed-
  to-JournalEntry mapping; registers 2 docudesk templates
  (opdrachtgeversverklaring, IB47 form); adds 1 manifest navigation
  entry behind `featureFlags.mkb-detachering`; declares RBAC
  restricting BSN + personnel data access; pre-declares
  encryption on BSN field
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers opdrachtgevers + IB47
  templates
- [ ] Project: openconnector — registers 4 salarisbureau source
  rows (ADP / Loket / Visma / Nmbrs) + 1 Belastingdienst IB47
  source row

## Scope

### In Scope

- One new capability spec
  (`bookkeeping-detachering-payroll-administratie`) — see the
  `specs/` folder.
- `SalarisFeed` raw-import register (with `schema:DataFeed`
  annotation) carrying the incoming batch from salarisbureau
  feeds; declarative `x-openregister-mappings` to balanced
  `JournalEntry` records of subtype `loonkosten` (loonkosten DR /
  nettoloon CR / sociale-premies CR / loonheffing CR / pensioen
  CR).
- `OpdrachtgeversVerklaring` register (with `schema:DigitalDocument`
  annotation): `zzpId`, `zzpNaam`, `opdrachtBeschrijving`,
  `looptijdStart/Eind`, `verklaringStatus` enum, `modelOvereenkomst`
  URI (optional), `verklaringDocumentUri`, `risicoBeoordeling`
  enum.
- `IB47Record` register: `belastingjaar`, `opdrachtgeverId` FK,
  `ontvangerNaam`, `ontvangerBSN` (encrypted + RBAC-restricted to
  `payroll-officer`), `ontvangerAdres`, `betalingenTotaal`,
  `betalingTypeCode` enum.
- Per-tax-year IB47 aggregation grouping `IB47Record` by
  `(belastingjaar, opdrachtgeverId)`; final yearly batch totals
  MUST equal sum of 12 monthly dry-runs (€0 tolerance).
- 4 openconnector source rows for salarisbureaus (ADP / Loket /
  Visma / Nmbrs) + 1 Belastingdienst IB47 upload source row.
- 2 docudesk templates (standaard opdrachtgeversverklaring,
  IB47 form per Belastingdienst XML schema 2026).
- Manifest navigation entry (Bookkeeping > Detachering en
  payroll) behind `featureFlags.mkb-detachering` with 3 sub-pages
  (Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie,
  IB47-jaarbatch).

### Out of Scope

- **Implementation code** — spec-only change.
- **Innovatiebox** — owned by sibling
  `add-shillinq-innovatiebox-administratie`.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-detachering-payroll-administratie`). Each requirement
is prefixed `REQ-DPA-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas
  (`SalarisFeed`, `OpdrachtgeversVerklaring`, `IB47Record`);
  declares `x-openregister-mappings` from SalarisFeed to
  JournalEntry; declares RBAC on `IB47Record.ontvangerBSN`
  restricting read to `payroll-officer`.
- `src/manifest.json` — adds 1 navigation entry + 3 sub-pages
  behind `featureFlags.mkb-detachering`.
- `lib/Settings/docudesk-templates.json` — registers 2 templates.
- `lib/Settings/openconnector-sources.json` — registers 5 sources
  (4 salarisbureaus + 1 Belastingdienst).
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-mappings`,
  `x-openregister-aggregations`, audit-trail-immutable, RBAC.
- **docudesk** — opdrachtgeversverklaring + IB47 templates.
- **openconnector** — 5 source rows per ADR-019.

## Risks

### Risk 1: Salarisbureau API contracts drift

**Severity**: Medium
**Mitigation**: Mapping lives as `x-openregister-mappings`
declaration — schema-only edit when a field renames. Openconnector
source owns the auth + protocol.

### Risk 2: Wet DBA risico beoordeling subjective

**Severity**: Low
**Mitigation**: `risicoBeoordeling` is enum (`geen | laag | midden
| hoog`); operator-classified. The standaard opdrachtgeversverklaring
template renders the chosen risico.

### Risk 3: BSN privacy footprint on IB47Record

**Severity**: High
**Mitigation**: `ontvangerBSN` field declared with
`x-openregister-encryption` and RBAC restricting read to
`payroll-officer`. Every access logs to audit-trail-immutable
per ADR-022.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **IB47 reporting cadence** — per REQ-DPA-005 the spec proposes
   annual batch with monthly dry-run. Confirm with the
   loonadministratie reviewer persona before `opsx-apply`.
