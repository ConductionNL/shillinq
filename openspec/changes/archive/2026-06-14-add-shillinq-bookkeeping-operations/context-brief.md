# Proposal: add-shillinq-bookkeeping-operations

## Summary

Add the **Tier 3 — operations + Dutch regulatory compliance core** to
Shillinq's bookkeeping rollout. T3 is the layer where a working
double-entry ledger (T1) plus sub-ledgers / period close / AP / AR /
financial statements (T2) become a **Dutch-compliant bookkeeping
engine** for the three operator segments Shillinq targets: SMB
(MKB), self-employed (ZZP), and decentralised government
(gemeenten, waterschappen, provincies).

T3 introduces 10 new capabilities — all declared as registers +
schemas + `x-openregister-lifecycle` rules per ADR-031, all consuming
OR's audit / RBAC / approval / scheduled-workflow abstractions per
ADR-022, all surfaced via `src/manifest.json` entries per ADR-024. No
PHP service classes for state machines, no app-local audit, no
parallel link tables. External submissions (SBR/Digipoort, CBS-IV3,
BCF claim file) land via OR's workflow + n8n adapter, not as bespoke
PHP HTTP clients (per ADR-031 §"Background jobs that orchestrate
external systems").

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + workflow definitions + seed data. The one
plausible PHP guard (KOR omzetdrempel + 1225-urencriterium thin
aggregations) is permitted under ADR-031 §"PHP guards remain a
legitimate seam" and noted as a yellow-flag in `design.md`'s
Declarative-vs-imperative table.

## Motivation

T1 + T2 give Shillinq a balanced ledger, sub-ledgers, period close,
AP/AR, and statements — enough to run **bookkeeping**, not enough to
run a **Dutch-compliant administration**. Every Dutch operator
hitting "go live" with Shillinq immediately needs:

- A way to file **BTW** (kwartaal / maand / ICP / suppletie) and
  to submit through SBR/Digipoort.
- For municipal users: **BBV-conformant** posting rules, **IV3**
  quarterly export to CBS, and **BCF** claim administration for
  the btw-compensatiefonds.
- For ZZP / starters: tracking of the **1225-urencriterium**,
  zelfstandigenaftrek, startersaftrek, and **MKB-winstvrijstelling**,
  with export to the IB-aangifteformulier.
- For small MKB: **KOR** opt-in/opt-out lifecycle and €20k
  omzetdrempel tracking with alarm before threshold is crossed.
- For municipalities banking at the Treasury: **schatkistbankieren**
  drempel + daily liquidity reporting.
- For grant recipients: **subsidie-verantwoording** lifecycle per
  ASV-model and Awb 4.2.
- For every operator: **Archiefwet 1995 / Selectielijst Gemeenten**
  retention enforcement on every record-type — consumed from OR's
  lifecycle/retention abstraction, **not** reimplemented.
- For the consultancy operator segment (Conduction's own primary
  customer profile): **multi-project WIP, billable hours, RJ 270 /
  IFRS 15 percentage-of-completion revenue recognition**, multi-rate
  cards (junior / senior / partner), utilisation, project P&L.

Each of these is a separate compliance regime with its own data
shape, its own lifecycle, and its own external surface. T3 frames
them as 10 capability specs so they can be implemented, reviewed,
and rolled out independently — but on a shared T1+T2 foundation
(no GL forking, no second journal, no parallel period engine).

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 3**:
`add-shillinq-bookkeeping-operations`. T1 (`add-shillinq-bookkeeping-foundation`)
and T2 (`add-shillinq-bookkeeping-compliance`) are siblings in the same
PR; T4-base, T4-specialized, and the deferred T5 are also captured in
the ADR.

## Affected Projects

- [x] Project: shillinq — adds 10 new registers/schemas to
  `lib/Settings/shillinq_register.json`, adds ~12 manifest navigation
  entries in `src/manifest.json`, ships compliance seed data
  (BTW-tariffs, BBV-taakvelden, RGS↔BBV mapping, Selectielijst
  retention rules, KOR threshold, urencriterium threshold, ASV-model
  subsidielifecycle) in `lib/Settings/seeds/`.
- [ ] Project: openregister — no source changes; T3 consumes existing
  OR abstractions (lifecycle, audit, RBAC, retention, scheduled
  workflow, approval workflow, mappings). Gaps surface as OR issues
  with the relevant spec annotated in `design.md`'s
  Declarative-vs-imperative decision table.
- [ ] Project: openconnector — no source changes here, but the
  SBR/Digipoort + CBS-IV3 + BCF + DigiKoppeling adapters are
  consumed via OpenConnector source registrations declared in T4.
  T3 only references them by symbolic source name (per ADR-019).
- [ ] Project: docudesk — no source changes; BTW-aangifte PDFs and
  BCF-claim files are referenced by docudesk attachment URI from the
  relevant register records.

## Scope

### In Scope

- **10 capability specs** (one per file under `specs/`):
  - `bookkeeping-vat-btw-filing` — BTW journal, periodieke aangifte
    (kwartaal/maand), ICP-opgaaf, reverse-charge (verleggings-
    regeling), SBR/Digipoort submission, suppletie-aangifte.
  - `bookkeeping-bbv-compliance` — BBV posting rules per taakveld,
    programma, paragraaf, autorisatieniveau.
  - `bookkeeping-iv3-reporting` — Quarterly IV3 export to CBS.
  - `bookkeeping-bcf-vat-compensation` — Btw-compensatiefonds claim
    admin for municipalities.
  - `bookkeeping-kor-kleine-ondernemersregeling` — KOR opt-in /
    opt-out lifecycle, €20k omzetdrempel tracking with alarm,
    automatic regime switch.
  - `bookkeeping-zzp-tax-regime` — Zelfstandigenaftrek, starters-
    aftrek, MKB-winstvrijstelling, 1225-urencriterium tracking,
    IB-aangifteformulier export.
  - `bookkeeping-schatkistbankieren` — Treasury banking flows,
    drempelbedrag, daily liquidity reporting.
  - `bookkeeping-subsidie-verantwoording` — Grant lifecycle per
    ASV-model + Awb 4.2.
  - `bookkeeping-archiefwet-retention` — Records retention per
    Archiefwet 1995 + Selectielijst Gemeenten, enforced via OR's
    `x-openregister-lifecycle.retention` per ADR-022.
  - `bookkeeping-consultancy-project-accounting` — Multi-project WIP,
    billable hours, RJ 270 / IFRS 15 percentage-of-completion,
    project P&L, multi-rate, utilisation.
- Compliance seed data in `lib/Settings/seeds/` (BTW tariffs,
  BBV-taakvelden, RGS↔BBV mapping, Selectielijst-2020 retention
  rules, KOR + urencriterium thresholds, ASV-model subsidie
  lifecycle template, RJ-270 stage definitions).
- `src/manifest.json` navigation entries for each operator-facing
  surface (BTW-aangiften list, BCF-claims list, KOR-status,
  Urenregistratie, Schatkist-positie, Subsidies, Projecten, etc.)
  driven by `type: index` / `type: detail` library renderers.
- Declarative lifecycles (per ADR-031) on every state-bearing
  schema: BTW-aangifte (`draft → submitted → accepted → corrected`),
  BCF-claim, KOR-regime (`outside → opted-in → threshold-warning →
  threshold-exceeded → opted-out`), Subsidie (`aanvraag → verleend →
  vastgesteld → uitbetaald → teruggevorderd?`), Project (`offerte →
  active → on-hold → closed → archived`).
- External submission flows (SBR/Digipoort, CBS-IV3, BCF) declared as
  OR `ScheduledWorkflow` / event-triggered workflows consumed from
  OpenConnector sources — **not** authored as PHP `HttpClient`
  wrappers in shillinq.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are not in this
  proposal. Tasks 2+ describe the implementing cycle's work pre-
  declared for traceability.
- **XBRL/SBR taxonomy plumbing** — the *structural* SBR submission
  flow is in T3 scope (`bookkeeping-vat-btw-filing` and `iv3-reporting`), but the
  full XBRL Nederlandse Taxonomie (NT) generation engine for the
  jaarrekening is T4. T3 ships only the trigger + the per-aangifte
  payload shape.
- **Multi-currency translation, FX revaluation, IFRS overlay** —
  T5. T3's `Currency` field exists on every monetary schema but the
  translation engine doesn't.
- **Intercompany eliminations, group consolidation** — T5.
- **Industry-specific BBV variants** beyond core gemeenten/provincies/
  waterschappen — out of scope (T3+ roadmap item).
- **Bespoke Vue components** beyond the manifest-driven
  `CnIndexPage` / `CnDetailPage`. The KOR threshold-warning surface
  and the urencriterium widget are declared as
  `x-openregister-widgets` consumed by `CnDashboardPage`; no custom
  Vue.

## Approach

10 deltas, each adding ADDED Requirements to a brand-new spec
under this change's `specs/` folder. Each spec follows the
conduction-schema format used by T1 (RFC 2119; `### REQ-<Abbrev>-NNN:`;
`#### Scenario:` with exactly 4 hashtags; GIVEN/WHEN/THEN). The
abbreviations:

| Spec | Abbrev |
|---|---|
| bookkeeping-vat-btw-filing | `VBTW` |
| bookkeeping-bbv-compliance | `BBV` |
| bookkeeping-iv3-reporting | `IV3` |
| bookkeeping-bcf-vat-compensation | `BCF` |
| bookkeeping-kor-kleine-ondernemersregeling | `KOR` |
| bookkeeping-zzp-tax-regime | `ZZP` |
| bookkeeping-schatkistbankieren | `SBK` |
| bookkeeping-subsidie-verantwoording | `SUB` |
| bookkeeping-archiefwet-retention | `ARC` |
| bookkeeping-consultancy-project-accounting | `CPA` |

Each requirement is declared independently with its own scenarios;
cross-spec dependencies are listed in the spec header's `Depends on:`
field (e.g. `bookkeeping-vat-btw-filing` depends on T1's GL + T2's
period-close; `bookkeeping-bcf-vat-compensation` depends on both
`bookkeeping-vat-btw-filing` and `bbv-compliance`). The chain is intentionally
shallow — every T3 spec stands on T1+T2 plus at most one sibling.

## New Dependencies

None. T3 consumes:

- T1's registers (`Account`, `GLTransaction`, `GLLine`, `JournalEntry`)
- T2's registers (`Invoice`, `BankTransaction`, `FiscalPeriod`,
  `TrialBalance`, `FinancialStatement`)
- OR abstractions: lifecycle, audit-trail-immutable, RBAC,
  approval-workflow, retention (per ADR-022), `ScheduledWorkflow`,
  aggregations, calculations, widgets, mappings.
- OpenConnector sources declared symbolically: `digipoort-sbr`,
  `cbs-iv3`, `digikoppeling-bcf`. These sources land in a separate
  OpenConnector source-registration change, not in this one.
- The `@conduction/nextcloud-vue` Tier-4 manifest renderer already
  in use (bumped via `shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 10+ schemas: `VatReturn`,
  `IcpStatement`, `VatCorrection`, `BbvAccountMapping`, `Iv3Export`,
  `BcfClaim`, `KorRegime`, `UrenRegistratie`, `ZzpDeduction`,
  `SchatkistPosition`, `Subsidie`, `RetentionRule`, `Project`,
  `ProjectAssignment`, `BillableHour`, `WipBalance`, `RateCard`.
  Declares `x-openregister-lifecycle` on every state-bearing schema.
- `lib/Settings/seeds/` — new files: `btw-tariffs-2026.json`,
  `bbv-taakvelden-2024.json`, `rgs-to-bbv-mapping.json`,
  `selectielijst-gemeenten-2020.json`, `kor-thresholds-2026.json`,
  `urencriterium-thresholds.json`, `asv-model-lifecycle.json`,
  `rj-270-stages.json`, `rate-card-templates.json`.
- `src/manifest.json` — adds ~12 navigation entries grouped under
  three optional menus (`Belastingen`, `Overheid`, `Projecten` —
  visibility driven by administration type seed) and corresponding
  index/detail pages.
- Repair step extension to (a) import the new seed files into the
  relevant registers, (b) declare the OR `ScheduledWorkflow` entries
  for periodic SBR/IV3 submissions, (c) register the
  OpenConnector source references.
- **Conditional thin PHP guards** (≤2 expected, single method each):
  one for KOR omzetdrempel cross-period aggregation if OR's
  aggregation engine can't span fiscal periods in a `requires`; one
  for 1225-urencriterium running-total if similarly unsupported. Both
  documented as ADR-031 exceptions in `design.md`.
- No new PHP services. No new Vue components. No new controllers
  beyond the existing manifest-driven generic flow.

## Cross-Project Dependencies

- **OpenRegister** — depends on the abstractions listed above being
  stable. The two highest-risk shapes for T3 are: (1) lifecycle
  precondition that aggregates across periods (KOR drempel +
  urencriterium); (2) retention enforcement on records that have
  outlived their `retentionUntil` field. Both have known fall-back
  patterns documented in `design.md`'s Reuse Analysis.
- **OpenConnector** — the SBR/Digipoort + CBS-IV3 + DigiKoppeling-BCF
  source registrations are external to shillinq; this change
  declares the symbolic source names only. The source-registration
  change (`add-openconnector-nl-overheid-sources`) is its own
  proposal and is not blocking — the T3 specs declare the workflow
  shape, the source landing date is independent.
- **docudesk** — referenced by URI from `VatReturn.attachmentUri`,
  `BcfClaim.attachmentUri`, `Subsidie.beschikkingUri`, etc. No code
  coupling.

## Risks

### Risk 1: BBV taakveld + RGS↔BBV mapping shape may drift across BBV revisions

**Severity**: Medium
**Mitigation**: The taakveld catalogue is shipped as versioned seed
data (`bbv-taakvelden-2024.json`); the mapping (`rgs-to-bbv-mapping.json`)
carries an `_meta.bbvVersion` field so coexistence with a future
2026 / 2028 revision is trivial. The mapping itself is operator-
editable; the seed is a starting point, not an enum constraint.

### Risk 2: SBR/Digipoort schema requires backend secrets (PKIoverheid certificate) that don't fit the spec-only envelope

**Severity**: Low (deferred)
**Mitigation**: T3 declares the workflow trigger + payload shape;
the actual SBR/Digipoort submission (certificate handling, response
parsing, ack/nack handling) lives in OpenConnector and is
configured by the operator at install time. T3 does not embed
certificates and does not author the HTTP wrapper.

### Risk 3: KOR omzetdrempel and 1225-urencriterium aggregations may not fit OR's declarative engine

**Severity**: Medium
**Mitigation**: Both metrics are cross-period aggregations (sum of
revenue YTD; sum of billable hours YTD per administration owner).
If OR's `x-openregister-aggregations` can express them with a
period-filter projection, declarative wins. If not, ADR-031
exception path applies: a thin single-method PHP aggregation
(`KorThresholdGuard::currentYtdRevenue(adminId)` and
`UrencriteriumGuard::currentYtdHours(personId)`) called from the
relevant lifecycle `requires`. Documented in `design.md`'s
Declarative-vs-imperative decision table; resolved in `opsx-ff`
discovery before implementing cycle.

### Risk 4: Selectielijst Gemeenten retention enforcement requires OR's lifecycle to honour `retention.until` on every schema

**Severity**: Low
**Mitigation**: This is exactly the abstraction the
`bookkeeping-archiefwet-retention` spec consumes via ADR-022.
shillinq declares the retention rules in seed data and references
them from each schema's `x-openregister-lifecycle.retention`
block; the actual purge/archive enforcement is OR's. If the
retention engine is missing a feature (e.g. "anonymise
PII-bearing fields but keep the rest" — relevant for AVG-bound
records), the gap is filed as an OR issue and the relevant T3
requirement annotates the shortfall.

### Risk 5: T3-mixed-with-T2 tier-cascade — T3 specs assume T2's shapes are stable

**Severity**: Medium
**Mitigation**: T2 is being written in parallel; T3 references its
specs by slug (`bookkeeping-trial-balance`,
`bookkeeping-period-close`, `bookkeeping-accounts-payable-core`,
`bookkeeping-accounts-receivable-core`,
`bookkeeping-financial-statements`). Acceptance criterion: T3's
`opsx-apply` cycle gates on T2's specs being at least at
`Status: approved`. If T2 shape changes during review, T3 specs are
adjusted in the same review cycle (small change — the dependency
surface is FK references plus aggregation joins, not deep coupling).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction. Registers are
non-destructive — unused schemas remain queryable but unreferenced;
seeded compliance data (BTW tariffs, BBV taakvelden, Selectielijst)
remains queryable. The only side effect of rollback is loss of the
manifest navigation entries; the underlying data is preserved.

No data migration risk at the spec stage.

## Open Questions

1. **KOR drempel + urencriterium aggregation shape** — see Risk 3.
   Resolved in `opsx-ff` discovery before implementing cycle.
2. **BBV-2024 vs an imminent BBV-2026 revision** — track the
   Commissie BBV publication calendar; if a 2026 revision lands
   during implementation, the seed file gains a `_meta.bbvVersion`
   bump and the mapping seed is regenerated. Backwards-compatible.
3. **SBR/Digipoort PKI key custody** — operator-managed, not
   shillinq-managed. Confirm with security review that no PKI
   material lands in shillinq's `secrets/`.
4. **IV3 quarterly cadence vs CBS demand for monthly** — CBS
   currently mandates quarterly for gemeenten; if a Wet HOF
   revision pushes monthly, the `ScheduledWorkflow` cron changes
   from `0 0 1 */3 *` to `0 0 1 * *` — operator-configurable, no
   schema change.
5. **Subsidie terugvordering edge case** — when terugvordering
   leads to a settlement plan (afbetalingsregeling), is that a new
   `Subsidie` record or a sub-state on the existing one? `REQ-SUB-008`
   currently proposes a sub-state with a payment-plan FK. Confirm
   with the subsidie-administrateur persona during spec review.
6. **Consultancy multi-rate edge case** — when a single project
   spans rate-card revisions (e.g. Q1 partner rate €180, Q2 €195),
   does revenue recognise at the old or new rate for hours logged
   in Q1 but invoiced in Q2? `REQ-CPA-007` defers to RJ 270 §3.2.4
   ("performance obligations are measured at transaction-price as
   of the date the obligation was satisfied"); confirm with a
   project-administrator persona.



## Design

# Design — Bookkeeping Operations + NL Compliance Core (T3)

## Context

T1 (`add-shillinq-bookkeeping-foundation`) gave Shillinq a balanced
double-entry GL. T2 (`add-shillinq-bookkeeping-subledgers-close-statements`
— parallel) gives it sub-ledgers, periods, period close, AP/AR, and
basic financial statements. **T3 is where Shillinq stops being a
generic bookkeeping engine and starts being a Dutch-compliant
bookkeeping engine for SMB, ZZP, and decentralised government.**

The change groups 10 capabilities that, together, cover the
operator-facing regulatory surface a Dutch operator hits in their
first month with the product:

1. BTW filing (every operator, every month or quarter)
2. BBV (every municipality)
3. IV3 reporting (every municipality, quarterly to CBS)
4. BCF (most municipalities, claiming back recoverable VAT)
5. KOR (every small SMB approaching €20k revenue)
6. ZZP tax regime (every freelancer)
7. Schatkistbankieren (every municipality banking with the Treasury)
8. Subsidie-verantwoording (every grant recipient)
9. Archiefwet retention (every operator, every record)
10. Consultancy project accounting (Conduction's own customer profile)

Each is a separate regulatory regime with its own data shape and
its own lifecycle, but they share T1+T2's substrate. The design
philosophy is: **each compliance regime ships as a register +
lifecycle + workflow declaration, never as a service class**. The
spec-only envelope keeps T3 reviewable as a unit; implementation
lands later through `opsx-apply`.

## Goals

- Express every T3 capability as **declarative metadata** —
  schemas + `x-openregister-lifecycle` rules + manifest entries +
  `ScheduledWorkflow` declarations — per ADR-031.
- Consume every existing OR abstraction (lifecycle, audit, RBAC,
  approval, retention, scheduled workflow, aggregations,
  calculations, widgets, mappings) — per ADR-022.
- Consume every existing sibling-app abstraction (OpenConnector for
  external HTTP, docudesk for source-document storage) — per ADR-022.
- Make every spec **regulatory-citation reviewable** — a compliance
  officer or accountant reading the spec should be able to confirm
  the model maps to the cited Wet / Besluit / RJ / IFRS reference
  without code-diving.
- Keep T3 narrow enough that T4 (reporting) and T5 (cross-cutting)
  can attach without reshaping T3's schemas.

## Non-Goals

- No bespoke PHP `*Service` classes for state machines, aggregations,
  notifications, or calculations. ADR-031 anti-pattern list applies.
- No XBRL/SBR Nederlandse Taxonomie generation engine — T4's job.
  T3 ships only the SBR submission *trigger* + per-aangifte payload
  shape.
- No FX revaluation, no IFRS overlay, no group consolidation — T5.
- No bespoke Vue components beyond the manifest-driven generic
  pages. KOR threshold-warning + urencriterium tracker land as
  `x-openregister-widgets` consumed by `CnDashboardPage`.
- No app-local audit table. Every state transition is audited by
  OR's audit-trail-immutable.
- No app-local approval table. Every approval routing consumes OR's
  approval-workflow extension per ADR-022.
- No app-local retention sweep. Records expire via OR's lifecycle
  retention enforcement per the Archiefwet spec.

## Decisions

### D1 — Declarative-first, per ADR-031 (re-affirmed for the compliance domain)

Every T3 behaviour expressible as schema metadata MUST be declared in
`lib/Settings/shillinq_register.json`, not authored as PHP. The
compliance domain is **dense with state machines** (BTW-aangifte
draft→submitted→accepted, KOR opt-in lifecycle, Subsidie
aanvraag→teruggevorderd, Project offerte→archived). Every one of
these is a textbook fit for `x-openregister-lifecycle`.

| Behaviour | Declarative form |
|---|---|
| BTW-aangifte lifecycle (`draft → submitted → accepted → corrected`) | `x-openregister-lifecycle` on `VatReturn` |
| BTW correction lifecycle (suppletie) | `x-openregister-lifecycle` on `VatCorrection` |
| KOR regime lifecycle (`outside → opted-in → threshold-warning → threshold-exceeded → opted-out`) | `x-openregister-lifecycle` on `KorRegime` |
| BCF claim lifecycle (`draft → submitted → accepted → settled`) | `x-openregister-lifecycle` on `BcfClaim` |
| Subsidie lifecycle (`aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd`) | `x-openregister-lifecycle` on `Subsidie` |
| Project lifecycle (`offerte → active → on-hold → closed → archived`) | `x-openregister-lifecycle` on `Project` |
| IV3 export lifecycle (`generated → submitted → accepted`) | `x-openregister-lifecycle` on `Iv3Export` |
| Schatkist position daily aggregation | `x-openregister-aggregations` over `BankTransaction` filtered by schatkist-flagged accounts |
| BCF claim aggregation (compensable VAT YTD) | `x-openregister-aggregations` filtered by `BbvAccountMapping.bcfCompensable` |
| KOR omzetdrempel YTD aggregation | `x-openregister-aggregations` over `Invoice` (T2) — see Risk 3 |
| Urencriterium YTD aggregation | `x-openregister-aggregations` over `BillableHour` |
| RJ 270 percentage-of-completion calculation | `x-openregister-calculations` on `Project` |
| Retention enforcement on every regulated record | `x-openregister-lifecycle.retention` per ADR-022; rules loaded from `selectielijst-gemeenten-2020.json` seed |
| BBV-taakveld mapping on every GL posting | `x-openregister-mappings` between `Account.accountNumber` and `BbvAccountMapping.taakveld` |
| External submission (SBR, IV3, BCF) | OR `ScheduledWorkflow` + n8n adapter, consuming OpenConnector source |
| KOR threshold-warning widget | `x-openregister-widgets` |
| Urencriterium tracking widget | `x-openregister-widgets` |
| Notifications on threshold-warning, approval-due, retention-due | `x-openregister-notifications` |

**Alternative considered**: Author `lib/Service/VatFilingService.php`,
`lib/Service/BcfClaimService.php`, `lib/Service/KorRegimeService.php`,
etc. — one service per regime, ~300 LOC each, ~3000 LOC total.
Rejected per ADR-031. That is precisely the decidesk MotionService /
VotingService / QuorumService anti-pattern. T3 ships clean.

### D2 — External submission flows live in OpenConnector, not shillinq

SBR/Digipoort (BTW + IV3), CBS (IV3), DigiKoppeling (BCF), and any
future Belastingdienst HTTP surfaces are **OpenConnector sources**.
shillinq declares an `x-openregister-lifecycle` action on the
relevant aggregate (e.g. `VatReturn.submit`) that invokes an OR
`ScheduledWorkflow` (or event-driven workflow) which in turn calls
the OpenConnector source. shillinq never authors a PHP HTTP client
for these surfaces.

This is ADR-022 applied to external-system abstractions: OpenConnector
*is* the abstraction, and per ADR-031 §"Background jobs that
orchestrate external systems", the workflow lives outside shillinq
and is operator-configurable.

**Alternative considered**: Author per-regime SOAP/REST clients in
`lib/Service/*Client.php`. Rejected — that is the exact pattern
ADR-019 (integration registry) was built to retire. Each integration
becomes a registered source consumed by every app, including the
T3 capabilities.

### D3 — BBV taakveld mapping is a register, not an enum

BBV mandates posting every transaction against a *taakveld* (e.g.
`0.1 Bestuur`, `1.2 Openbare orde en veiligheid`, `7.1 Volksgezondheid`).
The mapping from RGS account → taakveld is **operator-editable**
(one municipality may post `4250 Subsidies cultuur` to taakveld
`5.3 Cultuurpresentatie`, another to `5.6 Media`). A hard enum
would force every municipality to fork shillinq. A register makes
the mapping per-administration override-able with a seed default.

The `BbvAccountMapping` register carries:

- `accountNumber` (FK to `Account` per T1)
- `taakveld` (enum from `bbv-taakvelden-2024.json` seed)
- `programmaCode` (operator-defined)
- `paragraafCode` (optional)
- `bcfCompensable` (boolean, drives BCF claim aggregation per T3 spec 4)
- `iv3Bucket` (enum from IV3-bestand specificaties)
- `autorisatieniveau` (enum)

The seed file `rgs-to-bbv-mapping.json` ships sensible defaults; the
operator overrides per-administration. Per ADR-022, no parallel link
table.

**Alternative considered**: Embed taakveld as a field on `Account`
itself. Rejected — accounts are administration-scoped already, and
the BBV mapping is conceptually *a relationship*, not a property of
the account. Splitting keeps `Account` general-purpose (works for
non-municipal admins too) and BBV-specific logic isolated.

### D4 — Selectielijst Gemeenten retention is operator-editable seed, consumed by OR's lifecycle

The Archiefwet 1995 + actuele Selectielijst Gemeenten 2020 define
retention periods per record-type (e.g. financial records: 7 years;
subsidy-grant records: until 10 years after settlement; meeting
minutes: indefinite). Per ADR-022, **retention is OR's abstraction,
not shillinq's** — every shillinq schema declares
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }`,
and OR's retention engine reads the rule definition from a registered
rule set.

shillinq ships `selectielijst-gemeenten-2020.json` as seed data into
the OR `RetentionRule` register. Each rule has:

- `selectielijstCode` (e.g. `5.1.2`)
- `description`
- `retentionYears` (or `retentionTrigger` for relative — "10 years
  after `settledOn`")
- `disposition` (enum: `destroy`, `archive`, `anonymise`,
  `keep_indefinite`)
- `legalBasis` (citation: Archiefwet 1995 art. X, Selectielijst §Y)

Each T3 schema (and every T1+T2 schema once T3 retrofits them in a
follow-up) declares its retention via a single rule reference. The
enforcement (purge, archive, anonymise) is entirely OR's.

**Alternative considered**: shillinq authors a
`RetentionEnforcementJob` walking every record. Rejected — that's
the ADR-031 anti-pattern documented under "Background jobs that
walk an object queue". OR's lifecycle retention enforcement is the
declarative path.

### D5 — KOR + urencriterium thresholds as seed + aggregation, alarms as notifications

KOR's €20.000 omzetdrempel and ZZP's 1225-uren-per-jaar criterium are
both **threshold-driven alarms** built on YTD aggregations. The
shape:

- `kor-thresholds-2026.json` seed declares the current law's
  threshold (€20.000 since 2020; will move with future law).
- `urencriterium-thresholds.json` seed declares 1225 hours per
  calendar year (current law since 2001; will move with future law).
- `KorRegime.ytdRevenue` is an `x-openregister-calculations` field
  aggregating from `Invoice` (T2) within the current calendar year.
- `UrenRegistratie.ytdHours` is an `x-openregister-calculations`
  field aggregating `BillableHour` for the same operator within the
  current calendar year.
- A threshold-crossing transition emits an
  `x-openregister-notifications` event at 80% (warning) and 100%
  (alarm); the lifecycle of `KorRegime` advances to
  `threshold-warning` and `threshold-exceeded` states automatically.

If the aggregation engine can express cross-period sums in
`requires`, the entire flow is declarative. If not (Risk 3), a
single-method PHP guard `KorThresholdGuard::currentYtdRevenue($adminId)`
is called from the lifecycle precondition — ADR-031 exception path,
~30 LOC, no state.

**Alternative considered**: Daily cron job recomputing YTD per
administration. Rejected — that's an ADR-031 anti-pattern *unless*
the calculation can't fit in a derived field. Derived field is
correct here.

### D6 — Project accounting (RJ 270 / IFRS 15) as calculation + aggregation, not service

Percentage-of-completion revenue recognition under RJ 270 / IFRS 15:

`recognisedRevenue = totalContractValue × (costsIncurredToDate /
totalEstimatedCosts)` (cost-to-cost method, the most common)

This is a **derived field** on `Project` —
`x-openregister-calculations` with two aggregation references
(`costsIncurredToDate` summing `GLLine` postings on cost accounts
tagged to the project; `totalEstimatedCosts` an operator-set field on
the project). The recognition posting itself is a `JournalEntry`
materialised by an OR scheduled workflow at month-end. No
`ProjectRevenueRecognitionService`.

`utilization = billableHoursThisPeriod / capacityHoursThisPeriod` is
another derived field. `project P&L` is a per-project
`x-openregister-aggregations` filtering `GLLine` by project FK.

**Alternative considered**: Author a `RevenueRecognitionService`
with `recogniseMonthEnd()`, `computeWipBalance()`, `computeProjectPl()`.
Rejected — every method maps cleanly to an OR extension; the
service would be ~400 LOC of orchestration that the schema engine
collapses to ~80 LOC of metadata.

### D7 — Schatkistbankieren as schema flag + aggregation, not parallel ledger

Wet HOF mandates municipalities bank with the Treasury beyond a
drempelbedrag. The implementation is *not* a parallel ledger —
schatkist deposits and withdrawals post to the GL like any other
bank transaction. The distinguishing markers:

- `Account.isSchatkistAccount: boolean` flag on the relevant T1
  `Account` records (Treasury deposit account, working capital
  account).
- `SchatkistPosition` register holds the **daily aggregated
  position** (one record per administration per business day), a
  derived view via `x-openregister-aggregations` over the
  flagged accounts' `GLLine` postings.
- The drempelbedrag is a seed value in `schatkist-thresholds.json`
  (currently 0.75% of begroting for small munis, 0.5% for large —
  citation: Wet HOF art. 2 + ministerial regeling).
- Threshold-crossing notifications fire via
  `x-openregister-notifications`.

The daily liquidity report is a `type: dashboard` manifest page
binding to the aggregation. No bespoke `SchatkistService`. No
parallel ledger.

**Alternative considered**: Author `LiquidityService` with daily
position recompute. Rejected — pure aggregation, fits declarative.

### D8 — Subsidie lifecycle and terugvordering as state-machine with optional sub-state

Awb 4.2 + ASV-model define a subsidie lifecycle:

`aanvraag → verleend → (eventueel) ingetrokken / gewijzigd → vastgesteld → uitbetaald → (eventueel) teruggevorderd → (eventueel) afbetalingsregeling`

The terugvordering sub-state is the trickiest — a settlement plan
(afbetalingsregeling) needs its own payment schedule. T3 models
this as:

- Main `Subsidie` lifecycle covers the canonical path.
- A `terugvordering` sub-state has a child relation to
  `BillableHour`-style `RepaymentInstallment` records (a
  small register tracking each instalment).
- Per ADR-022, no parallel state machine — the sub-state is just an
  additional lifecycle state on `Subsidie` with an
  `x-openregister-relations` reference to the instalment register.

The lifecycle states map to Awb article references in the seed
`asv-model-lifecycle.json`; the operator sees the state names
translated to plain Dutch in the UI.

### D9 — Specs sized per ADR-032 — `kind: config`

Per ADR-032, the change envelope's `kind:` is **config**. Every
declared behaviour is metadata: schema fields, lifecycle blocks,
seed data, manifest entries, scheduled workflow declarations. The
only PHP that *might* land is the conditional aggregation guards
(D5, Risk 3). Even those are single-method, no-state, ≤30 LOC each —
solidly within the thin-glue exception of ADR-032.

Spec count: 10. Each is a self-contained capability. The
intended `opsx-apply` shape is one cycle per spec (or one combined
cycle if the implementing operator prefers — the registers don't
conflict because each owns its own schemas). Hydra dispatch can
proceed per-spec with `depends_on` chaining (see D10).

### D10 — Inter-spec dependency chain (per ADR-032 `depends_on`)

```
T1: chart-of-accounts → general-ledger → journal-entries
T2: trial-balance, period-close, accounts-payable, accounts-receivable, bookkeeping-financial-statements
T3 specs and their dependencies:
  bookkeeping-vat-btw-filing        depends on: T1.general-ledger, T2.period-close
  bbv-compliance        depends on: T1.chart-of-accounts, T1.general-ledger
  iv3-reporting         depends on: T3.bbv-compliance, T2.period-close
  bcf-vat-compensation  depends on: T3.bookkeeping-vat-btw-filing, T3.bbv-compliance
  kor                   depends on: T3.bookkeeping-vat-btw-filing
  zzp-tax-regime        depends on: T1.general-ledger
  schatkistbankieren    depends on: T2.bank-reconciliation (within T2.accounts-receivable spec), T1.general-ledger
  subsidie-verantwoording depends on: T1.general-ledger
  archiefwet-retention  depends on: ALL (consumes OR's retention abstraction; declared globally)
  consultancy-project-accounting depends on: T1.general-ledger, T2.accounts-receivable
```

The dependency graph is shallow — no spec depends on more than two
T3 siblings. Hydra's `depends_on` enforcement (per hydra/CLAUDE.md)
serialises the implementing PRs accordingly.

## Reuse Analysis

| Capability needed | What already exists | T3 reuse strategy |
|---|---|---|
| State machine for every aangifte / claim / subsidy / project / KOR-regime | `x-openregister-lifecycle` (ADR-031) | Every state-bearing T3 schema declares a lifecycle block. No app-local state machines. |
| Audit trail on every state transition | OR audit-trail-immutable (ADR-022) | Consumed automatically. Every aangifte submission / KOR regime switch / subsidie vaststelling / project lifecycle change is auditable end-to-end without app config. |
| Approval routing on submission gates (BTW aangifte boven drempel; subsidie-verlening; project-closure) | OR approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`. No app-local approval table. |
| RBAC per regime (BTW-aangifteverantwoordelijke, financieel administrateur, subsidie-coordinator, project-administrateur) | OR authorization (ADR-022) | Per-schema role definitions in the register file. T3 declares 8 named roles spanning the 10 specs; mappings in `design.md`'s RBAC subsection. |
| Recurring external submission (SBR-quarterly, IV3-quarterly, BCF-quarterly) | OR `ScheduledWorkflow` + n8n adapter (per ADR-031) | Each is a workflow declaration in the register; the workflow invokes the OpenConnector source. No app-local cron job. |
| External HTTP for SBR / Digipoort / CBS / DigiKoppeling | OpenConnector sources (per ADR-019) | T3 references symbolic source names; the source registrations land in a separate OpenConnector change. |
| Retention enforcement per Selectielijst | OR `x-openregister-lifecycle.retention` (per ADR-022) | shillinq seeds the rule set and declares the rule reference on every schema. Enforcement is OR's. |
| Threshold-warning alarms (KOR, urencriterium, schatkist drempel) | OR `x-openregister-notifications` + `x-openregister-calculations` (per ADR-031) | Calculated YTD field + lifecycle state transition + notification on the transition. No app-local cron. |
| Source-document storage (BTW aangifte PDFs, BCF claim files, subsidie beschikkingen) | docudesk (per ADR-022) | Referenced by URI from the relevant register records. No file storage in shillinq. |
| BBV-taakveld mapping | `x-openregister-mappings` + `BbvAccountMapping` register | shillinq seeds a default mapping; operator overrides per-administration. No app-local join code. |
| RGS account hierarchy (T1) | Already in `Account` register from T1 | Reused as-is. T3 adds `isSchatkistAccount` (D7), `bcfCompensable` (D3 via mapping), `bbvTaakveld` (D3 via mapping). |
| Period-stamped postings (T1) | `GLLine.periodId` from T1 | Reused as-is for IV3 quarterly aggregation, KOR YTD aggregation, urencriterium YTD aggregation. |
| Trial-balance + period-close (T2) | `bookkeeping-trial-balance`, `bookkeeping-period-close` from T2 | T3 consumes for IV3 export, BCF claim aggregation, KOR threshold (uses T2's revenue aggregation). |
| AP / AR (T2) | T2's `Invoice`, `Payment` registers | T3 reuses for project accounting (CPA), KOR omzet, subsidie uitbetaling. |
| Financial statements (T2) | T2's statement aggregations | T3 reuses for IV3 XML generation (IV3 buckets aggregate the statement). |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | T3 adds ~12 menu entries; visibility driven by administration-type seed (`Belastingen` for everyone, `Overheid` for gemeente/provincie/waterschap, `Projecten` for consultancy-flagged admins). |
| Widgets on dashboard | `x-openregister-widgets` + `CnDashboardPage` | KOR threshold-warning + urencriterium tracker + schatkist position; no bespoke Vue. |

**Net new code in T3 implementation**: ~14 schema declarations + ~12
manifest pages + ~9 seed JSON files + ~5 `ScheduledWorkflow`
declarations. Possibly 2 short PHP lifecycle guards (~30 LOC each)
if Risk 3 confirms the cross-period aggregations can't run inside
the declarative engine.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each T3 behaviour was classified before
this spec was finalised:

| Behaviour | Decision | Why |
|---|---|---|
| BTW-aangifte state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, textbook fit |
| BTW-aangifte payload aggregation (sum-by-rate from GL) | Declarative (`x-openregister-aggregations`) | Periodic aggregation over GL with rate-tag projection |
| SBR/Digipoort submission | OR `ScheduledWorkflow` + OpenConnector source | ADR-031 §"Background jobs that orchestrate external systems"; the workflow itself is imperative (n8n) but lives outside shillinq |
| BBV taakveld mapping enforcement | Declarative (`x-openregister-mappings` + `BbvAccountMapping` register) | Per-administration override via register |
| IV3 export XML generation | OR Mapping (transformation spec, declarative) → file artifact via OR's file engine | XML structure is a fixed CBS schema; declarative mapping fits |
| BCF claim aggregation (compensable VAT YTD) | Declarative (`x-openregister-aggregations`) | Standard projection-filter aggregation |
| KOR omzetdrempel YTD aggregation | Declarative if engine supports period-filter; otherwise thin PHP guard (~30 LOC) per ADR-031 exception | Resolution in `opsx-ff` discovery |
| KOR regime auto-switch on threshold crossing | Declarative (`x-openregister-lifecycle` triggered by aggregation calculation reaching threshold) | Chain of `calculation → lifecycle.requires → state transition → notification` is fully declarative |
| Urencriterium running-total | Same as KOR — declarative if supported; thin guard otherwise | Same resolution path |
| ZZP deduction computation | Declarative (`x-openregister-calculations`) | Pure derivation from urencriterium status + GL revenue |
| Schatkist daily position aggregation | Declarative (`x-openregister-aggregations`) | Period-bounded aggregation over flagged accounts |
| Subsidie lifecycle (5 main states + optional sub-state) | Declarative (`x-openregister-lifecycle`) | Textbook state machine |
| Terugvordering settlement plan | Declarative (sub-state + relation to `RepaymentInstallment` register) | Per ADR-022, no parallel state machine; relation handles the per-instalment tracking |
| Archiefwet retention enforcement | Consumed from OR's `x-openregister-lifecycle.retention` | ADR-022; rules seeded, enforcement is OR's |
| Project lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook state machine |
| Project P&L | Declarative (`x-openregister-aggregations`) | Filter GL by project FK; aggregate |
| RJ 270 / IFRS 15 percentage-of-completion | Declarative (`x-openregister-calculations`) | Pure formula on existing fields |
| WIP balance | Declarative (`x-openregister-aggregations` scheduled per period) | Snapshot at period-end via scheduled workflow |
| Utilization | Declarative (`x-openregister-calculations`) | Pure derived field |
| Threshold-warning + approval-due notifications | Declarative (`x-openregister-notifications`) | Standard notification declaration |
| Dashboard widgets (KOR, urencriterium, schatkist, project) | Declarative (`x-openregister-widgets`) | Schema-derived widgets |

No service class authored in this T3 envelope. The two conditional
PHP guards (KOR + urencriterium) are explicit ADR-031 exceptions —
single method, no state, ≤30 LOC each — and are referenced *by*
the lifecycle engine. They are NOT new services.

## RBAC role inventory

T3 introduces or formalises 8 named roles. All are scoped per
administration via OR's RBAC (per ADR-022) — no global super-roles.

| Role | Scope | Granted on |
|---|---|---|
| `vat-administrator` | per administration | create/read/update `VatReturn`, `IcpStatement`, `VatCorrection`; trigger `submit` transition (with approval) |
| `bbv-controller` | per administration (gemeente) | create/read/update `BbvAccountMapping`; read `Iv3Export`; trigger `verify` transition |
| `bcf-administrator` | per administration (gemeente) | create/read/update `BcfClaim`; trigger `submit` transition (with approval) |
| `treasury-officer` | per administration (gemeente) | read `SchatkistPosition`; trigger `transferToTreasury` workflow |
| `subsidie-coordinator` | per administration | create/read/update `Subsidie`; trigger `verleen`/`vaststel`/`uitbetaal`/`terugvorder` transitions (each with approval) |
| `project-administrator` | per project | read/update `Project`; create `BillableHour`; trigger `close` transition |
| `archivist` | per administration | read-only on everything; configures retention rule overrides; triggers `archive`/`destroy` transitions via OR's lifecycle |
| `zzp-administrator` | per administration (self) | read/update `UrenRegistratie`, `ZzpDeduction`; trigger `ibAangifteExport` |

These roles are declared in each schema's `x-openregister-rbac`
block (or the equivalent OR convention); no app-local RBAC code.

## Seed Data

T3 ships 9 new seed files under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `btw-tariffs-2026.json` | Current BTW rates (21%, 9%, 0%, vrijgesteld, verlegd) with their RGS account hints | ~10 | Wet OB 1968 + Belastingdienst tariefoverzicht |
| `bbv-taakvelden-2024.json` | Complete BBV taakveld catalogue | ~50 | Besluit BBV bijlage IV |
| `rgs-to-bbv-mapping.json` | Default mapping from RGS 3.5 account to BBV taakveld + BCF-compensable flag | ~150 | Commissie BBV handreiking + IV3 specificaties |
| `selectielijst-gemeenten-2020.json` | Selectielijst-2020 retention rules per record-type | ~30 | Archiefwet 1995 art. 5 + Selectielijst-2020 publicatie |
| `kor-thresholds-2026.json` | KOR omzetdrempel (€20.000) + warning percentage (80%) | 1 | Wet OB 1968 art. 25 lid 1 |
| `urencriterium-thresholds.json` | 1225-uren-per-jaar (full) + 800-uren (starters opvolgers) | 2 | Wet IB 2001 art. 3.6 |
| `asv-model-lifecycle.json` | ASV-model subsidie lifecycle states + their Awb article references | 7 | Awb 4.2 + VNG ASV-model 2022 |
| `rj-270-stages.json` | Percentage-of-completion stage definitions + transition triggers | 4 | RJ 270 §3 + IFRS 15 §B14-B19 |
| `rate-card-templates.json` | Default rate-card structure (junior / medior / senior / partner) | 4 | Conduction internal default; operator overrides |

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (e.g. `{ "_meta": { "source": "Wet OB 1968",
  "version": "2026-01-01", "imported": "<iso-timestamp>" } }`) so
  future revisions can identify which records were template-sourced
  versus operator-authored.

All seed data is loaded via `ConfigurationService::importFromApp()`
in the repair step. Per-administration override is allowed for
every seed except `urencriterium-thresholds.json` (statutory) and
`btw-tariffs-2026.json` (statutory — operator can add rates not
override the canonical Belastingdienst rates).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| BBV / IV3 specifications revise mid-implementation | Versioned seed filenames (`bbv-taakvelden-2024.json` → `bbv-taakvelden-2026.json`); coexistence trivial; operator-editable. |
| OR engine can't aggregate across periods declaratively | ADR-031 §"PHP guards remain a legitimate seam" — thin single-method guards for KOR + urencriterium. Documented exception. |
| SBR/Digipoort certificate custody | Out of shillinq scope — operator-managed via OpenConnector source config. Security review confirms no PKI material in shillinq's `secrets/`. |
| Selectielijst retention conflicts with operator's local archiefverordening | Per-administration override on `RetentionRule` records; operator's local rule wins. Each override carries an audit citation of the local archiefverordening. |
| Subsidie terugvordering edge case (afbetalingsregeling) | Sub-state on `Subsidie` + `RepaymentInstallment` register. Reviewed with subsidie-administrateur persona. |
| Consultancy multi-rate boundary (rate-card revision mid-project) | RJ 270 §3.2.4 governs: hours logged at rate-as-of-performance-date. `RateCard` carries `effectiveFrom` / `effectiveTo`; `BillableHour.recognisedRate` snapshots at write time. |
| Project lifecycle vs subsidie lifecycle confusion | Two separate registers, two separate lifecycles, two separate notifications. Cross-reference via FK only — no shared state. |
| KOR opt-out mid-year on threshold crossing has VAT impact | The KOR lifecycle's `threshold-exceeded → opted-out` transition emits a notification and a `JournalEntry` template that the operator + accountant review. Spec REQ-KOR-006 mandates the journal entry MUST NOT auto-post — operator approval required. |
| ZZP urencriterium false positive (ziekte / zwangerschapsverlof) | The `UrenRegistratie` schema carries an `excludedReason` enum (`sick`, `parental-leave`, `vacation`, `non-billable-admin`) per Wet IB 2001 — those hours don't count toward the 1225, but the operator can mark them. Documented in REQ-ZZP-004. |
| Tier-cascade — T3 specs assume T2's shapes stable | T2 is being written in parallel; T3's `opsx-apply` cycle gates on T2 specs at `Status: approved`. Small adjustment surface (FK references). |
| Archiefwet retention triggers may anonymise audit trail | OR's retention engine MUST preserve audit-trail-immutable hashes even when source records are anonymised — this is an OR contract, not a shillinq concern. T3 documents the expectation in `bookkeeping-archiefwet-retention` REQ-ARC-006. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the 14+ new
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with ~12 new menu entries + index/detail
   page pairs (additive). Visibility predicates filter by administration
   type (`gemeente`/`zzp`/`mkb`/`consultancy`).
3. The repair step extends to import the 9 new seed files into their
   target registers.
4. `ScheduledWorkflow` entries are created for SBR-quarterly,
   IV3-quarterly, BCF-quarterly, and the daily schatkist-position
   aggregation.
5. The `BbvAccountMapping` register is seeded with the default
   `rgs-to-bbv-mapping.json` for newly-installed gemeente
   administrations only (other administration types skip).
6. The `RetentionRule` register is seeded with
   `selectielijst-gemeenten-2020.json` for every administration.

Down-direction: registers are non-destructive — disabling the seed
import + reverting the manifest leaves stranded but queryable
records. No destructive rollback at the spec-acceptance gate.

## Open Questions

1. **Cross-period aggregation shape** — see Risk 3. Resolved in
   `opsx-ff` discovery before implementing cycle.
2. **BBV-2026 vs BBV-2024 mapping** — track Commissie BBV
   publication calendar; versioned seed allows coexistence.
3. **SBR/Digipoort PKI custody** — confirmed operator-managed via
   OpenConnector source config (no PKI material in shillinq).
4. **IV3 monthly vs quarterly** — currently quarterly per CBS;
   operator-configurable cron in the `ScheduledWorkflow`.
5. **Subsidie afbetalingsregeling shape** — sub-state on `Subsidie`
   with `RepaymentInstallment` FK. Confirm with subsidie-
   administrateur persona during spec review.
6. **Multi-rate project boundary** — RJ 270 §3.2.4 applied;
   `BillableHour.recognisedRate` snapshots at write. Confirm with
   project-administrator persona during spec review.
7. **KOR opt-out auto-posting** — REQ-KOR-006 forbids; operator
   approval gates. Confirm with accountant persona.



## Tasks

# Tasks — Bookkeeping Operations + NL Compliance Core (T3)

> **Spec-only change.** Per `proposal.md` Scope, implementation code
> is deliberately out of scope here. The tasks below describe the
> work an `opsx-apply` cycle will execute against the 10 spec deltas
> — they are pre-declared so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## 0. Deduplication Check (per ADR-012 + hydra `tasks.proposal` rule)

### Task 0.1: Confirm no T3 schema or capability already exists

- **spec_ref**: all 10 specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`,
  T1's already-merged registers (Account, GLTransaction, GLLine,
  JournalEntry), T2's in-flight registers (Invoice, Payment,
  BankTransaction, FiscalPeriod, TrialBalance, FinancialStatement)
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`,
    `BbvAccountMapping`, `BbvTaakveld`, `Iv3Export`, `BcfClaim`,
    `KorRegime`, `KorThreshold`, `UrenRegistratie`, `ZzpDeduction`,
    `IbAangifteExport`, `SchatkistPosition`, `Subsidie`,
    `RepaymentInstallment`, `RetentionRule`, `Project`,
    `ProjectAssignment`, `RateCard`, or `WipBalance` schema is already
    declared.
  - GIVEN `openspec/specs/` WHEN scanned THEN no `bookkeeping-vat-*`,
    `bookkeeping-bbv-*`, `bookkeeping-iv3-*`, `bookkeeping-bcf-*`,
    `bookkeeping-kor-*`, `bookkeeping-zzp-*`,
    `bookkeeping-schatkist-*`, `bookkeeping-subsidie-*`,
    `bookkeeping-archiefwet-*`, or `bookkeeping-consultancy-*`
    capability spec already exists.
  - GIVEN T1 + T2 schemas WHEN scanned THEN no overlapping field set
    duplicates a T3 capability (e.g. T1's `Account` does NOT carry
    `bcfCompensable` or `taakveld` — those live in T3's
    `BbvAccountMapping`).
  - GIVEN `openconnector` source registrations WHEN scanned THEN
    `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` source names are
    not yet registered (their registration lives in a separate
    change — `add-openconnector-nl-overheid-sources`).
- [ ] Implement
- [ ] Test (`openspec validate` clean; manual sibling-spec scan)

### Task 0.2: Confirm consumption of existing OR abstractions, not reinvention

- **spec_ref**: every spec (cross-cutting)
- **files**: every T3 spec's `## ADDED Requirements`
- **acceptance_criteria**:
  - GIVEN any T3 spec WHEN scanned for verbs like "implement an
    audit table", "build an approval queue", "write a retention
    sweep job" THEN no such phrasing SHALL appear — every audit,
    approval, retention reference MUST cite ADR-022 + consume the OR
    abstraction.
  - GIVEN any T3 spec WHEN scanned for state-machine descriptions
    THEN every state machine MUST be declared via
    `x-openregister-lifecycle` (ADR-031), NOT via a `*Service.transition*`
    method.
  - GIVEN any T3 spec mentioning external HTTP (SBR, CBS,
    DigiKoppeling) WHEN scanned THEN the submission MUST be expressed
    as an OR `ScheduledWorkflow` consuming an OpenConnector source
    (ADR-019 + ADR-022), never as a PHP `HttpClient` wrapper.
- [ ] Implement
- [ ] Test (reviewer manually confirms during spec review)

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-vat-btw-filing spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-vat-btw-filing/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T3 (operations + NL compliance core)` /
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-period-close (T2)`
    header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-VBTW-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
  - GIVEN the spec WHEN scanned THEN ADR-022 (audit), ADR-024
    (manifest), ADR-031 (declarative lifecycle), and ADR-019 (SBR
    via OpenConnector) are cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-bbv-compliance spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-bbv-compliance/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN every `REQ-BBV-NNN:` uses RFC
    2119 + has at least one `#### Scenario:` with GIVEN/WHEN/THEN.
  - GIVEN the spec WHEN scanned THEN the taakveld + RGS↔BBV mapping
    SHALL be described as a register (`BbvAccountMapping`), not an
    enum, per ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-iv3-reporting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-iv3-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-bbv-compliance (T3), bookkeeping-period-close (T2)`.
  - GIVEN the spec WHEN scanned THEN the IV3 XML generation MUST
    be expressed via OR's mapping engine, with the ADR-031 exception
    path documented for the conditional thin PHP renderer.
  - GIVEN the spec WHEN scanned THEN the submission flow MUST be
    expressed as an OR `ScheduledWorkflow` consuming `cbs-iv3`
    source.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-bcf-vat-compensation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-bcf-vat-compensation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)`.
  - GIVEN the spec WHEN scanned THEN BCF MUST be a separate
    register (`BcfClaim`), not co-mingled with `VatReturn`.
  - GIVEN the spec WHEN scanned THEN compensable-account flagging
    MUST be a field on `BbvAccountMapping` (NOT a parallel "BCF
    accounts" table) per ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-kor-kleine-ondernemersregeling spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-vat-btw-filing (T3)`.
  - GIVEN the spec WHEN scanned THEN the omzetdrempel threshold MUST
    ship as seed data (`kor-thresholds-2026.json`), not as schema
    enum.
  - GIVEN the spec WHEN scanned THEN auto-regime switch MUST be
    declared via `x-openregister-lifecycle` triggered by
    calculation-crossing — NOT by a daily cron job per ADR-031.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-zzp-tax-regime spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN the 1225-urencriterium MUST
    be expressed as `x-openregister-calculations` aggregating
    `UrenRegistratie` (with the ADR-031 exception path documented
    for the conditional thin PHP aggregation guard).
  - GIVEN the spec WHEN scanned THEN deduction amounts MUST ship
    as seed data (`zzp-deduction-amounts-2026.json` +
    `urencriterium-thresholds.json`), per ADR-031.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-schatkistbankieren spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-schatkistbankieren/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN schatkist MUST be modelled as
    a flag on T1 `Account` + an aggregated `SchatkistPosition` register
    — NOT a parallel ledger (per ADR-022).
  - GIVEN the spec WHEN scanned THEN the daily aggregation MUST be
    declared as an OR `ScheduledWorkflow`, never as a per-app
    `*Job` class (per ADR-031 §"Background jobs that walk an object
    queue" path 2).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-subsidie-verantwoording spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-subsidie-verantwoording/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN the ASV-model lifecycle MUST
    be declared via `x-openregister-lifecycle` per ADR-031, with
    inline citations to the Awb 4.2 articles.
  - GIVEN the spec WHEN scanned THEN the terugvordering settlement-
    plan MUST be modelled as a `RepaymentInstallment` register linked
    by FK — NOT as a parallel state machine inside `Subsidie` per
    ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.9: Author bookkeeping-archiefwet-retention spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), consumes OR's lifecycle retention abstraction`.
  - GIVEN the spec WHEN scanned THEN retention enforcement MUST be
    explicitly declared as OR's responsibility per ADR-022, NOT
    reimplemented in shillinq (the spec's reviewer scenario REQ-ARC-001
    enforces this).
  - GIVEN the spec WHEN scanned THEN every existing shillinq schema
    (T1 + T2 + T3 itself) MUST be mapped to a Selectielijst code in
    REQ-ARC-003's table.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.10: Author bookkeeping-consultancy-project-accounting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-consultancy-project-accounting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)`.
  - GIVEN the spec WHEN scanned THEN RJ 270 / IFRS 15 percentage-of-
    completion MUST be expressed as `x-openregister-calculations` per
    ADR-031, NOT a `RevenueRecognitionService`.
  - GIVEN the spec WHEN scanned THEN rate-card multi-rate boundaries
    MUST use snapshot-at-write (`BillableHour.recognisedRate`) per
    RJ 270 §3.2.4 with a tested scenario.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.11: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the shared
    `nextcloud-app` spec per shillinq config.yaml `rules.proposal`,
    includes Affected Projects / Scope / Risks / Rollback / Open
    Questions, AND classifies `kind: config` per ADR-032.
  - GIVEN `design.md` WHEN inspected THEN it includes a Reuse
    Analysis table, a Seed Data section, and a Declarative-vs-
    imperative decision table per hydra `rules.design` + ADR-031
    enforcement.
- [x] Implement
- [ ] Test (peer review — bookkeeper + compliance-officer personas
  read the model end-to-end and confirm regulatory citations)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare VAT/BTW registers — `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff`

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-001 .. REQ-VBTW-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN every required field per
    REQ-VBTW-002 / 007 / 009 / 003 is present with declared typing.
  - GIVEN the `VatReturn.state` lifecycle WHEN scanned THEN the 6
    transitions from REQ-VBTW-005 are declared via
    `x-openregister-lifecycle`.
  - GIVEN the `draft → submitted` precondition WHEN inspected THEN
    `x-openregister-lifecycle.requires.approval-workflow` per REQ-VBTW-006
    is present.
  - GIVEN the `rubrieken` field WHEN inspected THEN it is declared as
    a derived field via `x-openregister-aggregations` per REQ-VBTW-004.
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; aggregation correctness
  over seeded GL fixture; approval-gate honoured)

### Task 2.2: Declare BBV registers — `BbvAccountMapping`, `BbvTaakveld`

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-001 .. REQ-BBV-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `BbvAccountMapping` WHEN loaded THEN fields per REQ-BBV-002
    are present, AND `(administrationId, accountNumber)` is unique
    (declarative constraint).
  - GIVEN the T1 `GLTransaction.post` lifecycle precondition WHEN
    scanned THEN it asserts BBV-mapping existence for municipal
    administrations per REQ-BBV-003.
- [ ] Implement
- [ ] Test (PHPUnit: unmapped account fails posting for municipal
  admin; non-municipal admin bypasses the check; BBV aggregations
  return correct totals)

### Task 2.3: Declare IV3 register — `Iv3Export`

- **spec_ref**: `bookkeeping-iv3-reporting/spec.md` (REQ-IV3-001 .. REQ-IV3-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-IV3-002 are
    present.
  - GIVEN the lifecycle WHEN scanned THEN the 6 transitions from
    REQ-IV3-005 are declared.
  - GIVEN the `buckets` field WHEN inspected THEN it is declared as
    a derived field via `x-openregister-aggregations` per REQ-IV3-003.
- [ ] Implement
- [ ] Test (PHPUnit: aggregation correctness; XML validates against
  CBS schema; submission triggers via OpenConnector mock)

### Task 2.4: Declare BCF register — `BcfClaim`

- **spec_ref**: `bookkeeping-bcf-vat-compensation/spec.md` (REQ-BCF-001 .. REQ-BCF-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-BCF-002 are
    present.
  - GIVEN the lifecycle WHEN scanned THEN the transitions from
    REQ-BCF-006 are declared with the claim-arithmetic precondition.
  - GIVEN `BbvAccountMapping` WHEN extended THEN it carries
    `compensablePercentage` per REQ-BCF-005.
- [ ] Implement
- [ ] Test (PHPUnit: claim aggregation includes only compensable
  accounts at the correct percentage; submission via OpenConnector mock)

### Task 2.5: Declare KOR registers — `KorRegime`, `KorThreshold`

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-001 .. REQ-KOR-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `KorRegime` WHEN loaded THEN fields per REQ-KOR-002 are
    present.
  - GIVEN `KorRegime.state` lifecycle WHEN scanned THEN the auto-
    transitions on threshold crossings from REQ-KOR-005 are declared.
  - GIVEN the lifecycle's post-transition action WHEN inspected
    THEN the `threshold-exceeded → opted-out` action creates a
    `JournalEntry` in `state: pending` per REQ-KOR-006 (NOT auto-posted).
  - GIVEN `KorRegime.ytdRevenue` WHEN inspected THEN it is declared
    as `x-openregister-calculations` per REQ-KOR-004 (OR a referenced
    PHP guard with ADR-031 exception annotation).
- [ ] Implement
- [ ] Test (PHPUnit: threshold-crossing transitions; notification
  fires at 80% + 100%; opt-out journal is `pending` not `posted`)

### Task 2.6: Declare ZZP registers — `UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport`

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-001 .. REQ-ZZP-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN fields per REQ-ZZP-002
    / 005 / 006 are present.
  - GIVEN `UrenRegistratie.category` WHEN inspected THEN excluded
    categories require `excludedReason` per REQ-ZZP-002.
  - GIVEN `ZzpDeduction.ytdQualifyingHours` WHEN inspected THEN it is
    declared as `x-openregister-calculations` per REQ-ZZP-003 (OR a
    referenced PHP guard with ADR-031 exception annotation).
- [ ] Implement
- [ ] Test (PHPUnit: excluded-hours filtering; deduction
  calculation correctness with starters scenarios)

### Task 2.7: Declare Schatkist register — `SchatkistPosition` + `Account` extension

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-001 .. REQ-SBK-011)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN T1 `Account` WHEN extended THEN it carries
    `isSchatkistAccount: boolean` (default `false`) per REQ-SBK-002.
  - GIVEN `SchatkistPosition` WHEN loaded THEN fields per REQ-SBK-003
    are present.
  - GIVEN the daily aggregation WHEN scanned THEN it is declared as
    `x-openregister-aggregations` filtered by `isSchatkistAccount`
    per REQ-SBK-004.
  - GIVEN the daily workflow WHEN scanned THEN it is declared as
    `ScheduledWorkflow`, NOT a `*Job` class, per REQ-SBK-007.
- [ ] Implement
- [ ] Test (PHPUnit: aggregation includes only flagged accounts;
  daily workflow generates one record per administration per day;
  threshold-crossing notification fires)

### Task 2.8: Declare Subsidie registers — `Subsidie`, `RepaymentInstallment`

- **spec_ref**: `bookkeeping-subsidie-verantwoording/spec.md` (REQ-SUB-001 .. REQ-SUB-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the two schemas WHEN loaded THEN fields per REQ-SUB-002 /
    007 are present.
  - GIVEN `Subsidie.state` lifecycle WHEN scanned THEN the 8
    transitions from REQ-SUB-003 are declared with approval-workflow
    requires on `verleen` + `terugvorder`.
  - GIVEN the `vastgesteld → uitbetaald` transition WHEN inspected
    THEN it creates a `JournalEntry` in `pending` state per REQ-SUB-005.
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; approval-gates honoured;
  repayment-plan instalments created correctly)

### Task 2.9: Declare Retention register — `RetentionRule`

- **spec_ref**: `bookkeeping-archiefwet-retention/spec.md` (REQ-ARC-001 .. REQ-ARC-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN `RetentionRule` WHEN loaded THEN fields per REQ-ARC-002
    are present.
  - GIVEN every existing shillinq schema (T1 + T2 + the 9 other T3
    schemas) WHEN scanned THEN each carries
    `x-openregister-lifecycle.retention.rule` per the REQ-ARC-003
    table.
  - GIVEN the `daysUntilRetention` derived field WHEN inspected on
    every retention-bound schema THEN it is declared via
    `x-openregister-calculations` per REQ-ARC-007.
- [ ] Implement
- [ ] Test (PHPUnit: schema-load validator enforces retention rule
  presence; operator override prevails over seeded default;
  `daysUntilRetention` calculation correctness)

### Task 2.10: Declare Project accounting registers — `Project`, `ProjectAssignment`, `RateCard`, `WipBalance`

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-001 .. REQ-CPA-014)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN fields per REQ-CPA-002 /
    004 / 005 / 008 are present.
  - GIVEN `Project.recognisedRevenue` WHEN inspected THEN it is
    declared via `x-openregister-calculations` per REQ-CPA-007.
  - GIVEN `BillableHour` (UrenRegistratie extension) WHEN inspected
    THEN it carries `recognisedRate` snapshotted at write time per
    REQ-CPA-009.
  - GIVEN the WIP snapshot workflow WHEN scanned THEN it is declared
    as `ScheduledWorkflow` triggered by period close per REQ-CPA-008.
- [ ] Implement
- [ ] Test (PHPUnit: percentage-of-completion calculation correctness;
  rate-card snapshot honours work date not invoice date; WIP snapshot
  fires on period close)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship BTW tariff seed

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-003)
- **files**: `lib/Settings/seeds/btw-tariffs-2026.json`
- **acceptance_criteria**:
  - GIVEN the seed file WHEN loaded THEN it contains at minimum the
    canonical tariffs (21%, 9%, 0%, vrij, verlegd) with SPDX header +
    `_meta.source: "Wet OB 1968"` + version field.
- [ ] Implement
- [ ] Test (PHPUnit: parse + import + every record validates)

### Task 3.2: Ship BBV taakveld catalogue seed

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-005)
- **files**: `lib/Settings/seeds/bbv-taakvelden-2024.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the full BBV
    bijlage IV taakveld catalogue (~50 codes), `_meta.bbvVersion:
    "2024"`, and SPDX header.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship default RGS↔BBV mapping seed

- **spec_ref**: `bookkeeping-bbv-compliance/spec.md` (REQ-BBV-006)
- **files**: `lib/Settings/seeds/rgs-to-bbv-mapping.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN each record validates against
    `BbvAccountMapping` AND every record carries
    `_meta.source: "seeded"`.
- [ ] Implement
- [ ] Test (PHPUnit: load + per-admin idempotent seed; operator
  override preserved on re-run)

### Task 3.4: Ship Selectielijst Gemeenten retention seed

- **spec_ref**: `bookkeeping-archiefwet-retention/spec.md` (REQ-ARC-002)
- **files**: `lib/Settings/seeds/selectielijst-gemeenten-2020.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN every record validates against
    `RetentionRule` AND covers at minimum the categories enumerated in
    REQ-ARC-002.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.5: Ship KOR threshold seed

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-003)
- **files**: `lib/Settings/seeds/kor-thresholds-2026.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains a record with
    `thresholdAmount: 20000` and `warningPercentage: 80`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.6: Ship urencriterium and ZZP-deduction seed

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-007)
- **files**: `lib/Settings/seeds/urencriterium-thresholds.json`,
  `lib/Settings/seeds/zzp-deduction-amounts-2026.json`
- **acceptance_criteria**:
  - GIVEN the two files WHEN loaded THEN they contain the values
    enumerated in REQ-ZZP-007 with SPDX headers + citations.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.7: Ship ASV-model subsidie lifecycle seed

- **spec_ref**: `bookkeeping-subsidie-verantwoording/spec.md` (REQ-SUB-006)
- **files**: `lib/Settings/seeds/asv-model-lifecycle.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 6 canonical
    lifecycle states with their Awb citations.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.8: Ship RJ-270 stage definitions seed

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-002)
- **files**: `lib/Settings/seeds/rj-270-stages.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 canonical
    stages (`initiation`, `execution`, `closeout`, `complete`).
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.9: Ship schatkist drempelbedrag seed

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-005)
- **files**: `lib/Settings/seeds/schatkist-thresholds.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 administration-
    type thresholds enumerated in REQ-SBK-005.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.10: Ship rate-card templates seed

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-005)
- **files**: `lib/Settings/seeds/rate-card-templates.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it contains the 4 default
    levels (junior, medior, senior, partner).
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.11: Extend the repair step to import every T3 seed file

- **spec_ref**: every spec's seed-data REQ
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install WHEN the repair step runs THEN
    each seed file's records appear in its target register,
    idempotent on re-run.
  - GIVEN per-administration override WHEN a record is edited THEN
    the operator edit persists across subsequent repair runs (no
    overwrite of operator-authored records).
  - GIVEN a `gemeente` administration WHEN the repair step runs THEN
    the BBV-mapping seed is applied for THAT administration;
    non-municipal admins skip the BBV seed.
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Belastingen menu (BTW, ICP, BTW-correcties)

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-011)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares `Belastingen >
    BTW-aangiften`, `Belastingen > ICP-opgaaf`, `Belastingen >
    BTW-correcties` with `type: index` + `type: detail` pages.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke for each page)

### Task 4.2: Add Overheid menu (BBV, IV3, BCF, Schatkist)

- **spec_ref**: REQ-BBV-007 + REQ-IV3-007 + REQ-BCF-008 + REQ-SBK-009
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Overheid > BBV-mapping`, `Overheid > IV3-rapportages`,
    `Overheid > BCF-claims`, `Overheid > Schatkist-positie` with
    the appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these entries
    show only for `gemeente`/`provincie`/`waterschap` administrations.
- [ ] Implement
- [ ] Test (same as 4.1 + visibility predicate test)

### Task 4.3: Add KOR + ZZP menus

- **spec_ref**: REQ-KOR-009 + REQ-ZZP-008
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Belastingen > KOR-status`, `Belastingen > Urenregistratie`,
    `Belastingen > ZZP-aftrek`, `Belastingen > IB-aangifte` with
    appropriate `type` pages.
  - GIVEN the visibility predicate WHEN evaluated THEN these
    entries show only for `mkb`/`zzp` administrations.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.4: Add Subsidies + Projecten + Bewaartermijnen menus

- **spec_ref**: REQ-SUB-008 + REQ-CPA-012 + REQ-ARC-009
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Subsidies` (with sub-pages), `Projecten > Overzicht`,
    `Projecten > Tarieven`, `Projecten > Utilisatie`,
    `Administratie > Bewaartermijnen` with appropriate `type` pages.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ScheduledWorkflow declarations

### Task 5.1: Declare quarterly SBR/Digipoort BTW workflow

- **spec_ref**: `bookkeeping-vat-btw-filing/spec.md` (REQ-VBTW-010)
- **files**: `lib/Settings/shillinq_register.json` (workflow block) or
  the OR scheduled-workflow seed
- **acceptance_criteria**:
  - GIVEN the workflow declaration WHEN scanned THEN cron defaults
    to monthly/quarterly aligned with `VatReturn.periodType`; the
    source name is `digipoort-sbr`.
- [ ] Implement
- [ ] Test (PHPUnit + integration test against an OpenConnector mock)

### Task 5.2: Declare quarterly IV3 workflow

- **spec_ref**: `bookkeeping-iv3-reporting/spec.md` (REQ-IV3-006)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is `0 0 1 */3 *`
    (quarter starts) and source is `cbs-iv3`.
- [ ] Implement
- [ ] Test (same as 5.1)

### Task 5.3: Declare quarterly BCF workflow

- **spec_ref**: `bookkeeping-bcf-vat-compensation/spec.md` (REQ-BCF-007)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is quarterly and source
    is `digikoppeling-bcf`.
- [ ] Implement
- [ ] Test (same as 5.1)

### Task 5.4: Declare daily schatkist-position workflow

- **spec_ref**: `bookkeeping-schatkistbankieren/spec.md` (REQ-SBK-007)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN cron is once-per-business-
    day (operator-configurable) and the aggregation declared per
    REQ-SBK-004 is invoked.
- [ ] Implement
- [ ] Test (PHPUnit + cron-trigger integration test)

### Task 5.5: Declare period-end WIP snapshot workflow

- **spec_ref**: `bookkeeping-consultancy-project-accounting/spec.md` (REQ-CPA-008)
- **files**: same
- **acceptance_criteria**:
  - GIVEN the workflow WHEN scanned THEN it is triggered by T2 period
    close events and generates a `WipBalance` record per active
    project.
- [ ] Implement
- [ ] Test (PHPUnit + period-close integration test)

## 6. ADR-000 reconciliation note

### Task 6.1: Update adr-000-data-model.md

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN the new 14+ T3 entities (VatReturn,
    IcpStatement, VatCorrection, VatTariff, BbvAccountMapping,
    BbvTaakveld, Iv3Export, BcfClaim, KorRegime, KorThreshold,
    UrenRegistratie, ZzpDeduction, IbAangifteExport, SchatkistPosition,
    Subsidie, RepaymentInstallment, RetentionRule, Project,
    ProjectAssignment, RateCard, WipBalance) are recorded with their
    `Primary spec:` references pointing at the new T3 specs.
  - GIVEN any pre-existing ADR-000 entries overlapping the new
    schemas WHEN read THEN reconciliation notes are appended (similar
    to T1's GLLine ↔ GeneralLedgerEntry note).
- [ ] Implement
- [ ] Test (peer review by the bookkeeper + compliance-officer
  personas)

## 7. Conditional thin PHP guards (only if Risk 3 confirms)

### Task 7.1 (conditional): Author KorThresholdGuard

- **spec_ref**: `bookkeeping-kor-kleine-ondernemersregeling/spec.md` (REQ-KOR-004)
- **files**: `lib/Lifecycle/KorThresholdGuard.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express
    cross-period revenue aggregation declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `currentYtdRevenue(string
    $adminId, int $year): float` and is referenced from
    `x-openregister-lifecycle.requires` on the `KorRegime` lifecycle.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation linking back to design.md's Declarative-vs-
    imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: invoice fixture sums correctly; edge cases for
  cancelled invoices, credit notes, partial periods)

### Task 7.2 (conditional): Author UrencriteriumGuard

- **spec_ref**: `bookkeeping-zzp-tax-regime/spec.md` (REQ-ZZP-003)
- **files**: `lib/Lifecycle/UrencriteriumGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express the
    cross-period qualifying-hours sum declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `currentYtdHours(string
    $personId, int $year): float` and is referenced from
    `x-openregister-lifecycle.requires` on the `ZzpDeduction`
    schema.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: hours fixture; excluded categories filter
  correctly; edge cases for start/end of year)

## 8. ADR-005 (security) compliance — per ADR-005 cross-cutting requirement

- **spec_ref**: every spec's authorization scenarios; the RBAC role
  inventory in `design.md`
- **acceptance_criteria**:
  - GIVEN every T3 register declaration WHEN scanned THEN every
    schema declares per-role permissions via OR's authorization
    abstraction (per ADR-022); shillinq does NOT author per-app
    RBAC code.
  - GIVEN every controller-equivalent surface (OR generic CRUD)
    WHEN scanned THEN no T3 spec authorises bypass of the RBAC layer
    (e.g. no `#[NoAdminRequired]` on lifecycle-trigger endpoints
    that grant cross-tenant access).
  - GIVEN external HTTP (SBR/Digipoort/CBS/DigiKoppeling) WHEN scanned
    THEN no PKI material or static credentials live in shillinq's
    `secrets/`; credentials are operator-managed via OpenConnector
    source config.
- [ ] Implement (verified during code review / security review)
- [ ] Test (security reviewer manual confirmation)

## 9. ADR-009 (testing) compliance — per ADR-009 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every register declaration WHEN tests are written THEN
    each schema has a PHPUnit unit test covering lifecycle
    transitions and aggregation correctness.
  - GIVEN every OR `ScheduledWorkflow` declaration WHEN tests are
    written THEN each has an integration test against an
    OpenConnector mock or local stub.
  - GIVEN every manifest entry WHEN tests are written THEN each has
    a Playwright MCP browser smoke test confirming the index/detail
    page renders correctly via `CnIndexPage`/`CnDetailPage`.
  - GIVEN every visibility predicate WHEN tests are written THEN
    each is exercised for both true (visible) and false (hidden)
    administration-type cases.
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (CI gate: `composer test` + `npm run test` + Playwright
  MCP smoke for each new menu entry)

## 10. ADR-010 (documentation) compliance — per ADR-010 cross-cutting requirement

- **spec_ref**: every spec
- **acceptance_criteria**:
  - GIVEN every T3 capability WHEN documented THEN
    `docs/user-guide/bookkeeping/` gains a per-capability page
    (bookkeeping-vat-btw-filing, bbv, iv3, bcf, kor, zzp, schatkist,
    subsidies, retention, project-accounting) per ADR-030 journeydoc
    convention.
  - GIVEN every new operator surface WHEN documented THEN screenshots
    are captured to `docs/images/` (e.g. BTW-aangifte index, KOR
    status widget, IV3 export detail, BCF claim drill-down, projecten
    overview).
  - GIVEN i18n strings WHEN scanned THEN Dutch (`nl_NL`) and English
    (`en_US`) translations exist for every operator-facing term
    introduced in T3 (BTW-aangifte, Belastingen, KOR-drempel,
    Urenregistratie, Schatkist-positie, Subsidieverlening,
    Terugvordering, Bewaartermijn, WIP, Utilisatie, etc.).
- [ ] Implement (lands with the implementing cycle, not the spec)
- [ ] Test (docs build clean; screenshots captured via Playwright MCP)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (`/test-persona-janwillem` for SMB, `/test-persona-priya` for
      ZZP, `/test-persona-noor` for municipal CISO/admin) confirms
      every regulatory citation is correctly stated
- [ ] Compliance reviewer confirms no parallel audit table, no
      parallel approval queue, no parallel retention sweep (ADR-022
      compliance)
- [ ] Architecture reviewer confirms every state machine is
      declarative per ADR-031 — zero new `*Service` classes for
      lifecycle/aggregation/calculation/notification
- [ ] T2 dependency check — T2 specs cited (`bookkeeping-trial-balance`,
      `bookkeeping-period-close`, `bookkeeping-accounts-payable`,
      `bookkeeping-accounts-receivable-core`,
      `bookkeeping-financial-statements`) are at minimum
      `Status: approved` when the implementing cycle starts
- [ ] OpenConnector source-registration dependency tracked —
      `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf` sources
      registered before first end-to-end test of the implementing
      cycle
- [ ] No source code changes outside
      `openspec/changes/add-shillinq-bookkeeping-operations/`

## Tests (company-wide ADR-009)

<!-- T3 spec-only change. Implementation-cycle tests are pre-declared on tasks 2–7 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1–2.10, 3.11, 7.1, 7.2;
      lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new
      app-specific endpoints in T3 (OR exposes register CRUD
      generically); tests cover the register HTTP surface per
      OR's contract
- [ ] Browser tests (Playwright MCP) for UI changes — declared on
      tasks 4.1–4.4; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's
      CI gate
- [ ] Integration tests against OpenConnector mocks for `digipoort-sbr`,
      `cbs-iv3`, `digikoppeling-bcf` source consumption (tasks 5.1–5.3)

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/user-guide/bookkeeping/`
      — per-capability pages authored during implementation cycle per
      ADR-030 journeydoc convention (10 pages, one per T3 spec)
- [ ] Screenshot captured and committed to `docs/images/` — authored
      during implementation cycle (~10 screenshots minimum, one per
      operator surface)
- [ ] Cross-references added to T1 + T2 docs noting the T3 capabilities
      that extend them

## i18n (company-wide ADR-005 + the i18n shared specs)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added
      during implementation cycle — required term clusters:
  - `Belastingen`, `BTW-aangifte`, `ICP-opgaaf`, `Suppletie`,
    `Verleggingsregeling`, `Indienen via Digipoort`
  - `BBV`, `Taakveld`, `Programma`, `Paragraaf`,
    `IV3-rapportage`, `BCF-claim`, `Compensabele BTW`
  - `KOR`, `Omzetdrempel`, `Vrijstelling`, `Opt-in`, `Opt-out`
  - `Urenregistratie`, `Urencriterium`, `Zelfstandigenaftrek`,
    `Startersaftrek`, `MKB-winstvrijstelling`, `IB-aangifte`
  - `Schatkist-positie`, `Drempelbedrag`, `Deposito`, `Opname`
  - `Subsidie`, `Aanvraag`, `Verleend`, `Vastgesteld`,
    `Uitbetaald`, `Teruggevorderd`, `Afbetalingsregeling`
  - `Bewaartermijn`, `Vernietigen`, `Archiveren`, `Anonimiseren`,
    `Archiefwet`, `Selectielijst`
  - `Project`, `Tarievenkaart`, `WIP`, `Onderhanden werk`,
    `Utilisatie`, `Percentage-of-completion`,
    `Omzetverantwoording`