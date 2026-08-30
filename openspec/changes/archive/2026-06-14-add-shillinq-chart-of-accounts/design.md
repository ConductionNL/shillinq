# Design — Chart of Accounts

## Context

Shillinq's mission is a Conduction-native business administration suite
covering bookkeeping, invoicing, procurement, contracts, and downstream
reporting. The 5-tier rollout (see `proposal.md`) starts with Tier 1
**foundation**: a balanced double-entry general ledger built on top of
a hierarchical chart of accounts.

This change is the **first slice** of Tier 1 — the chart of accounts
itself. Sibling changes `add-shillinq-general-ledger` and
`add-shillinq-journal-entries` consume the `Account` register declared
here.

Currently `lib/Settings/shillinq_register.json` ships only a placeholder
`example` schema; `openspec/architecture/adr-000-data-model.md`
enumerates `Account` and `GeneralLedgerAccount` entries but neither has
landed as a register yet.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire chart-of-accounts surface as **declarative
  metadata** — schema + `x-openregister-lifecycle` rules + manifest
  entries — per ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC, hierarchical relations — per ADR-022. No
  reimplementation in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the model as a faithful
  hierarchical chart of accounts, RGS-conformant, with no surprises.
- Keep the shape narrow enough that the sibling GL and JE specs can
  attach without reshaping the `Account` schema.

## Non-Goals

- No GL postings, no journal entries — sibling changes own those.
- No multi-currency translation — Tier 5's job. (The `Account`
  schema carries `currency` so Tier 5 doesn't need a destructive
  migration.)
- No frontend Vue components beyond the generic
  `CnIndexPage`/`CnDetailPage` from `@conduction/nextcloud-vue`
  bound through `src/manifest.json`.
- No PHP code authored in this change.

## Decisions

### D1 — Declarative-first, per ADR-031

Every chart-of-accounts behaviour expressible as schema metadata MUST
be declared in `lib/Settings/shillinq_register.json`, not authored as
a PHP service. Concretely:

| Behaviour | Declarative form |
|---|---|
| Account active/blocked/archived state machine | `x-openregister-lifecycle` on `Account` |
| Account hierarchy navigation | `x-openregister-relations` (self-relation `parentAccountNumber → Account.accountNumber`) |
| Audit trail of every state change | OR's built-in audit-trail-immutable (no app config required) |

**Alternative considered**: Author a PHP `ChartOfAccountsService`
mirroring Exact / Twinfield style. Rejected per ADR-031 — that is
exactly the anti-pattern decidesk's MotionService / VotingService /
QuorumService are now mid-migration away from.

### D2 — `Account` carries `currency`, `administrationId`, `lifecycleState` for future tiers

The `Account` schema must carry forward-compatible fields so that
sibling and downstream tiers do not force a destructive migration:

- `currency` — Tier 5 multi-currency translation needs per-account
  base currency; declaring it now (default `EUR`) avoids the
  rewrite later.
- `administrationId` — Tier 2 multi-administration scoping needs every
  account to be scoped; declaring as a non-required string in Tier 1
  keeps single-tenant installs working unchanged.
- `lifecycleState` — exposes the OR lifecycle state as a queryable
  field for reporting indexes.

**Alternative considered**: Add fields later as needs surface.
Rejected — OR's schema-versioning is additive but downstream specs
already reference these fields (GL lines need account `currency` for
balance checks; JE approval policies key off `administrationId`).
Forward declaration is cheap.

### D3 — RGS templates as seed data, not hard-coded enums

The chart-of-accounts shape is fixed by RGS conformance, but the
exact account numbers and names vary by administration type. T1
ships three RGS 3.5 templates:

- `rgs-3.5-mkb.json` — the standard SMB chart.
- `rgs-3.5-zzp.json` — the simplified ZZP/freelancer chart.
- `rgs-bbv.json` — the BBV (Besluit Begroting en Verantwoording)
  chart for Dutch government / municipal bookkeeping.

Templates are JSON arrays of `Account` records. The repair step
seeds whichever template the administration selects on first run
(or none — operators may build their own). Per-administration
override is allowed: any seeded account can be edited, archived,
or augmented with sub-accounts.

**Alternative considered**: Bake one template into the schema as
enum constraints. Rejected — RGS evolves (4.x ships before
implementation cycle finishes is plausible), accounts vary per
administration, and government / SMB / ZZP cannot share enums.
Seed files keep schema stable and templates evolveable.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Account hierarchy | `adr-000-data-model.md` `Account` entry, `GeneralLedgerAccount` entry | This change's `Account` formalises the existing entry. The two ADR-000 entries are reconciled in the implementation cycle (the GL-prefixed entry becomes the canonical one; `Account` keeps its "business workspace" sense reserved for T2 multi-tenancy). |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event with actor, before/after, timestamp, hash chain. |
| RBAC | OR authorization | Per-schema role definitions in the register file. Grants `bookkeeper` create/read, `auditor` read-only. |
| Lifecycle engine | `x-openregister-lifecycle` (per ADR-031) | `Account` declares an `active → blocked → archived` lifecycle. |
| Seed data import | `ConfigurationService::importFromApp()` (per shillinq config.yaml `design` rule 3) | Repair-step pattern already in use for the placeholder schema; extended to load the chosen RGS template into the `Account` register. |
| Cross-schema relations | `x-openregister-relations` | Self-relation on `Account.parentAccountNumber`. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted on `feature/adopt-app-manifest`) | Adds 1 menu entry + 1 index page + 1 detail page, all consuming `type: index` / `type: detail` library renderers. |

**Net new code in implementation cycle**: 1 schema declaration + 1
manifest entry pair + 3 seed JSON files. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Account state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| Hierarchical account navigation | Declarative (`x-openregister-relations` self-relation) | Standard relation shape |
| Audit trail | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |

No service class authored in this envelope.

## Seed Data

This change ships three RGS template seeds, all under
`lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `rgs-3.5-mkb.json` | Standard SMB chart of accounts. Five top-level cluster headers (assets/liabilities/equity/revenue/expenses) and the RGS 3.5 canonical SMB account tree. | ~150 |
| `rgs-3.5-zzp.json` | Simplified ZZP/freelancer subset of RGS 3.5. | ~40 |
| `rgs-bbv.json` | BBV chart for Dutch municipal / government bookkeeping. | ~120 |

Format: a JSON array of `Account` records matching the schema declared
in `bookkeeping-chart-of-accounts/spec.md`. Loaded via
`ConfigurationService::importFromApp()` in the repair step. The
administration's first-run flow selects which template to seed (or
none — operators may build their own). After seeding, accounts are
fully editable through normal OR object operations; per-administration
override is the default behaviour.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "RGS 3.5", "variant":
  "mkb", "imported": "<iso-timestamp>" } }`) so a future migration to
  RGS 4.x can identify which records were template-sourced versus
  operator-authored.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Account hierarchy depth unbounded | Schema permits arbitrary depth; UI (T4+ reporting) renders the first 4 levels by default with collapse/expand. No T1 enforcement of max depth. |
| RGS 4.x ships during implementation | Seed files versioned in filename (`rgs-3.5-*`); coexistence is trivial; a `rgs-4.0-*` file can be added without touching the schema. |
| ADR-000 data-model entries `GeneralLedgerAccount` overlaps with `Account` | Reconciliation is a one-paragraph annotation in ADR-000 added during the implementation cycle. Not in this spec. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the `Account`
   schema (additive — no existing schema changes).
2. `src/manifest.json` is patched with one new menu entry + one new
   index/detail page pair (additive).
3. A new repair step (or extension of the existing one) imports the
   selected RGS template into the `Account` register on first install.
4. ADR-000 gains a one-paragraph annotation reconciling
   `GeneralLedgerAccount` with `Account`.

Down-direction: registers are non-destructive — disabling the seed
import + reverting the manifest leaves stranded but queryable
records. No destructive rollback needed at the spec-acceptance gate.

## Open Questions

1. **RGS template variant for housing corp / healthcare / education
   sectors** — out of scope here; placed on the rollout roadmap.
2. **Closing-account cardinality** — `REQ-CoA-009` proposes
   exactly one closing account per administration. Confirm with
   the bookkeeper persona during spec review.
