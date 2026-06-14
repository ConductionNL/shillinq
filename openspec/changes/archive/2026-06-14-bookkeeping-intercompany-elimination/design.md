# Design — Intercompany Elimination Engine

## Context

Consolidation-groepen (multi-entity structures) generate intercompany-transacties (IC-transacties) that are economically legitimate at entiteit-level but misleading in geconsolideerde jaarrekening (group-level profit/loss, balans, free-cash-flow). An elimination engine matches paired IC-transacties across entiteiten, resolves timing- en FX-differences via tolerance-rules, auto-generates eliminatie-journaalposten in consolidatie-laag (not in bron-administraties), and escalates unexplained mismatches to manual queue.

The change is **spec-only**. Implementation lands later via `opsx-apply` and Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire IC-elimination surface as **declarative metadata** — schemas, matching algorithm as aggregation + scheduled workflow, tolerance-rules, manifest entries — per ADR-031.
- Enforce **persistent IC-relatie-registratie** — no "where are the IC-pairs?" spreadsheet; one source of truth.
- Deliver **tolerance-gebaseerde auto-resolve** — small timing/FX-differences auto-accepted per configured rules; manual queue only for material/unexplained mismatches.
- Enable **multi-currency matching** — transacties in different functional-valuta matched via configured koers, translatie-verschillen flowed to CTA (not P&L).
- Make the spec a **competent-consolidatie-accountant-readable contract** — Dutch MKB IC-workflow recognisable end-to-end (relatie-setup, detectie, matching, tolerance-eval, mismatch-resolutie, eliminatie-generatie, roll-forward).

## Non-Goals

- No PHP IC-matching service, no `IntercompanyMatchingService.php`.
- No GL-modification in bron-administraties; all eliminatie-journaalposten live in consolidatie-laag.
- No UBL / Peppol IC-confirmation-letter generation — that's a T4 consolidation-output spec.
- No goodwill-elimination, minority-interest, or currency-translation — those are T3/T4 specs.

## Decisions

### D1 — IC-relatie-registratie is persistent, not per-period spreadsheet

`IntercompanyRelation` captures the stable relatie between two groepsentiteiten: relatie-type (sales-of-goods, services, royalty, etc.), default-GL-rekeningen at both ends, tolerantie-parameters (absoluut + relatief). This relatie is registered once and reused period-after-period, eliminating the "which entiteiten are IC-paired?" question.

### D2 — Matching algorithm is aggregation + scheduled workflow, not PHP service

The matching algorithm (per-relatie, aggregate transaction-amounts A-zijde vs. B-zijde, calculate delta, evaluate tolerance) is expressed via `x-openregister-aggregations` queries and `x-openregister-lifecycle.requires` lifecycle-guards per ADR-031. Periodic runs (maand, kwartaal, jaar) trigger via OR's `ScheduledWorkflow` primitive. Zero PHP matching logic.

### D3 — Multi-method detectie: account-based, label-based, explicit

`IntercompanyTransaction` records are detected via three methodes with configurable confidence:
- **Account-based**: transactie lands on a registered IC-rekening (high-confidence).
- **Label-based**: counterparty-naam matches a groep-entiteit (medium-confidence, review-queue).
- **Explicit**: entiteit-administrator marks transactie as IC manually (high-confidence).

### D4 — Tolerantie-regels are aggregation preconditions, not service-logic

`ToleranceRule` defines mismatch-acceptance thresholds (absoluut + relatief, applied via AND/OR strategy). Tolerance-evaluation happens in the matching aggregation; within-tolerance mismatches auto-accept, buiten-tolerantie escalates. Configureerbaar per relatie-type or globally.

### D5 — Mismatch oorzaak-classificatie enables semi-automated resolutie

`IntercompanyMismatch` includes oorzaak-classificatie (timing, FX, transfer-pricing, fout, unknown). Each oorzaak offers a resolutie-pad: timing → interim-elimination-with-reversal-next-period, FX → post-to-CTA-restpost, transfer-pricing → generate-correction-booking-wizard, fout → manual-GL-investigation. Resolutie tracked per mismatch.

### D6 — Eliminatie-journaalposten live in consolidatie-laag, immutable, audittrailed

`EliminationJournal` records are generated in consolidatie-layer (not in bron-administraties) with balanced debet/credit per matched transactie-pair. Each journaal-entry references the triggering match, source-transacties, and actor. No GL-modification; eliminatie is purely consolidatie-specific.

### D7 — Counterparty-saldo-overzicht provides one-view IC-status per relatie

`CounterpartyBalance` aggregates per IC-paartje per periode: openstaande vorderingen, schulden, netto-positie, omzet-flows, mismatch-count, last-update-timestamp. Controllers + administrateurs see consistent view; audit-trail links back to transacties.

### D8 — Cross-period roll-forward consistency enforced, cascading impact detected

`IntercompanyMatch` records carry periode-referentie. If a prior-period match is corrected after the fact, REQ-ICE-008 detects backdated wijziging, alarms, blocks verdere-matching, and offers reconciliation-wizard.

### D9 — Multi-currency matching via transactie-datum + balansdatum koersen

For inter-company-transacties in different functional-valuta: match via conversion to gemeenschappelijke rapportage-valuta at transactie-datum-koers. Balansdatum-saldo gets translatie-risico-update at slotkoers. Translatie-verschillen flow to CTA-restpost in EV (per IFRS 21).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| IC-relatie registry + lifecycle | none | New `IntercompanyRelation` register with active-from/to period and edit audit-trail |
| Detectie (account-based, label-based, explicit) | GL-query capabilities | GL-query per registered IC-rekening; debiteur/crediteur-naam matcher; explicit-tag support |
| Matching algorithm | OR `x-openregister-aggregations` | Per-relatie GROUP BY + SUM + delta calculation as aggregation; no PHP service |
| Tolerance evaluation | OR `x-openregister-lifecycle.requires` | Lifecycle guard consuming aggregation result; auto-accept within-tolerance, escalate buiten-tolerantie |
| Scheduled matching runs | OR `ScheduledWorkflow` (per ADR-031 path 2) | Monthly/quarterly/annual runs via scheduled primitive, not shillinq *Job class |
| Mismatch classification & resolutie | none | New `IntercompanyMismatch` register with oorzaak-enum + resolutie-tracking; workflow per oorzaak |
| Eliminatie-journaalpost generation | T1 `JournalEntry` materialisation pattern | Matched pair triggers lifecycle action, materialises balanced `EliminationJournal` with debet/credit per relatie GL-accounts |
| Counterparty-balance aggregation | OR `x-openregister-aggregations` | GROUP BY (entiteit-a, entiteit-b, periode) with SUM (vorderingen, schulden, omzet, mismatch-count) |
| FX translation handling | T3 treasury/FX-management (future) | Translatie-verschillen post to CTA-restpost per oorzaak-flag; T3 spec handles goodwill + CTA consolidation |
| Cross-period consistency | OR audit-trail + constraints | Periode-linking in IntercompanyMatch; backdated-wijziging detection + cascade-impact wizard |
| Manifest navigation | Shillinq manifest pattern (ADR-015) | 7 register index/detail pages + exception-queue view |

**Net new code in implementation cycle**: 7 schema declarations + 1 matching aggregation + 2 lifecycle-guards (tolerance evaluation + scheduled-workflow integration) + 1 mismatch-resolutie-action-router + 3 manifest index/detail page pairs. At most 1 limited PHP aggregation-cache fallback if OR's engine not yet stable.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| IC-relatie registration | Declarative (schema + manual operator entry) | One-time setup, no logic |
| Detectie (account-based) | Aggregation query (GL query per IC-rekening) | No logic, pure query |
| Detectie (label-based) | Aggregation query (debiteur/crediteur-naam match) | Simple pattern match, no business logic |
| Detectie (explicit) | Tag-based flag (operator marks transactie) | No logic, manual flag |
| Matching aggregation (delta calc) | Declarative aggregation (`x-openregister-aggregations`) | Pure GROUP BY + SUM |
| Tolerance evaluation | Lifecycle guard (`x-openregister-lifecycle.requires`) | Pure threshold comparison |
| Scheduled matching runs | OR `ScheduledWorkflow` (path 2, ADR-031) | Background job, no PHP *Job class |
| Mismatch classification | Operator-authored field (oorzaak-enum) | Domain-expert judgment required |
| Mismatch resolutie routing | Workflow action (logic-per-oorzaak template) | Per-oorzaak path is template; operator executes |
| Eliminatie-journaalpost generation | Lifecycle action invoking T1's materialisation | No new service, reuses existing pattern |
| Counterparty-balance aggregation | Declarative aggregation | GROUP BY + SUM, no logic |
| Cross-period consistency detection | Audit-trail query + constraint check | Query for period-mismatch, no logic |

No service class authored in this envelope (subject to ADR-031 exception: at most 1 limited PHP aggregation-cache class if OR's engine is immature).

## Seed Data

None — IC-relaties are operator-configured per consolidatie-groep on first-use. No templates.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Matching algorithm performance at 50 entiteiten, 100k IC-transacties/month | Per-relatie parallel matching (OR aggregation engine parallelises); incremental re-match only recalculates changed transacties; cache via OR extension if needed. Profiling in implementing cycle. |
| FX-translation koers-selection (daily ECB vs. monthly average vs. manual override) | Spec mandates transactie-datum-koers and balansdatum-koers with audit-trail; standard (ECB daily) selected in `opsx-ff` discovery. |
| OR's aggregation engine not yet stable for large-scale GROUP BY | Spec outlines fallback: limited PHP cache (single-method aggregation class) per ADR-031 exception; remove when OR engine matures. Shape-neutral. |
| Mismatch resolutie queue grows faster than controllers can resolve | Mismatch prioritisation by oorzaak + age; escalation-path (assign, escalate, schedule callback); SLA tracking. Resolutie-pads semi-automated per oorzaak. |
| Backdated correcties in prior periods cascade impact through future periods | Explicit cascade-detection + wizard per REQ-ICE-008; audit-trail immutable; re-run prior period, propagate forward. |
| Tolerance-rules drift per entiteit, losing consistency | Global default rules + per-relatie overrides; centralized configuration; change-audit. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` patched with 7 new schemas (additive).
2. `src/manifest.json` patched with 7 index/detail page pairs (additive).
3. Consolidatie-laag persistence (separate from bron-administraties) configured in shillinq runtime.
4. If OR's aggregation engine not yet stable, `lib/Aggregation/IntercompanyAggregationCache.php` ships (~50 LOC, ADR-031 exception annotated); removed when OR engine lands.

Down-direction: registers non-destructive; reverting removes manifest entries; IC-data remains queryable; eliminatie-journalen immutable in consolidatie-laag.

## Open Questions

1. **FX-koers selection standard** — ECB daily closing, monthly average, manual override? Resolved in `opsx-ff`; spec shape-neutral.
2. **Default tolerantie-thresholds per IC-relatie-type** — sales-of-goods €25/0.5%, services €10/0.25%, interest €100/0.1%, transfers €1000/0.05%? Proposed in task-6; customisable per administration.
3. **Intercompany-relatie inheritance in holding-opco structure** — does a single relatie between holding and opco-group cover all opco-pair combinations, or explicit one-per-pair? Design decision: explicit one-per-pair (granular control, audit clarity).
4. **OR aggregation engine maturity for GROUP BY + SUM at scale** — is it production-ready? Resolved in `opsx-ff` discovery.
5. **Mismatch resolutie SLA and escalation policy** — who receives escalations? When? What's the expected time-to-resolve per oorzaak? Resolved in implementing cycle UX review.
