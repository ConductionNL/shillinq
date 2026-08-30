# Proposal: add-shillinq-vat-btw-filing

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + workflow declarations + BTW
tariff seed data. No PHP service classes are authored.

## Summary

Introduce the **BTW (omzetbelasting) periodic filing** capability for
Shillinq as the first slice of Tier 3 (operations + NL compliance
core) per `adr-001-bookkeeping-tier-roadmap.md`. This change declares
the `VatReturn`, `IcpStatement`, `VatCorrection`, and `VatTariff`
registers with `x-openregister-lifecycle` rules (per ADR-031), wires
navigation into `src/manifest.json` (per ADR-024), ships the
`btw-tariffs-2026.json` seed loaded via
`ConfigurationService::importFromApp()` (per ADR-022), and declares
the quarterly SBR/Digipoort submission as an OR `ScheduledWorkflow`
consuming the `digipoort-sbr` OpenConnector source (per ADR-019).
No PHP service classes, no app-local HTTP client, no bespoke Vue
components — the entire capability lands as register metadata +
manifest entries + workflow + seed JSON.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-general-ledger` (sums BTW per rate
from posted GL lines) and T2 `bookkeeping-period-close` (period
boundaries define aangifte windows).

## Why

Every Dutch operator hitting "go live" with Shillinq immediately
needs to file BTW (kwartaal or maand), to file ICP-opgaaf for
intra-EU sales, to file suppleties for corrections, and to submit
through SBR/Digipoort. Until this capability lands, an operator
must run Shillinq's bookkeeping in parallel with an external tax
package — defeating the suite's value.

The aangifte process is a textbook fit for declarative metadata:
the `VatReturn` lifecycle (`draft → submitted → accepted → corrected`)
is a clean state machine; the rubrieken aggregation is a sum-by-rate
projection over posted `GLLine` rows; the SBR submission is an OR
`ScheduledWorkflow` invoking an OpenConnector source.

## Affected Projects

- [x] Project: shillinq — adds 4 new registers/schemas (`VatReturn`,
  `IcpStatement`, `VatCorrection`, `VatTariff`) to
  `lib/Settings/shillinq_register.json`, adds 3 manifest navigation
  entries (`Belastingen > BTW-aangiften`, `> ICP-opgaaf`,
  `> BTW-correcties`) in `src/manifest.json`, ships
  `lib/Settings/seeds/btw-tariffs-2026.json`, declares the quarterly
  SBR `ScheduledWorkflow`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  `x-openregister-aggregations`, `ScheduledWorkflow`,
  approval-workflow).
- [ ] Project: openconnector — no source changes; references the
  `digipoort-sbr` source symbolically. Source registration lands
  separately in `add-openconnector-nl-overheid-sources`.
- [ ] Project: docudesk — referenced by URI from
  `VatReturn.attachmentUri` for aangifte-PDFs.

## Scope

### In Scope

- One new capability spec (`bookkeeping-vat-btw-filing`) — see the
  `specs/` folder.
- BTW periodic return (kwartaal/maand), ICP-opgaaf for intra-EU
  sales, verleggingsregeling (reverse-charge) handling,
  suppletie-aangifte correction lifecycle.
- SBR/Digipoort submission declared as an OR `ScheduledWorkflow`
  consuming `digipoort-sbr`.
- Manifest navigation under `Belastingen` (with index/detail pages
  rendered by `CnIndexPage`/`CnDetailPage`).
- Audit trail + approval gate on `draft → submitted` consumed from
  OR abstractions per ADR-022.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP
  services, Vue components, controllers, tests, and CI changes
  are not in this proposal.
- **BCF claim** — owned by sibling
  `add-shillinq-bcf-vat-compensation`.
- **KOR opt-in lifecycle** — owned by sibling
  `add-shillinq-kor-kleine-ondernemersregeling`.
- **XBRL / Nederlandse Taxonomie jaarrekening generation** — T4.
  T3 ships only the SBR submission *trigger* + per-aangifte payload
  shape.
- **OpenConnector source registration** for `digipoort-sbr` — owned
  by `add-openconnector-nl-overheid-sources`.
- **PKIoverheid certificate custody** — operator-managed via the
  OpenConnector source config; never in shillinq's `secrets/`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-vat-btw-filing`** — declares the four registers,
their lifecycles, the rubrieken aggregation over T1 GL postings,
and the SBR submission workflow. Each requirement is prefixed
`REQ-VBTW-*` for traceability; scenarios use exactly 4-hashtag
headers with GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes T1 GL, T2 period-close, existing OR abstractions,
and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 schemas with
  `x-openregister-lifecycle` blocks.
- `lib/Settings/seeds/btw-tariffs-2026.json` — new file (21%, 9%,
  0%, vrij, verlegd), SPDX header, `_meta.source: "Wet OB 1968"`.
- `src/manifest.json` — adds 3 navigation entries under
  `Belastingen` + their `type: index`/`type: detail` pages.
- `ScheduledWorkflow` declaration for SBR-quarterly/maand submission.
- Repair step extension to import the BTW tariff seed.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`, `ScheduledWorkflow`, and
  approval-workflow being stable. No new OR features required.
- **OpenConnector** — references `digipoort-sbr` source by name;
  source registration lands in a separate change.
- **docudesk** — referenced by URI for aangifte-PDFs; no coupling.

## Risks

### Risk 1: SBR/Digipoort PKIoverheid certificate custody

**Severity**: Low (deferred)
**Mitigation**: T3 declares the workflow trigger + payload shape;
the actual SBR/Digipoort submission (certificate handling, response
parsing, ack/nack handling) lives in OpenConnector and is operator-
configured at install time. T3 does not embed certificates and does
not author the HTTP wrapper.

### Risk 2: Rubrieken aggregation may not fit OR's declarative engine

**Severity**: Low
**Mitigation**: Sum-by-rate aggregation over `GLLine` is a standard
projection-filter pattern; if the engine cannot express it, ADR-031
exception path applies (single-method PHP guard, ~30 LOC). Resolved
in `opsx-ff` discovery.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation, rollback
follows the standard pattern: revert the implementing PR, run the
repair step in down-direction (registers non-destructive — unused
schemas remain queryable). Seeded BTW tariffs remain queryable.

## Open Questions

1. **Periodtype default** — kwartaal is most common for SMB;
   monthly is default for large operators. `REQ-VBTW-002` declares
   the field as operator-configurable; default seeded as `quarterly`.
2. **Suppletie threshold** — Belastingdienst mandates suppletie for
   corrections above €1.000; below that operators may correct in the
   next regular aangifte. Confirm with bookkeeper persona during spec
   review.
