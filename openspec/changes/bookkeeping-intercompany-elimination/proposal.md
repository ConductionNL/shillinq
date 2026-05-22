# Proposal: bookkeeping-intercompany-elimination

`kind: spec` per ADR-032 — a procedural matching engine consuming `IntercompanyRelation` definitions and generating `IntercompanyMatch` + `IntercompanyMismatch` records, with auto-resolve tolerances and persistent relational registry. No app-local matching service; all state persisted to OpenRegister as auditable records.

## Summary

Introduce the **intercompany elimination engine** as a T2 consolidation capability for Shillinq. This capability is the matching and elimination heart of a multi-entity group's consolidation workflow: period-to-period auto-detection of intercompany transactions (via registered IC-relaciones or label-based heuristics), matching of paired transactions across counterparty entiteiten, tolerance-gebaseerde auto-resolve of small mismatches, elimination-journaal generation, and exception-queue escalation for manual investigation. The engine operates inside a consolidation-group (defined in `bookkeeping-consolidation-commercial`) and generates `EliminationJournal` records in the consolidatie-laag; it never modifies bron-administraties.

This change declares the core schemas (`IntercompanyRelation`, `IntercompanyTransaction`, `IntercompanyMatch`, `IntercompanyMismatch`, `ToleranceRule`, `CounterpartyBalance`, `EliminationJournal`), the matching algorithm as declarative aggregation + triggered workflows (per ADR-031), and requirements for detection, matching, tolerance evaluation, and mismatch classification/resolutie.

**Depends on:** `bookkeeping-consolidation-commercial` (defines consolidation-group context), `bookkeeping-multi-administratie` (provides bron-administraties), `bookkeeping-grootboek` (GL query for matched transactions).

## Motivation

Intercompany elimination is the most foutgevoelig segment of consolidation work. Dutch MKB holding-structures (holding-BV met werkmaatschappijen, vastgoed-BV's, internationale dochters) generate hundreds of IC-transacties per periode; hand-matching in Excel introduces data-entry errors, timing-lag reconciliation, and unchecked GL-restatements. A dedicated matching engine with persistent relation-registratie, multi-method detectie (account-based, label-based, explicit), tolerance-gebaseerde auto-resolve, and audittrail reduces consolidatie-cycle time from days to hours and eliminates the back-and-forth e-mailverkeer between entiteit-administrateurs.

The context-brief.md enumeert tien R's covering detectie, matching, tolerance, mismatch-resolutie, eliminatie-journaalpost-generation, counterparty-saldo-overzicht, cross-period roll-forward, multi-currency matching, and performance-at-scale.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-intercompany-elimination`); declares 7 new registers with schemas, lifecycle, aggregations, and manifest entries (Intercompany Relations, Matches, Mismatches, Tolerance Rules, Counterparty Balances, Elimination Journals).
- [ ] Project: openregister — no source changes; consumes `x-openregister-lifecycle`, `x-openregister-aggregations`, `x-openregister-lifecycle.requires` for tolerance evaluation and scheduled matching runs.
- [ ] Project: openconnector — may provide IC-data ingestion from external sources (Exact Online, Twinfield, API feeds) for entiteiten not in Shillinq; outside this change's scope.

## Scope

### In Scope

- One new capability spec (`bookkeeping-intercompany-elimination`) in `specs/bookkeeping-intercompany-elimination/spec.md`.
- The `IntercompanyRelation` register — persistent relation-registratie between groepsentiteiten with relatie-type, default-GL-rekeningen, tolerantie-parameters, active-period.
- The `IntercompanyTransaction` register — auto-detected or manually-marked IC-transacties with counterparty-aanduiding, detectie-confidence, gematched-status.
- The `IntercompanyMatch` register — paired transactions from two entiteiten with mismatch-amount, match-status (perfect, binnen-tolerantie, buiten-tolerantie, eenzijdig), gegenereerde-eliminatie-referentie.
- The `IntercompanyMismatch` register — discrepancies with oorzaak-classificatie (timing, FX, transfer-pricing, fout) and resolutie-tracking.
- The `ToleranceRule` register — configureerbare mismatch-acceptatie-rules (absoluut + relatief) with auto-resolve optie.
- The `CounterpartyBalance` register — aggregatie-view per IC-paartje per periode (vorderingen, schulden, netto-positie, omzet, inkoop, mismatch-count).
- The `EliminationJournal` register — gegenereerde eliminatie-journaalposten (niet in bron-administraties, wel in consolidatie-laag) met audit-trail naar match.
- Matching algorithm as declarative aggregation + OR scheduled-workflow (per ADR-031 §"Background jobs").
- Multi-currency matching with FX-translation-differenzen geboekt naar CTA in plaats van P&L.
- Cross-period roll-forward consistency checks and cascading impact detection.

### Out of Scope

- **Implementation code** — spec-only change. PHP matching-engine services, Vue management UI, consolidatie-layer persistence, are deliberately not in this proposal; the task list references them but implementation lands via separate `opsx-apply` cycle.
- **Currency translation + goodwill elimination** — T3/T4. This spec handles IC-matching; subsequent specs handle FX translation, goodwill, minority-interest, and full consolidatie-output per Titel 9 / IFRS 10.
- **Consolidation-commercial** — that spec defines consolidation-group context; this spec consumes it.

## Approach

One delta, adding REQ-ICE-NNN requirements to a brand-new spec:

**`bookkeeping-intercompany-elimination`** — declares the seven registers, the matching algorithm (aggregation + scheduled workflow), detectie-methodes (account-based, label-based, explicit), tolerantie-evaluation, mismatch-classificatie, eliminatie-journaalpost-generation, counterparty-saldo-views, cross-period consistency.

Each requirement uses RFC 2119 keywords in `### REQ-ICE-NNN: <name>` format with `#### Scenario:` blocks (exactly 4 hashtags) in GIVEN/WHEN/THEN form.

## New Dependencies

None. Consumes existing OpenRegister (`x-openregister-lifecycle`, `x-openregister-aggregations`, `x-openregister-lifecycle.requires`) and GL/administratie abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 7 new schemas with lifecycle blocks, aggregations, and audittrail declarations.
- `src/manifest.json` — adds manifest entries for Intercompany Relations, Matches, Mismatches, Tolerance Rules, Counterparty Balances, Elimination Journals.
- No new PHP matching service (subject to ADR-031 exception: limited guard logic if OR's aggregation engine is not yet stable).
- No bespoke Vue components (manages via OR's manifest index/detail pattern per ADR-015).

## Cross-Project Dependencies

- **Consolidation-Commercial** — defines consolidation-group context; IC-elimination operates within a group.
- **Multi-Administratie** — provides GL-access and transactie-data from bron-administraties.
- **OpenRegister** — depends on `x-openregister-lifecycle`, `x-openregister-aggregations`, `x-openregister-lifecycle.requires`, and scheduled-workflow (ScheduledWorkflow primitive).

## Risks

### Risk 1: Matching algorithm performance at scale (50 entities, 100k IC-transactions/period)

**Severity**: Medium
**Mitigation**: REQ-ICE-010 targets <5 min for typical maand-matching. If performance gates trip: incremental matching only recalculates changed transacties (per-delta logic), parallel matching per IC-relatie (OR's aggregation engine parallelises), and pre-aggregated cache via OR's extension. Profiling in implementing cycle.

### Risk 2: FX translation mismatches require precise koers-handling

**Severity**: Medium
**Mitigation**: REQ-ICE-009 mandates transactie-datum-koers + balansdatum-koers selection, with audit-trail of applied rates. Translatie-verschillen flow to CTA-restpost (EV component), not P&L. Rate source (ECB, manual override) logged per match. T3/T4 specs clarify goodwill + CTA consolidation.

### Risk 3: Mismatch classification is domain-expert task; manual resolutie queue may block consolidation

**Severity**: Low-Medium
**Mitigation**: REQ-ICE-005 offers semi-automated resolutie-pads per oorzaak (timing: interim-elimination-with-reversal-next-period, FX: post-to-CTA, transfer-pricing: generate-source-correction-booking-wizard, fout: manual-GL-entry). Exception-queue prioritised by oorzaak + age. Clear assign/escalate flow.

### Risk 4: Cross-period re-matching may cascade impact through historical periods

**Severity**: Low
**Mitigation**: REQ-ICE-008 detects backdated wijzigingen, alarms, blocks verdere-matching, and offers wizard to manage cascading impact. Audit-trail immutable; correction-path explicit (re-run prior period, propagate to future periods).

## Rollback Strategy

Spec-only change. To roll back: revert commit, delete change folder. No runtime impact. After implementation (separate cycle), rollback follows: revert implementing PR; registers are non-destructive — IC-transacties remain queryable; eliminatie-journalen stay immutable in consolidatie-laag; no GL-entries in bron-administraties are modified by rollback.

## Open Questions

1. **FX-translation koers-handling standardization** — ECB daily rates vs. monthly averages vs. manual override? Resolved in `opsx-ff` discovery; spec shape-neutral.
2. **Default tolerantie-regels per IC-relatie-type** — sales-of-goods €25/0.5%, services €10/0.25%, interest-on-loan €100/0.1%? Defaults proposed in design.md; customisable per administration.
3. **Intercompany-relatie inheritance** — holding-to-opco structure: does one relatie cover all opco-pairs or per-pair? Resolved in spec; one relatie per pairtje.
4. **OR aggregation engine maturity** — is `x-openregister-aggregations` ready for large-scale GROUP BY queries? Resolved in `opsx-ff`; if not stable, spec outlines fallback (limited PHP aggregation cache).
