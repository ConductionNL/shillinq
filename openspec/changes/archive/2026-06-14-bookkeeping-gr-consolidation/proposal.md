# Proposal: bookkeeping-gr-consolidation

`kind: config` per ADR-032 — the centre of mass is declarative schema metadata + manifest entries + seed data. No PHP service classes are authored.

## Summary

Introduce **Gemeenschappelijke Regeling (GR) consolidation** — a specialized T5 capability enabling multi-organization consolidated financial reporting with automatic inter-company elimination for Dutch public sector joint-venture administrations. This change adds two new declarative capabilities — group consolidation with real-time consolidated reporting, and inter-company posting with automatic elimination rules — declared as OpenRegister registers + schemas with `x-openregister-lifecycle` rules (per ADR-031), manifest entries (per ADR-024), and consuming OpenRegister's audit, RBAC, and approval-workflow abstractions instead of reimplementing them (per ADR-022). No PHP service classes, no custom database tables, no bespoke Vue components — the entire GR consolidation surface lands as register metadata + manifest entries.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Shillinq's `adr-000-data-model.md` already enumerates `ConsolidationGroup`, `ConsolidatedReport`, and `IntercompanyTransaction` entities for multi-organization consolidation (all marked **Primary spec: financial-reporting-accountability**), but none of those entities are yet declared in `lib/Settings/shillinq_register.json` — the register file ships only a placeholder `example` schema. Dutch Gemeenschappelijke Regelingen (municipal joint ventures, inter-municipal service delivery, social housing consortiums) depend on consolidated financial reporting and elimination of inter-company transactions to comply with BBV (Besluit Begroting en Verantwoording) and CCH audit requirements. Until the consolidation engine is in place, multi-organization administrations cannot close their period with accurate group-level financial statements.

This change delivers a **specialized T5 surface**: real-time group consolidation with configurable elimination rules. It assumes T1–T4 are complete (chart of accounts, general ledger, sub-ledgers, financial reporting are all in place). GR consolidation layers on top, consuming T4's reporting surface and adding group-level aggregation, inter-company routing, and elimination workflows.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas to `lib/Settings/shillinq_register.json`, adds 2 manifest navigation entries in `src/manifest.json`, ships seed templates in `lib/Settings/seeds/`.
- [ ] Project: openregister — no source changes; this change consumes existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`, approval workflow). If a needed extension is missing, it is filed as an OR issue and the gap recorded in `design.md`.
- [ ] Project: docudesk — no source changes; consolidation reports reference docudesk attachments by foreign-key URI per ADR-022.

## Scope

### In Scope

- Two new capability specs (`bookkeeping-group-consolidation`, `bookkeeping-intercompany-posting`) — see the `specs/` folder.
- Consolidation group definition — member organizations, consolidation method (`full`, `proportional`, `equity`), elimination rules declaratively defined as `x-openregister-lifecycle` rules on posting and consolidation events.
- Consolidated financial reporting with real-time aggregation and inter-company elimination:
  - Assets, liabilities, equity, revenue, expenses at the group level.
  - Automatic inter-company transaction elimination by pattern (same debit/credit account pair from two members).
  - Proportional consolidation option for joint ventures held <100%.
  - Equity consolidation method for associated entities (20–50% ownership).
- Inter-company posting — tracking of transactions between group members with automatic elimination rules:
  - Inter-company transaction header (from member, to member, amount, period, reference).
  - Automatic GL posting trigger on consolidation (debit inter-company-receivable, credit inter-company-payable on elimination).
  - Elimination rule management — per-group configurable patterns (account-pair matching, amount thresholds).
- Manifest navigation entries (Consolidation > Group Consolidation, Consolidation > Inter-Company Transactions) using `type: index`/`type: detail` page renderers from `@conduction/nextcloud-vue`.
- Audit trail consumed from OpenRegister's audit-trail-immutable abstraction per ADR-022 — DO NOT reimplement.
- Approval gate for consolidation runs (if configured by administration) — consumed from OpenRegister's approval-workflow abstraction per ADR-022.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but implementation lands via a separate `opsx-apply` cycle on the spec.
- **Sub-ledger variants (T2–T4)** — invoicing, accounts payable/receivable, trial balance, period close, bank reconciliation, and VAT/BTW filing are in separate Tier specs. GR consolidation sits on top of T4-base.
- **Subsidiary acquisition accounting** — purchase accounting, goodwill allocation, and step acquisition are explicitly out of scope; record them on a future T5+ roadmap.
- **Multi-currency translation** — FX revaluation and CTA (cumulative translation adjustment) postings live in T4-base. This spec assumes all entities in a GR operate in EUR (or remit in EUR).
- **Statutory reporting formats** — publication of consolidated reports in UBL/Peppol, SBR/XBRL, or government filing APIs live in separate specs. This spec defines the consolidated data model; rendering/publication are downstream.
- **Frontend Vue components** beyond what `CnIndexPage` / `CnDetailPage` from `@conduction/nextcloud-vue` already render generically from a register manifest. No bespoke Vue files in this spec.

## Approach

Two deltas, each adding ADDED Requirements to a brand-new spec:

1. **`bookkeeping-group-consolidation`** — declares the `ConsolidationGroup` and `ConsolidatedReport` registers. A consolidation group links multiple `Corporation` entities by a `parentOrganization` field; each consolidation run aggregates GL data from members, applies elimination rules, and materialises a `ConsolidatedReport` object. Lifecycle states (`draft → finalized → published → archived`) declared as `x-openregister-lifecycle`. Seed templates for the three Dutch GR variants (municipal service delivery, housing consortium, inter-municipal cooperation) loaded via `ConfigurationService::importFromApp()` during repair.

2. **`bookkeeping-intercompany-posting`** — declares the `IntercompanyTransaction` register and elimination-rule schema. An inter-company transaction records a sale/service between two group members; on consolidation, pairs of matching transactions (A→B debit, B→A credit) are automatically eliminated by matching account pairs and amounts. Elimination rules are stored per group and applied at consolidation time via `x-openregister-lifecycle` actions. Manual override allowed — operators can exclude specific transactions from elimination.

All specs follow the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed for traceability (`REQ-GC-*`, `REQ-ICP-*`) and numbered starting at REQ-001 within each capability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the already-available `@conduction/nextcloud-vue@^1.0.0-beta.66` (from the bump in PR #259).

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas (`ConsolidationGroup`, `IntercompanyTransaction`) plus 1 supporting schema (`EliminationRule`); declares `x-openregister-lifecycle` rules on consolidation runs and inter-company posting.
- `lib/Settings/seeds/gr-consolidation-examples.json` — new file, seed consolidation group templates for three Dutch GR variants (municipal, housing, inter-municipal). Imported via `ConfigurationService::importFromApp()` in the repair step.
- `src/manifest.json` — adds 2 navigation entries (Group Consolidation, Inter-Company Transactions) and 2 `type: index` + 2 `type: detail` page entries.
- Repair step (`lib/Migration/Version*.php` or repair class) — reuses the existing register-import pattern; one additional step to optionally seed a GR consolidation group example.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable (ADR-022), approval-workflow (ADR-022). If a needed shape is missing (e.g. scheduled consolidation runs at period boundary), the gap is filed as an OR issue and the relevant requirement is annotated in `design.md` under "Declarative-vs-imperative decision".
- **docudesk** — consolidation reports may reference attachments (audit certificates, elimination justifications) by foreign-key URI. No code coupling — the FK is a plain string field validated by JSON Schema.

## Risks

### Risk 1: Elimination rule performance at scale

**Severity**: Medium
**Mitigation**: A consolidation group with 50+ members generates 50²/2 ≈ 1250 potential inter-company transaction pairs. Matching by account + amount can be ambiguous if two members both owe the parent for the same invoice amount. Risk is deferred to implementation (opsx-ff discovery); the spec remains neutral — REQ-ICP-004 mandates elimination without prescribing matching strategy. Possible solution: require manual review for ambiguous matches, or add a `reference` field to `IntercompanyTransaction` for explicit pairing.

### Risk 2: Proportional consolidation scope

**Severity**: Low
**Mitigation**: Proportional consolidation (50%–99.9% ownership) is more complex than full consolidation. If proportional is not critical for initial deployment, mark it as deferred; the spec lists it as OPTIONAL in REQ-GC-005. Full consolidation (100% ownership) and equity consolidation (associates, 20%–50%) are simpler and ship in this spec.

### Risk 3: Elimination-rule drift from T4 account changes

**Severity**: Low
**Mitigation**: Elimination rules reference accounts by account-number FK. If T4 renames or archives an account, the rule silently does not match. Mitigation is a validation check at consolidation time: warn (not error) if an account referenced in an elimination rule no longer exists or is archived. Record the finding in `design.md` as a known limitation.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact because no implementation lands until `opsx-apply` is run on the spec. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR, run the repair step in down-direction (registers are non-destructive — unused schemas remain queryable but unreferenced). No data migration risk at the spec stage.

## Open Questions

1. **Elimination-rule matching strategy** — exact account-pair + amount match, or fuzzy matching with threshold? Resolved in `opsx-ff` discovery. The spec is shape-neutral: REQ-ICP-004 mandates elimination without prescribing the algorithm.
2. **Proportional consolidation MVP scope** — is it required for initial deployment, or deferred to a follow-up? Confirm with stakeholders.
3. **Scheduled consolidation runs** — should consolidation be triggered on-demand only, or is there a calendar schedule (e.g., last day of fiscal period)? Answer drives whether `ScheduledWorkflow` is needed.
