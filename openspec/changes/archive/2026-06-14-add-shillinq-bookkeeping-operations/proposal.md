# Proposal: add-shillinq-bookkeeping-operations

`kind: config` per ADR-032 — the centre of mass is declarative schema metadata + workflow definitions + seed data. The one plausible PHP guard (KOR omzetdrempel + 1225-urencriterium thin aggregations) is permitted under ADR-031 §"PHP guards remain a legitimate seam" and noted as a yellow-flag in `design.md`'s Declarative-vs-imperative table.

## Summary

Add the **Tier 3 — operations + Dutch regulatory compliance core** to Shillinq's bookkeeping rollout. T3 is the layer where a working double-entry ledger (T1) plus sub-ledgers / period close / AP / AR / financial statements (T2) become a **Dutch-compliant bookkeeping engine** for the three operator segments Shillinq targets: SMB (MKB), self-employed (ZZP), and decentralised government (gemeenten, waterschappen, provincies).

T3 introduces 10 new capabilities — all declared as registers + schemas + `x-openregister-lifecycle` rules per ADR-031, all consuming OR's audit / RBAC / approval / scheduled-workflow abstractions per ADR-022, all surfaced via `src/manifest.json` entries per ADR-024. No PHP service classes for state machines, no app-local audit, no parallel link tables. External submissions (SBR/Digipoort, CBS-IV3, BCF claim file) land via OR's workflow + n8n adapter, not as bespoke PHP HTTP clients (per ADR-031 §"Background jobs that orchestrate external systems").

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

T1 + T2 give Shillinq a balanced ledger, sub-ledgers, period close, AP/AR, and statements — enough to run **bookkeeping**, not enough to run a **Dutch-compliant administration**. Every Dutch operator hitting "go live" with Shillinq immediately needs:

- A way to file **BTW** (kwartaal / maand / ICP / suppletie) and to submit through SBR/Digipoort.
- For municipal users: **BBV-conformant** posting rules, **IV3** quarterly export to CBS, and **BCF** claim administration for the btw-compensatiefonds.
- For ZZP / starters: tracking of the **1225-urencriterium**, zelfstandigenaftrek, startersaftrek, and **MKB-winstvrijstelling**, with export to the IB-aangifteformulier.
- For small MKB: **KOR** opt-in/opt-out lifecycle and €20k omzetdrempel tracking with alarm before threshold is crossed.
- For municipalities banking at the Treasury: **schatkistbankieren** drempel + daily liquidity reporting.
- For grant recipients: **subsidie-verantwoording** lifecycle per ASV-model and Awb 4.2.
- For every operator: **Archiefwet 1995 / Selectielijst Gemeenten** retention enforcement on every record-type — consumed from OR's lifecycle/retention abstraction, **not** reimplemented.
- For the consultancy operator segment (Conduction's own primary customer profile): **multi-project WIP, billable hours, RJ 270 / IFRS 15 percentage-of-completion revenue recognition**, multi-rate cards (junior / senior / partner), utilisation, project P&L.

Each of these is a separate compliance regime with its own data shape, its own lifecycle, and its own external surface. T3 frames them as 10 capability specs so they can be implemented, reviewed, and rolled out independently — but on a shared T1+T2 foundation (no GL forking, no second journal, no parallel period engine).

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md) for the canonical 5-tier breakdown. This change delivers **Tier 3**: `add-shillinq-bookkeeping-operations`. T1 (`add-shillinq-bookkeeping-foundation`) and T2 (`add-shillinq-bookkeeping-compliance`) are siblings in the same PR; T4-base, T4-specialized, and the deferred T5 are also captured in the ADR.

## Affected Projects

- [x] Project: shillinq — adds 10 new registers/schemas to `lib/Settings/shillinq_register.json`, adds ~12 manifest navigation entries in `src/manifest.json`, ships compliance seed data (BTW-tariffs, BBV-taakvelden, RGS↔BBV mapping, Selectielijst retention rules, KOR threshold, urencriterium threshold, ASV-model subsidielifecycle) in `lib/Settings/seeds/`.
- [ ] Project: openregister — no source changes; T3 consumes existing OR abstractions (lifecycle, audit, RBAC, retention, scheduled workflow, approval workflow, mappings). Gaps surface as OR issues with the relevant spec annotated in `design.md`'s Declarative-vs-imperative decision table.
- [ ] Project: openconnector — no source changes here, but the SBR/Digipoort + CBS-IV3 + BCF + DigiKoppeling adapters are consumed via OpenConnector source registrations declared in T4. T3 only references them by symbolic source name (per ADR-019).
- [ ] Project: docudesk — no source changes; BTW-aangifte PDFs and BCF-claim files are referenced by docudesk attachment URI from the relevant register records.

## Scope

### In Scope

- **10 capability specs** (one per file under `specs/`):
  - `bookkeeping-vat-btw-filing` — BTW journal, periodieke aangifte (kwartaal/maand), ICP-opgaaf, reverse-charge (verleggingsregeling), SBR/Digipoort submission, suppletie-aangifte.
  - `bookkeeping-bbv-compliance` — BBV posting rules per taakveld, programma, paragraaf, autorisatieniveau.
  - `bookkeeping-iv3-reporting` — Quarterly IV3 export to CBS.
  - `bookkeeping-bcf-vat-compensation` — Btw-compensatiefonds claim admin for municipalities.
  - `bookkeeping-kor-kleine-ondernemersregeling` — KOR opt-in / opt-out lifecycle, €20k omzetdrempel tracking with alarm, automatic regime switch.
  - `bookkeeping-zzp-tax-regime` — Zelfstandigenaftrek, startersaftrek, MKB-winstvrijstelling, 1225-urencriterium tracking, IB-aangifteformulier export.
  - `bookkeeping-schatkistbankieren` — Treasury banking flows, drempelbedrag, daily liquidity reporting.
  - `bookkeeping-subsidie-verantwoording` — Grant lifecycle per ASV-model + Awb 4.2.
  - `bookkeeping-archiefwet-retention` — Records retention per Archiefwet 1995 + Selectielijst Gemeenten, enforced via OR's `x-openregister-lifecycle.retention` per ADR-022.
  - `bookkeeping-consultancy-project-accounting` — Multi-project WIP, billable hours, RJ 270 / IFRS 15 percentage-of-completion, project P&L, multi-rate, utilisation.
- Compliance seed data in `lib/Settings/seeds/` (BTW tariffs, BBV-taakvelden, RGS↔BBV mapping, Selectielijst-2020 retention rules, KOR + urencriterium thresholds, ASV-model subsidie lifecycle template, RJ-270 stage definitions).
- `src/manifest.json` navigation entries for each operator-facing surface (BTW-aangiften list, BCF-claims list, KOR-status, Urenregistratie, Schatkist-positie, Subsidies, Projecten, etc.) driven by `type: index` / `type: detail` library renderers.
- Declarative lifecycles (per ADR-031) on every state-bearing schema: BTW-aangifte (`draft → submitted → accepted → corrected`), BCF-claim, KOR-regime (`outside → opted-in → threshold-warning → threshold-exceeded → opted-out`), Subsidie (`aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd?`), Project (`offerte → active → on-hold → closed → archived`).
- External submission flows (SBR/Digipoort, CBS-IV3, BCF) declared as OR `ScheduledWorkflow` / event-triggered workflows consumed from OpenConnector sources — **not** authored as PHP `HttpClient` wrappers in shillinq.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services, Vue components, controllers, tests, and CI changes are not in this proposal. Tasks 2+ describe the implementing cycle's work pre-declared for traceability.
- **XBRL/SBR taxonomy plumbing** — the *structural* SBR submission flow is in T3 scope (`bookkeeping-vat-btw-filing` and `iv3-reporting`), but the full XBRL Nederlandse Taxonomie (NT) generation engine for the jaarrekening is T4. T3 ships only the trigger + the per-aangifte payload shape.
- **Multi-currency translation, FX revaluation, IFRS overlay** — T5. T3's `Currency` field exists on every monetary schema but the translation engine doesn't.
- **Intercompany eliminations, group consolidation** — T5.
- **Industry-specific BBV variants** beyond core gemeenten/provincies/waterschappen — out of scope (T3+ roadmap item).
- **Bespoke Vue components** beyond the manifest-driven `CnIndexPage` / `CnDetailPage`. The KOR threshold-warning surface and the urencriterium widget are declared as `x-openregister-widgets` consumed by `CnDashboardPage`; no custom Vue.

## Approach

10 deltas, each adding ADDED Requirements to a brand-new spec under this change's `specs/` folder. Each spec follows the conduction-schema format used by T1 (RFC 2119; `### REQ-<Abbrev>-NNN:` with `#### Scenario:` with exactly 4 hashtags; GIVEN/WHEN/THEN). The abbreviations:

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

Each requirement is declared independently with its own scenarios; cross-spec dependencies are listed in the spec header's `Depends on:` field. The chain is intentionally shallow — every T3 spec stands on T1+T2 plus at most one sibling.

## New Dependencies

None. T3 consumes:

- T1's registers (`Account`, `GLTransaction`, `GLLine`, `JournalEntry`)
- T2's registers (`Invoice`, `BankTransaction`, `FiscalPeriod`, `TrialBalance`, `FinancialStatement`)
- OR abstractions: lifecycle, audit-trail-immutable, RBAC, approval-workflow, retention (per ADR-022), `ScheduledWorkflow`, aggregations, calculations, widgets, mappings.
- OpenConnector sources declared symbolically: `digipoort-sbr`, `cbs-iv3`, `digikoppeling-bcf`. These sources land in a separate OpenConnector source-registration change, not in this one.
- The `@conduction/nextcloud-vue` Tier-4 manifest renderer already in use (bumped via `shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 10+ schemas: `VatReturn`, `IcpStatement`, `VatCorrection`, `BbvAccountMapping`, `Iv3Export`, `BcfClaim`, `KorRegime`, `UrenRegistratie`, `ZzpDeduction`, `SchatkistPosition`, `Subsidie`, `RetentionRule`, `Project`, `ProjectAssignment`, `BillableHour`, `WipBalance`, `RateCard`. Declares `x-openregister-lifecycle` on every state-bearing schema.
- `lib/Settings/seeds/` — new files: `btw-tariffs-2026.json`, `bbv-taakvelden-2024.json`, `rgs-to-bbv-mapping.json`, `selectielijst-gemeenten-2020.json`, `kor-thresholds-2026.json`, `urencriterium-thresholds.json`, `asv-model-lifecycle.json`, `rj-270-stages.json`, `rate-card-templates.json`.
- `src/manifest.json` — adds ~12 navigation entries grouped under three optional menus (`Belastingen`, `Overheid`, `Projecten` — visibility driven by administration type seed) and corresponding index/detail pages.
- Repair step extension to (a) import the new seed files into the relevant registers, (b) declare the OR `ScheduledWorkflow` entries for periodic SBR/IV3 submissions, (c) register the OpenConnector source references.
- **Conditional thin PHP guards** (≤2 expected, single method each): one for KOR omzetdrempel cross-period aggregation if OR's aggregation engine can't span fiscal periods in a `requires`; one for 1225-urencriterium running-total if similarly unsupported. Both documented as ADR-031 exceptions in `design.md`.
- No new PHP services. No new Vue components. No new controllers beyond the existing manifest-driven generic flow.

## Cross-Project Dependencies

- **OpenRegister** — depends on the abstractions listed above being stable. The two highest-risk shapes for T3 are: (1) lifecycle precondition that aggregates across periods (KOR drempel + urencriterium); (2) retention enforcement on records that have outlived their `retentionUntil` field. Both have known fall-back patterns documented in `design.md`'s Reuse Analysis.
- **OpenConnector** — the SBR/Digipoort + CBS-IV3 + DigiKoppeling-BCF source registrations are external to shillinq; this change declares the symbolic source names only. The source-registration change (`add-openconnector-nl-overheid-sources`) is its own proposal and is not blocking — the T3 specs declare the workflow shape, the source landing date is independent.
- **docudesk** — referenced by URI from `VatReturn.attachmentUri`, `BcfClaim.attachmentUri`, `Subsidie.beschikkingUri`, etc. No code coupling.

## Risks

### Risk 1: BBV taakveld + RGS↔BBV mapping shape may drift across BBV revisions

**Severity**: Medium
**Mitigation**: The taakveld catalogue is shipped as versioned seed data (`bbv-taakvelden-2024.json`); the mapping (`rgs-to-bbv-mapping.json`) carries an `_meta.bbvVersion` field so coexistence with a future 2026 / 2028 revision is trivial. The mapping itself is operator-editable; the seed is a starting point, not an enum constraint.

### Risk 2: SBR/Digipoort schema requires backend secrets (PKIoverheid certificate) that don't fit the spec-only envelope

**Severity**: Low (deferred)
**Mitigation**: T3 declares the workflow trigger + payload shape; the actual SBR/Digipoort submission (certificate handling, response parsing, ack/nack handling) lives in OpenConnector and is configured by the operator at install time. T3 does not embed certificates and does not author the HTTP wrapper.

### Risk 3: KOR omzetdrempel and 1225-urencriterium aggregations may not fit OR's declarative engine

**Severity**: Medium
**Mitigation**: Both metrics are cross-period aggregations (sum of revenue YTD; sum of billable hours YTD per administration owner). If OR's `x-openregister-aggregations` can express them with a period-filter projection, declarative wins. If not, ADR-031 exception path applies: a thin single-method PHP aggregation (`KorThresholdGuard::currentYtdRevenue(adminId)` and `UrencriteriumGuard::currentYtdHours(personId)`) called from the relevant lifecycle `requires`. Documented in `design.md`'s Declarative-vs-imperative decision table; resolved in `opsx-ff` discovery before implementing cycle.

### Risk 4: Selectielijst Gemeenten retention enforcement requires OR's lifecycle to honour `retention.until` on every schema

**Severity**: Low
**Mitigation**: This is exactly the abstraction the `bookkeeping-archiefwet-retention` spec consumes via ADR-022. shillinq declares the retention rules in seed data and references them from each schema's `x-openregister-lifecycle.retention` block; the actual purge/archive enforcement is OR's. If the retention engine is missing a feature (e.g. "anonymise PII-bearing fields but keep the rest" — relevant for AVG-bound records), the gap is filed as an OR issue and the relevant T3 requirement annotates the shortfall.

### Risk 5: T3-mixed-with-T2 tier-cascade — T3 specs assume T2's shapes are stable

**Severity**: Medium
**Mitigation**: T2 is being written in parallel; T3 references its specs by slug (`bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-financial-statements`). Acceptance criterion: T3's `opsx-apply` cycle gates on T2's specs being at least at `Status: approved`. If T2 shape changes during review, T3 specs are adjusted in the same review cycle (small change — the dependency surface is FK references plus aggregation joins, not deep coupling).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact because no implementation lands until `opsx-apply` is run on the spec. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR, run the repair step in down-direction. Registers are non-destructive — unused schemas remain queryable but unreferenced; seeded compliance data (BTW tariffs, BBV taakvelden, Selectielijst) remains queryable. The only side effect of rollback is loss of the manifest navigation entries; the underlying data is preserved.

No data migration risk at the spec stage.

## Open Questions

1. **KOR drempel + urencriterium aggregation shape** — see Risk 3. Resolved in `opsx-ff` discovery before implementing cycle.
2. **BBV-2024 vs an imminent BBV-2026 revision** — track the Commissie BBV publication calendar; if a 2026 revision lands during implementation, the seed file gains a `_meta.bbvVersion` bump and the mapping seed is regenerated. Backwards-compatible.
3. **SBR/Digipoort PKI key custody** — operator-managed, not shillinq-managed. Confirm with security review that no PKI material lands in shillinq's `secrets/`.
4. **IV3 quarterly cadence vs CBS demand for monthly** — CBS currently mandates quarterly for gemeenten; if a Wet HOF revision pushes monthly, the `ScheduledWorkflow` cron changes from `0 0 1 */3 *` to `0 0 1 * *` — operator-configurable, no schema change.
5. **Subsidie terugvordering edge case** — when terugvordering leads to a settlement plan (afbetalingsregeling), is that a new `Subsidie` record or a sub-state on the existing one? Currently proposed as sub-state with a payment-plan FK. Confirm with the subsidie-administrateur persona during spec review.
6. **Consultancy multi-rate edge case** — when a single project spans rate-card revisions (e.g. Q1 partner rate €180, Q2 €195), does revenue recognise at the old or new rate for hours logged in Q1 but invoiced in Q2? Defers to RJ 270 §3.2.4 ("performance obligations are measured at transaction-price as of the date the obligation was satisfied"); confirm with a project-administrator persona.
