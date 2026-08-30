---
kind: config
depends_on: []
---

# Change: shillinq-mcp-adoption

## Why

ADR-063 ("MCP as Platform Abstraction", 2026-07-12, hydra #102) rules that apps MUST NOT
hand-write MCP tool code. A leaf app declares a per-schema `x-openregister-mcp` block in its
register JSON and OpenRegister derives `{appId}.{schema}.{verb}` tools automatically.
Exposure is **default-OFF**: a schema without the dialect produces no tools at all.

Shillinq is the fleet's **tool-explosion stress case**. Its register declares **771 schema
entries / 482 unique slugs** across `shillinq_register.json` + 143 `register.d` fragments —
by far the largest surface in the fleet. Naively enabling the dialect across that register
would emit well over a thousand tools. Specter's research on tool-set size is unambiguous:
oversized tool sets degrade LLM tool-selection accuracy by ~9.5% and burn 30k+ tokens of
context before the agent has done any work. The default-OFF posture exists precisely to
prevent this, and **the curation — not the JSON — is the deliverable of this change**.

Shillinq ships **no MCP provider** (`grep -ril mcp lib/` at HEAD returns nothing), so there
is no hand-written CRUD to retire and no provider surgery. This is a pure `kind: config`
dialect declaration.

The second, harder half of the problem is that shillinq is a **bookkeeping and invoicing**
app. Every entity worth exposing is a financial record. An agent that can create an invoice,
post a payment, or write a ledger entry has **real-money consequences** and lands squarely in
Archiefwet retention and audit-trail territory — shillinq's own `GLTransaction` carries
`irreversible`, `postingLocked`, `periodLocked`, `retentionUntil`, and `integrityVerified`
properties, which is the schema telling us in plain language that the ledger is legally
append-only. This change therefore declares a **strictly read-only** surface and enables
**zero write verbs**.

## Motivation

- Hermiq (the fleet's sole agent consumer) can answer accountant/controller questions
  ("which invoices are overdue?", "how many hours did X book on project Y?", "is the trial
  balance balanced?") only if shillinq declares a tool surface. Today it declares none.
- Doing it **wrong** is worse than not doing it: an uncurated 482-schema surface would
  degrade every other app's tools in the same agent context, because the tool budget is
  shared fleet-wide.
- Financial write access via an LLM is a governance hazard that must be **explicitly
  refused in the spec**, not left implicit — an omitted verb and a considered refusal look
  identical in JSON, and only the spec records the reasoning.

## Affected Projects

- [ ] Project: shillinq — declare `x-openregister-mcp` (read verbs only) on 12 curated
      schemas via a new last-sorting `register.d` fragment; no PHP, no write verbs.

## Scope

### In Scope

- Declare `x-openregister-mcp` with `enabled: true` and **`search` + `get` only** on
  **12** curated schemas: `ARInvoice`, `SupplierInvoice`, `CustomerMaster`, `Payment`,
  `Account`, `GLTransaction`, `UrenRegistratie`, `ExpenseClaimEntry`, `Project`,
  `VatReturn`, `TrialBalance`, `BankStatement`.
- Per-verb agent-facing `description` prose, `scope: read`, `readOnlyHint: true`, and for
  `search` a `filters` list whose every entry is a **verified real property** of that schema.
- Record the grouped exclusion rationale for the remaining **470 unique slugs** (design.md).

### Out of Scope

- **Every write verb** (`create` / `update` / `delete`) on **every** schema — refused, see
  design.md Decision D3. No financial write is enabled by this change.
- Any `#[McpTool]` service attribute. No non-CRUD behaviour in shillinq is worth a curated
  tool today (design.md Decision D5).
- Provider surgery — shillinq has no MCP provider to migrate.
- Any change to schema properties, lifecycle, calculations, or seed data.

## Approach

Add one new register fragment, `lib/Settings/register.d/zzz-mcp-tool-surface.json`, carrying
nothing but `components.schemas.<Slug>.configuration["x-openregister-mcp"]` for the 12
curated schemas. `SettingsService::deepMergeConfig()` (ADR-037) unions fragment keys onto the
base schema, so the fragment adds the dialect without restating — or clobbering — a single
property. The `zzz-` prefix sorts last of all 143 fragments, so nothing merged afterwards can
overwrite the declaration.

## New Dependencies

None. The dialect is inert until OpenRegister's derived-tool engine is deployed.

## Impact

- **Config:** one new file, `lib/Settings/register.d/zzz-mcp-tool-surface.json`. No existing
  register file is edited.
- **Register version:** the fragment signature is folded into the register version
  (`SettingsService`, `+frag.<hash>`), so OpenRegister's version-gated `importFromApp`
  re-imports on deploy. This is the intended trigger.
- **Consumers:** Hermiq gains 24 shillinq tools (12 schemas x 2 read verbs) and zero write
  tools.
- **No PHP, no frontend, no migration.**

## Cross-Project Dependencies

- **openregister** (blocking for runtime, not for this change): the derived tools only
  materialise once OR's schema-derived tool provider is deployed. The dialect is a no-op
  before that, so this change is safe to land independently. `depends_on` is left empty
  because the predecessor is a cross-repo slug the supervisor cannot resolve.
- **hermiq**: consumes the derived tool ids; no whitelist migration needed (there is no
  pre-existing shillinq tool to re-point).

## Risks

### Risk 1: An agent-initiated financial write corrupts the ledger or the audit trail

- **Severity**: High
- **Mitigation**: **Refused outright.** No `create`/`update`/`delete` verb is declared on any
  schema in this change. A verb that is not declared is not derived, so the tool does not
  exist to be called. See design.md D3 for the per-verb refusal argument.

### Risk 2: Tool explosion degrades the whole fleet's agent accuracy

- **Severity**: High
- **Mitigation**: 12 of 482 slugs (2.5%) are exposed, read-only — 24 tools. The grouped
  exclusion rationale in design.md is the durable artifact that keeps the surface from
  creeping: any future addition must argue against a named category.

### Risk 3: A declared `search` filter is not a real schema property

- **Severity**: Medium
- **Mitigation**: OpenRegister's `McpAnnotationValidator` rejects an unknown filter at import
  — a single bad filter blocks the whole register import. Every filter in this change was
  cross-checked against the **union** of the schema's properties across all fragments that
  define it (a slug redefined in N files has the union of their properties). The table in
  design.md is the record.

### Risk 4: An exposed schema leaks special-category PII or payment credentials into LLM context

- **Severity**: Medium
- **Mitigation**: All BSN-bearing schemas (`Employee`, `Werknemer`, `IBAangifte`,
  `IB47Record`) and all secret-bearing schemas (`WidgetAccessKey`, `ConfirmationToken`) are
  **excluded outright**. Their masking lives in the app's controllers, which a derived
  OR-level tool bypasses — so exclusion is the only safe posture (design.md D4). The residual
  IBAN exposure on `CustomerMaster` / `BankStatement` is accepted and documented.

### Risk 5: Three competing invoice supertypes make the agent pick the wrong one

- **Severity**: Medium
- **Mitigation**: `Invoice`, `ARInvoice`, and `BillableInvoice` all exist. Only `ARInvoice`
  (the AR sub-ledger record of account, the one carrying `amountDue` / `paidAmount` /
  `dunning`) is exposed; the other two are left OFF as origination documents. Flagged as an
  open question — see below.

## Rollback Strategy

Delete `lib/Settings/register.d/zzz-mcp-tool-surface.json` and re-import. The dialect is
purely additive: no property, lifecycle, or stored object is touched, so removal restores the
prior (no-tools) state exactly. Nothing depends on the derived tools existing.

## Open Questions

1. **Invoice fragmentation.** `Invoice` (booking-order origination), `ARInvoice` (AR
   sub-ledger), and `BillableInvoice` (time-and-expense origination) overlap. This change
   bets on `ARInvoice` as the record of account. Live verification that customer invoice rows
   actually land in `ARInvoice` (and not only in `Invoice`) is REQUIRED before the derived
   tools are trusted — a wrong bet returns an empty list, not an error.
2. **Duplicate VAT-return slugs.** `VatReturn` and `VATReturn` are two distinct schemas in the
   same register, differing only in case. This change exposes `VatReturn` (the one in the base
   register, carrying the Digipoort submission lifecycle). The collision itself looks like a
   modelling defect and should be raised separately.
3. **Agent attribution in the audit trail.** Reads are attributed to the session user, not the
   agent principal. Harmless for reads; it would be disqualifying for writes, and is a
   precondition for ever revisiting D3.
</content>
