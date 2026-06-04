# ADR-002: Information architecture — cross-cutting placement rules

**Status:** Accepted
**Date:** 2026-05-23

## Context

Shillinq's surface area is large: ~113 capability specs spanning
double-entry bookkeeping, sales/purchase/inventory/payroll operations,
the full Dutch fiscal stack (BTW, Vpb, IB, SBR/XBRL), and the
decentralised-government compliance stack (BBV, IV3, SiSa, EMU, BCF,
Rechtmatigheidsverantwoording, Wet Fido). Every product/design/dev
proposal so far has been tempted to mint a new top-level menu, a new
parallel sub-page tree, or a dedicated screen for every "engine" spec.
Left unchecked the result is a sidebar with 20+ items, three different
places to file a Vpb-aangifte, and a separate menu for each sector
variant — none of which the target users (ZZP'er / MKB-BV / gemeente
/ concern) can navigate.

A proposal-level IA review (see `/tmp/ia-shillinq.md`, *Shillinq —
Information Architecture*, 2026-05-22) walked every spec slug end to
end and condensed the cross-cutting placement rules into one design
table plus a 113-spec mapping. The spec-by-spec mapping is the
review's output and lives in the source document; this ADR lifts the
**cross-cutting topology rules** from that review into the
architecture canon so every future spec follows them without having
to re-derive the IA from scratch.

This ADR does **not** restate the per-spec placement table — that
table is per-capability and changes when specs are added/promoted/
demoted. Specs reference these rules; the mapping table is consulted
on a spec-by-spec basis from the IA review document.

## Decision

There are **five cross-cutting design rules** that govern how every
future Shillinq capability spec attaches to the UI topology. Every
new or revised spec MUST declare a primary placement that satisfies
all five rules; reviewers MUST block specs that violate them without
an explicit ADR superseding this one.

### Rule 1 — One aangifte = one SUB_PAGE under Belasting

Anything submitted to a tax authority (Belastingdienst Digipoort, KvK
as filer, RVO for WBSO-S&O, EU for OSS, Belastingdienst voor IB/Vpb)
lives as **one sub-page under the Belasting top-menu**. Never as a
top-level menu. Never as a tab on Rapportage.

Anything that is an external report but **not** a tax aangifte
(jaarrekening, SBR/XBRL bundle, IV3, SiSa, CSRD, programmabegroting,
audit pack, BADO controleprotocol, single-audit EU, Country-by-Country)
lives as **one sub-page under the Rapportage top-menu**.

The split is "**aangifte vs rapportage**", not "fiscaal vs niet-fiscaal".
A Vpb-aangifte is an aangifte (Belasting); a jaarrekening with Vpb-
deferred-tax note is a rapportage (Rapportage). When in doubt, ask:
*does a tax authority receive the submission as the recipient of
record?* If yes → Belasting; if no → Rapportage.

### Rule 2 — Sector variants are SETTINGS, not menus

Variants gated by `administratie.sector` (gemeente, provincie,
waterschap, gemeenschappelijke regeling, commercieel, ZZP) or
`administratie.fiscaalRegime` (Vpb-MKB, Vpb-overheid, IB-ZZP, KOR-on)
get **no own menu and no parallel sub-page tree**. They appear as:

- a SETTING tab on the relevant generic page (e.g. BBV-waterschappen
  is a tab on **Beheer / Rekeningschema → BBVW-mapping**, not a
  separate menu), and/or
- a DETAIL_TAB on the generic working page (e.g. Vpb-overheid is a
  sector-tab inside the single **Belasting / Vpb-aangifte** sub-page,
  not a parallel `/belasting/vpb-overheid` route).

Rationale: a gemeente that also runs a commercial trekje (e.g.
afval-inzameling BV) must see **one UI** with sector toggles, not two
parallel applications inside the same app. Sector divergence belongs
in configuration + a tabbed inside, not in topology.

Concretely, this rules out a parallel "Overheid" or "ZZP" top-menu,
and rules out twin pages like `/rapportage/jaarrekening-mkb` +
`/rapportage/jaarrekening-bv`.

### Rule 3 — Integraties are SETTINGS + ACTIONS, never their own menu

External-system integrations — TenderNed, Peppol, Digipoort, PSD2,
pipelinq-bridge, docudesk-attachments, POS-systemen, mobiele
scanner-PWA, self-service booking widget, every future connector —
follow a two-part pattern:

- **Configuration** lives in **Beheer / Integraties** (one sub-page
  per integration, with credentials/tokens/endpoints/feature flags).
- **Use** happens via an ACTION button on the relevant working page
  (e.g. "TenderNed-import" on inkooporder, "Verstuur via Peppol" on
  factuur, "Open mobiele scanner" on Voorraad-landing, "Bridge naar
  pipelinq" on klant).

An integration NEVER becomes a sub-page with its own H1 in the
hoofdnavigatie. There is no `/peppol`, no `/tenderned`, no
`/docudesk`. The integration is plumbing; the working page is the
user-facing surface.

### Rule 4 — "Engine" specs default to SETTING + ACTION, not SUB_PAGE

A spec whose name contains "engine", "rule-engine", "automation",
"orchestrator", or describes machinery that other specs consume is
**a feature, not a page**. Default placement is **SETTING + ACTION**:

- the rules/templates/configuration live as a sub-page under Beheer
  (e.g. CCM Regel-engine, Voorzieningen-templates, Rate-card-engine
  settings), and
- the trigger/execution surfaces as an ACTION on the working page
  the engine acts on (e.g. "Activeer voorziening" on a journal
  entry, "Pas rate-card toe" on an order regel).

A SUB_PAGE promotion is allowed **only if** the engine produces an
eigen werkruimte for the eindgebruiker — a list/detail-screen pair
the user spends real time in. Examples that *do* warrant promotion:
`retainer-billing-engine` (drawdown/rollover/true-up has its own
detail-screen and the user iterates inside it). Examples that do
*not*: `ccm-rule-engine`, `rate-card-engine`, `wbso-uren-tagging-and-
export`, `inventory-reorder-automation` — all SETTING + ACTION.

The "engine produces its own werkruimte" promotion requires explicit
justification in the spec; absence of justification means SETTING +
ACTION wins.

### Rule 5 — Compliance markers, retention, audit, validation are never their own page

Cross-cutting governance — Archiefwet-retentie, audit-trail,
document-attachments, DBA-compliance-markers, BBV-mapping flags,
validatieregels, lock-state, approval-status — is **never** its own
top-level page. It surfaces as:

- a DETAIL_TAB on the object it governs (e.g. **Audit** tab on
  journal-entry, **Retentie** tab on document, **DBA-status** tab on
  detacheringsopdracht), plus
- a SETTING in Beheer for the rules/policies (e.g. **Beheer /
  Retentie** holds the per-doctype retentietermijnen).

Cross-cutting governance that comes from a foundation app
(OpenRegister retention, OR audit-trail, docudesk attachments) is
**consumed**, not rebuilt. Shillinq shows status; OR/docudesk do the
work. This is a direct corollary of ADR-022 (apps consume
OpenRegister abstractions) applied to the IA layer.

### Topology invariants that fall out of rules 1–5

These are derived constraints — they hold as long as rules 1–5 hold:

- **Sidebar caps at 9 items**: Dashboard + 8 functional top-menus
  (Boekhouding, Verkoop, Inkoop, Voorraad, Salaris, Belasting,
  Rapportage, Beheer). No tenth item without an ADR superseding this
  one. New domains demote into one of the existing 8.
- **No TOP_MENU specs**: no capability spec maps to a top-menu
  directly. Top-menus are structural anchors set by this ADR; specs
  attach beneath them.
- **Tier-suffixed change envelopes are not capabilities**: the five
  rollup change slugs (`add-shillinq-bookkeeping-foundation`,
  `…-compliance`, `…-operations`, `…-advanced`,
  `add-shillinq-gov-sector-mkb-advanced`) inherit placement
  transitively from the capability specs they bundle (per ADR-001).
  They do not get their own placement.

## Consequences

### Positive

- **Predictable placement decisions.** New specs have a deterministic
  decision tree (aangifte? sector variant? integration? engine?
  governance? otherwise SUB_PAGE under the relevant functional
  top-menu). The IA review document's per-spec table becomes a
  worked example, not the law.
- **Sidebar stays navigable.** 9 items, no growth without an ADR.
  Government / ZZP / concern users see the same shell; sector
  switching is configuration, not navigation.
- **Foundation apps stay foundational.** Audit/retention/attachments
  surface as tabs and consume OR/docudesk; Shillinq does not
  re-implement them per-domain.
- **Engines stop fragmenting the topology.** A new "X-engine" spec
  ships as one settings page + a button, not as a new menu fighting
  for sidebar real-estate.

### Negative

- **Some capabilities feel "buried" under tabs/actions.** A user
  hunting for "DBA-compliance" finds it as a tab on
  `/salaris/detachering`, not as a top-level item. The trade-off is
  navigability of the whole over discoverability of any single
  feature; deeplinks, search, and Dashboard widgets compensate.
- **Sector-tab pages become wider.** The single Vpb-aangifte page
  carries MKB-Vpb + Vpb-overheid + deferred-tax + investeringsaftrek-
  toepassing + innovatiebox tabs. This is acceptable because the
  audience for any one administratie sees only the tabs their
  sector enables (gated by `administratie.sector`/`fiscaalRegime`).
- **One more ADR for spec authors to read.** Future spec PRs MUST
  cite their placement choice and the rule it satisfies; reviewers
  block specs without a placement declaration.

### Per-rule consequences for future specs

| Rule | Specs constrained going forward |
|------|----------------------------------|
| 1 | Any new aangifte spec (e.g. KOR-variant, OSS-uitbreiding, MOSS-erfgenaam, Pillar-2-CbCR) ships as a sub-page under Belasting; any new external report (e.g. CSRD-update, IFRS-Sustainability, ESEF) ships under Rapportage. No "Fiscaal Beheer" or "Reporting Hub" mega-menus. |
| 2 | New sector entrants (samenwerkingsverband, ZBO, schoolbestuur, kerkgenootschap) get a `administratie.sector` value + sector-tabs on existing pages — never a parallel menu. |
| 3 | New connector specs (e.g. Mollie, Stripe, Exact-bridge, Twinfield-bridge, KvK-LV, Belastingdienst-API-V2) land as one **Beheer / Integraties** sub-page + ACTION buttons on the working pages they enrich. No `/mollie`, no `/exact`. |
| 4 | New "engine" specs (forecasting-engine, anomaly-detection-engine, depreciation-recalc-engine, dunning-strategy-engine) ship as SETTING + ACTION by default; promotion to SUB_PAGE requires the spec to demonstrate an eigen werkruimte. |
| 5 | New governance specs (e.g. GDPR-DPIA-tracker, NIS2-incident-register, accountantsverklaring-tracker) ship as DETAIL_TAB on the controlled object + SETTING in Beheer. They consume OR primitives where available (retention, audit, attachments). |

### Migration

This ADR governs **future** spec placement. Existing capability specs
are already placed per the IA review's mapping table in
`/tmp/ia-shillinq.md` (the 113-spec table). Where existing specs
violate a rule (e.g. an "engine" spec accidentally claiming a
SUB_PAGE without justification, or a sector-variant claiming its own
sub-page), reviewers SHOULD open a follow-up to demote per these
rules — but no automatic migration is required by this ADR.

When a future ADR overrides one of these rules (e.g. an explicit
sidebar expansion past 9 items), it MUST cite this ADR as superseded
and update the rule-table here.

## See also

- `/tmp/ia-shillinq.md` — source IA review (2026-05-22), full
  113-spec placement table + per-menu sub-architecture + phased
  rollout. This ADR lifts its cross-cutting rules into canon.
- `adr-000-data-model.md` — the 225-entity catalogue every menu
  consumes.
- `adr-001-bookkeeping-tier-roadmap.md` — T1→T5 rollout that the
  IA's phased delivery (MVP / Secondary / Advanced) tracks against.
- `hydra/openspec/architecture/adr-022-apps-consume-openregister-abstractions.md`
  — retention/audit/attachment consumption pattern Rule 5 enforces.
- `hydra/openspec/architecture/adr-024-app-manifest.md` — manifest
  shape that encodes top-menu + sub-page topology.
- `hydra/openspec/architecture/adr-032-spec-sizing-and-chaining.md` —
  `kind:` taxonomy and chain primitive; this ADR adds a placement
  declaration alongside `kind`.
