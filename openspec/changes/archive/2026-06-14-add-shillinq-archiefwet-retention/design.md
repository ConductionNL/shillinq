# Design — Archiefwet Retention

**Status:** pr-created

## Context

The Archiefwet 1995 + actuele Selectielijst Gemeenten 2020 define
retention obligations on every Dutch government record. Provincies
and waterschappen follow sector-specific selectielijsten;
non-government operators follow their own archiefverordening but
still default to ASV-model-like retention.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare `RetentionRule` as a register seeded from the
  Selectielijst (per ADR-031).
- Map every existing Shillinq schema to a Selectielijst code via
  `x-openregister-lifecycle.retention.rule`.
- Consume OpenRegister's retention enforcement engine per ADR-022
  — shillinq does NOT author parallel retention code.
- Allow per-administration override above the statutory minimum.
- Preserve audit-trail-immutable hashes through anonymisation.

## Non-Goals

- No app-local retention sweep / `*Job` / service (ADR-031 + ADR-022
  anti-patterns).
- No industry-specific selectielijsten beyond gemeente (roadmap).
- No PII anonymisation engine — operator-configured via OR's
  retention engine.

## Decisions

### D1 — `RetentionRule` register seeded from Selectielijst

Per Wet HOF and the Archiefbesluit, retention periods are not
hardcoded in apps — they're per-Selectielijst-code rules. The
register holds `(selectielijstCode, description, retentionYears or
retentionTrigger, disposition: destroy|archive|anonymise|keep_indefinite,
legalBasis)`.

Loaded via `ConfigurationService::importFromApp()` from
`selectielijst-gemeenten-2020.json` for every administration on
install (non-municipal admins get the same baseline; operators
override per administration).

### D2 — Per-schema rule reference via `x-openregister-lifecycle.retention.rule`

Every Shillinq schema declares
`x-openregister-lifecycle.retention.rule: "selectielijst:5.1.2"`
(for example). OR's retention engine reads the rule definition
from the register and enforces. This is the entire shillinq
contribution — declarative metadata.

The mapping table lives in `REQ-ARC-003` as the spec's compliance
backbone: every T1 + T2 + T3 schema mapped to a Selectielijst code
with citation.

### D3 — Per-administration override above statutory minimum

Operators may extend retention (an internal audit policy mandating
10 years for financial records vs. the Archiefwet floor of 7
years), but NEVER shorten below the floor. Enforced by a
`RetentionRule` validation: `customRetentionYears >= statutoryMinimum`
per `REQ-ARC-004`.

### D4 — Audit-trail hashes preserved through anonymisation

When OR's retention engine anonymises a record (PII fields zeroed
but financial fields preserved), the audit-trail-immutable hash
chain MUST remain intact. This is an OR contract documented as
expectation in `REQ-ARC-006`; gated at the implementing PR's
integration test.

### D5 — `daysUntilRetention` derived field

Every retention-bound schema gets a `daysUntilRetention` field via
`x-openregister-calculations`, computing `(retentionDate - today)`
for operator visibility. Surfaced in the Bewaartermijnen index page.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `RetentionRule` storage | OR generic CRUD | Standard register |
| Per-schema rule reference | `x-openregister-lifecycle.retention.rule` (ADR-022) | Declared on every schema |
| Retention enforcement (purge/archive/anonymise) | OR retention engine (ADR-022) | Consumed entirely; shillinq does not implement |
| Audit-trail preservation | OR audit-trail-immutable (ADR-022) | Hashes preserved through anonymisation by OR contract |
| `daysUntilRetention` derived field | `x-openregister-calculations` (ADR-031) | Standard derivation |
| RBAC (archivist) | OR authorization (ADR-022) | Per-schema role |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 menu entry under `Administratie` |
| Selectielijst seed | `ConfigurationService::importFromApp()` | `selectielijst-gemeenten-2020.json` |

**Net new code in implementation**: 1 schema declaration + N
schema retention-rule references (T1 + T2 + all 9 sibling T3
schemas) + 1 manifest entry + 1 seed JSON. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Per-schema retention rule | Declarative (`x-openregister-lifecycle.retention.rule`) | ADR-022 — OR's abstraction |
| Retention enforcement | Consumed from OR | ADR-022; shillinq does not author |
| `daysUntilRetention` derivation | Declarative (`x-openregister-calculations`) | Standard derivation |
| Audit-trail preservation | OR contract | ADR-022 |
| Operator override validation | Declarative (schema validation rule) | Standard constraint |

No service class authored. No background job authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/selectielijst-gemeenten-2020.json` | Selectielijst-2020 retention rules per record-type | ~30 | Archiefwet 1995 art. 5 + Selectielijst-2020 publicatie |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
`feedback_spdx-in-docblock.md`. `_meta` block with `source` and
`version`. Loaded via the repair step for every administration on
install; per-administration override allowed above statutory minima.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR retention engine feature gaps | OR issues filed; spec does NOT fall back to shillinq-authored retention code |
| Operator override below statutory floor | Validation rule per `REQ-ARC-004` rejects |
| Anonymisation breaks audit hash | OR contract documented in `REQ-ARC-006`; gated at integration test |
| Selectielijst revision | Versioned seed (`selectielijst-gemeenten-2020.json` could ship as `2026` if VNG publishes); coexistence trivial |
| Cross-schema mapping completeness | `REQ-ARC-003` mapping table enumerates every T1+T2+T3 schema; spec-review gate confirms |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 1 schema + N retention-
   rule references on existing schemas (additive on existing schemas).
2. `src/manifest.json` adds 1 navigation entry.
3. The repair step imports the Selectielijst seed for every
   administration (idempotent).
4. OR's retention engine begins enforcing on next sweep.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing rules + per-schema references remain
queryable; OR's enforcement re-engages on next install.

## Open Questions

1. **OR selective-anonymisation gap** — confirm during `opsx-ff`;
   file OR issue if missing.
2. **Sector-specific selectielijsten** — provincies / waterschappen
   / sector-specific roadmap items.
