# Proposal: add-shillinq-sbr-xbrl-reporting

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + NL-taxonomie mapping seed data.
No PHP service classes are authored.

## Summary

Introduce the **SBR/XBRL annual reporting** capability for Shillinq as
part of the Tier 4 advanced bookkeeping engine (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
`XbrlInstance` register with a `draft → validated → submitted → accepted
/ rejected` `x-openregister-lifecycle` (per ADR-031), wires the
navigation into `src/manifest.json` (per ADR-024), consumes OpenRegister's
`Mapping` abstraction for NL-taxonomie line → concept resolution, and
consumes openconnector's Digipoort source registry for submission (per
ADR-022). NL-taxonomie mapping seed templates ship under
`lib/Settings/seeds/sbr-mappings/` per SBR entry point + taxonomy
version. No PHP service classes, no embedded SOAP/WS-Security client,
no bespoke Vue components.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

KvK deponering and Belastingdienst aangifte (VPB / IB) are legal
obligations for every NL company; SBR-banken kredietrapportage and
SBR-Wonen are sector mandates. Without SBR/XBRL filing generation,
Shillinq cannot serve as a complete bookkeeping system for Dutch SMB,
government, or housing-corporation use. The XBRL document is a
transformation on top of the T3 `FinancialStatement` (per `design.md`
D2 in the source bundled change) — not a re-aggregation — so the
filing and the operator-visible statement share a single source of
truth.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`XbrlInstance`)
  to `lib/Settings/shillinq_register.json`, ships NL-taxonomie mapping
  seed templates under `lib/Settings/seeds/sbr-mappings/`, adds 1
  manifest navigation entry in `src/manifest.json`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (`x-openregister-lifecycle`, audit-trail-
  immutable, `Mapping`, RBAC, `ScheduledWorkflow`).
- [ ] Project: openconnector — no source changes; this change *consumes*
  an openconnector `Source` record for Digipoort (SBR submission).
  Source records are administration-side configuration, not
  shillinq-side code.
- [ ] Project: docudesk — XBRL submission receipts are attached as
  source documents by foreign-key URI per ADR-022.

## Scope

### In Scope

- One new capability spec (`bookkeeping-sbr-xbrl-reporting`) — see
  the `specs/` folder.
- `XbrlInstance` register declaration with
  draft → validated → submitted → accepted / rejected lifecycle.
- NL-taxonomie line → concept mapping via OR `Mapping` records per
  entry point and taxonomy version (kvk-jaarrekening,
  belastingdienst-vpb, belastingdienst-ib,
  sbr-banken-kredietrapportage, sbr-wonen).
- Digipoort submission routed through openconnector by source slug;
  no embedded SOAP/WS-Security stack in shillinq.
- NL-taxonomie mapping seed templates under
  `lib/Settings/seeds/sbr-mappings/`, versioned in filename
  (`nt17`, `nt18`).
- Manifest navigation entry (Bookkeeping > SBR/XBRL Filings) using
  `type: index` / `type: detail` renderers from
  `@conduction/nextcloud-vue`.
- Audit trail consumed from OpenRegister's audit-trail-immutable
  abstraction per ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the implementation lands via a separate
  `opsx-apply` cycle on the spec.
- **Digipoort certificate management UI** — openconnector owns source
  credentials including WS-Security certificates; shillinq does not
  surface a credential-management UI for them.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-sbr-xbrl-reporting`** — declares the `XbrlInstance`
register with the draft/validated/submitted/accepted/rejected
lifecycle, references the OR `Mapping` abstraction for line→concept
mapping, consumes openconnector's Digipoort source for submission, and
ships NL-taxonomie mapping seeds per entry point and version.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-SBR-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions,
existing openconnector source records (Digipoort) that operators
configure, and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema
  (`XbrlInstance`) with `x-openregister-lifecycle`.
- `lib/Settings/seeds/sbr-mappings/*.json` — seed data for the
  NL-taxonomie mapping per entry point (kvk-jaarrekening,
  belastingdienst-vpb, belastingdienst-ib,
  sbr-banken-kredietrapportage, sbr-wonen) per taxonomy version.
- `src/manifest.json` — adds 1 navigation entry (SBR/XBRL Filings) and
  matching `type: index` + `type: detail` pages.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022), `Mapping`, RBAC.
- **openconnector** — operators must have configured a `Source`
  record for a Digipoort production endpoint. shillinq references the
  source by slug; no code coupling.
- **docudesk** — XBRL submission receipts referenced by docudesk
  attachment URI from the relevant register records.

## Risks

### Risk 1: NL-taxonomie evolves rapidly; mappings can fall behind

**Severity**: Medium
**Mitigation**: Per REQ-SBR-002 the `XbrlInstance` records pin
`taxonomyVersion` per generated instance; per REQ-SBR-006 the
`Mapping` records are versioned independently. Historical instances
remain intact even when a new taxonomy ships. The mapping seed files
under `lib/Settings/seeds/sbr-mappings/` are versioned in the filename
(`nt17`, `nt18`, etc.) so coexistence is trivial.

### Risk 2: Digipoort source not yet configurable in openconnector when this change implements

**Severity**: Low–Medium
**Mitigation**: openconnector's pluggable source registry is mature.
The implementing cycle MUST verify the configured Digipoort source
slug before running the manifest validator. If the source type is
missing, an openconnector issue is filed and the requirement stays
shape-neutral (the requirement names the slug, not the underlying
protocol). No shillinq-side fallback HTTP client.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR, run
the repair step in down-direction (registers are non-destructive —
unused schemas remain queryable but unreferenced).

## Open Questions

1. **NL-taxonomie version pinning policy** — auto-upgrade to the
   latest taxonomy on filing, or operator-controlled? REQ-SBR-002 pins
   `taxonomyVersion` per instance; the *new-filing default* policy
   lives in administration settings, not the schema.
