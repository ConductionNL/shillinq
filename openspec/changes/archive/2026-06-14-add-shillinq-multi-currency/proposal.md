# Proposal: add-shillinq-multi-currency

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + a scheduled-workflow record. No
PHP service classes are authored.

## Summary

Introduce the **multi-currency postings & revaluation** capability for
Shillinq as part of the Tier 4 advanced bookkeeping engine (per
`adr-001-bookkeeping-tier-roadmap.md`). This change extends T1
`GLLine` additively with `baseCurrencyAmount` / `transactionCurrency` /
`fxRate` / `fxRateSource` / `fxRateDate` (modifying T1's REQ-GL-003 and
renaming `GLLine.currency` to `transactionCurrency` per the canonical
`## MODIFIED Requirements` + `## RENAMED Requirements` pattern),
declares the `FxRate` register (ECB + manual + internal-policy), wires
daily ECB ingestion as an OR `ScheduledWorkflow` consuming
openconnector (per ADR-022 + ADR-031 path 2), declares period-end
revaluation as a scheduled workflow, and pre-positions IAS 21
functional-currency translation for T5 consolidation via OR `Mapping`.
No PHP `FxRevaluationService`, no embedded ECB HTTP client.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Any administration with foreign customers / suppliers needs FX-aware
postings, period-end revaluation of open foreign-currency positions,
and (when consolidating) IAS 21 functional-currency translation. T1's
single-currency assumption (REQ-GL-003 carries one `currency` field
and one `amount` field) is explicitly marked "T5 will revisit when
multi-currency lands" — this change is that revisit, lifted into T4
because every cross-border SMB needs it long before group consolidation.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`FxRate`),
  additive extensions to T1 `GLLine` (`baseCurrencyAmount`,
  `transactionCurrency` rename, `baseCurrency`, `fxRate`,
  `fxRateSource`, `fxRateDate`), adds 1 manifest navigation entry,
  registers 2 scheduled workflows (daily ECB ingest, period-end
  revaluation).
- [ ] Project: openregister — no source changes; this change consumes
  `x-openregister-lifecycle`, audit-trail-immutable, RBAC,
  `Mapping`, `ScheduledWorkflow`.
- [ ] Project: openconnector — no source changes; this change *consumes*
  an openconnector `Source` record for ECB FX rates (daily rate
  ingestion).

## Scope

### In Scope

- One new capability spec (`bookkeeping-multi-currency`) — see the
  `specs/` folder. The spec carries a `## MODIFIED Requirements` block
  superseding T1 `bookkeeping-general-ledger` REQ-GL-003 plus a
  `## RENAMED Requirements` block (`currency` → `transactionCurrency`).
- `FxRate` register declaration (ECB + manual + internal-policy
  source).
- Additive multi-currency extension on T1 `GLLine` (no destructive
  migration; T1's `amount` is reinterpreted as `transactionAmount` —
  on-the-wire JSON name preserved for backwards compat).
- Daily ECB FX-rate ingestion as an OR `ScheduledWorkflow` calling an
  openconnector source (per ADR-031 path 2 + ADR-022).
- Period-end revaluation of open foreign-currency positions as an OR
  `ScheduledWorkflow` triggered on period close (no PHP
  `FxRevaluationService`).
- Realised gain/loss on settlement as a declarative
  `x-openregister-lifecycle` action on the sub-ledger record.
- IAS 21 functional-currency translation for consolidation declared as
  an OR `Mapping` referencing the `FxRate` register (per ADR-022).
- Manifest navigation entry (Bookkeeping > FX Rates) using
  `type: index` / `type: detail` renderers.

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **Intercompany eliminations and full group consolidation** — T5's
  job. Multi-currency declares IAS 21 translation rules because
  translation is unavoidable, but the eliminations engine itself is
  T5.
- **Operator-selectable monthly revaluation** — period-end is the
  default per REQ-MC-004; monthly is deferred to future enhancement.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` render generically.

## Approach

One delta with three sections:

1. **`## MODIFIED Requirements`** — supersedes T1 `bookkeeping-general-ledger`
   REQ-GL-003 with the multi-currency `GLLine` field set, the
   FX-orientation contract (`baseCurrencyAmount = transactionAmount ×
   fxRate`, no reciprocation against `FxRate.rate`), the balance
   invariant computed in `baseCurrencyAmount`, and three scenarios
   covering same-currency, foreign-currency, and join-resolves-rate.

2. **`## RENAMED Requirements`** — renames T1 `GLLine.currency` to
   `transactionCurrency` so the line's foreign-currency designation
   is unambiguous against the newly-added `baseCurrency` field.

3. **`## ADDED Requirements`** — declares `REQ-MC-001` (anchor),
   `REQ-MC-002` (`FxRate` register + ECB feed inversion), `REQ-MC-003`
   (ECB scheduled-workflow ingestion), `REQ-MC-004` (period-end
   revaluation), `REQ-MC-005` (IAS 21 translation via OR `Mapping`),
   `REQ-MC-006` (manifest navigation).

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each new requirement is prefixed `REQ-MC-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions, an
operator-configured openconnector ECB source, and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`FxRate`)
  with `x-openregister-lifecycle` and uniqueness constraint on
  (`transactionCurrency`, `baseCurrency`, `date`, `source`);
  additively patches T1 `GLLine` schema with multi-currency fields
  (no rename of on-disk `amount`); declares 2 `ScheduledWorkflow`
  records.
- `src/manifest.json` — adds 1 navigation entry (FX Rates) with
  filter chips per REQ-MC-006.
- No new PHP services. No new Vue components. No new controllers. No
  new TimedJobs.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  audit-trail-immutable, RBAC, `Mapping`, and `ScheduledWorkflow`.
- **openconnector** — operators must have configured a `Source`
  record for the ECB reference XML feed. shillinq references the
  source by slug; no code coupling.
- **T1 `bookkeeping-general-ledger`** — multi-currency reinterprets
  T1's `amount` field as `transactionAmount` (semantic shift only;
  the on-disk and on-the-wire JSON name remains `amount` for
  backwards compat) and renames `currency` to `transactionCurrency`.
  T2 / T3 readers must be updated to read `baseCurrencyAmount` for
  aggregation.

## Risks

### Risk 1: Multi-currency rounding edge cases

**Severity**: Medium
**Mitigation**: T1 already encodes signs in `side` enum (no negative
numbers); T4 multi-currency follows same. The ECB feed is inverted on
ingest (`our rate = 1 / ECB rate`) and rounded to at least 6 decimal
places to preserve precision on the round-trip. Rounding convention
documented in implementing cycle's tests with specific cases
(`€ 0.005` banker's rounding).

### Risk 2: T2 / T3 callers reading T1's `currency` break under rename

**Severity**: Low–Medium
**Mitigation**: The `## RENAMED Requirements` block is explicit;
implementing cycle MAY provide a deprecation alias for one release.
T1's on-disk JSON property name `amount` is preserved; only the
documented meaning shifts (to `transactionAmount`). T2 sub-ledger and
T3 statement readers are updated to read `baseCurrencyAmount` for
aggregation, `transactionAmount` (`amount`) for display.

### Risk 3: ECB source slug not yet configurable in openconnector

**Severity**: Low
**Mitigation**: Mature openconnector source registry; implementing
cycle verifies before validator runs. If missing, openconnector issue
filed; spec stays shape-neutral (names slug, not protocol).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR. The additive field
extensions on `GLLine` leave T1 single-currency callers correct
(default `fxRate = 1.0`, `baseCurrencyAmount = amount`).

## Open Questions

1. **FX revaluation cadence** — period-end is the default per
   REQ-MC-004; operator-selectable monthly revaluation deferred to
   future enhancement.
2. **Deprecation alias for `GLLine.currency` → `transactionCurrency`**
   — one release or hard rename? Settled in implementing cycle based
   on T2/T3 caller audit.
