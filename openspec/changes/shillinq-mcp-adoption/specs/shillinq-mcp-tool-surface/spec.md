# Spec: shillinq-mcp-tool-surface (delta)

## ADDED Requirements

### Requirement: REQ-MCP-001 — Shillinq's MCP tool surface MUST be declared on the schema via the `x-openregister-mcp` dialect and MUST NOT be hand-written in PHP

Shillinq MUST NOT ship an MCP tool provider, an `IMcpToolProvider` implementation, or any
hand-coded tool descriptor (ADR-063). The entire shillinq tool surface MUST be **derived** by
OpenRegister from a per-schema `configuration["x-openregister-mcp"]` block, producing tool ids
of the form `shillinq.{schema}.{verb}`. The declaration MUST be carried in a dedicated,
last-sorting register fragment (`lib/Settings/register.d/zzz-mcp-tool-surface.json`) that adds
only the dialect key, so that ADR-037's key-union merge folds it onto the base schema without
restating or overwriting any property. Exposure MUST remain default-OFF: a schema without the
dialect MUST produce no tools.

#### Scenario: the dialect is declared in a fragment, not in the schema-owning files
- **GIVEN** the 12 curated schemas are defined across 8+ existing register files
- **WHEN** `zzz-mcp-tool-surface.json` is merged by `SettingsService::deepMergeConfig()`
- **THEN** each curated schema gains `configuration["x-openregister-mcp"]` by key union
- **AND** no property, lifecycle, calculation, or required-list of any schema is altered

#### Scenario: shillinq ships no MCP PHP
- **WHEN** the change is complete
- **THEN** `lib/` contains no MCP provider, no tool descriptor, and no `#[McpTool]` attribute
- **AND** the only artifact added is the register fragment

### Requirement: REQ-MCP-002 — The exposed surface MUST be limited to the 12 curated schemas, and every declared `search` filter MUST be a real property of its schema

Exactly **12** of shillinq's 482 unique schema slugs MUST carry the dialect: `ARInvoice`,
`SupplierInvoice`, `CustomerMaster`, `Payment`, `Account`, `GLTransaction`, `UrenRegistratie`,
`ExpenseClaimEntry`, `Project`, `VatReturn`, `TrialBalance`, `BankStatement`. Each MUST declare
`enabled: true` and a `tools` map containing **only** `search` and `get`. Each verb MUST carry
agent-facing `description` prose that states what the tool returns and when to reach for it,
plus `scope: "read"` and `readOnlyHint: true`. Each `search` MUST declare a `filters` list in
which **every entry is a real property of that schema** (the union of its properties across
every fragment that defines it) — OpenRegister's `McpAnnotationValidator` rejects an unknown
filter and a single bad filter fails the whole register import. No other schema may carry the
dialect without an explicit spec change that argues against the categorised exclusion
rationale in `design.md`.

#### Scenario: the derived surface is 24 read tools and nothing else
- **GIVEN** OpenRegister's derived-tool engine is deployed
- **WHEN** the MCP tool list is enumerated
- **THEN** exactly 24 shillinq tools exist (12 schemas x `search` + `get`)
- **AND** every one of them is `readOnlyHint: true` and `scope: "read"`

#### Scenario: an unknown search filter fails the import rather than shipping
- **GIVEN** a `search.filters` entry that is not a property of its schema
- **WHEN** the register is imported
- **THEN** `McpAnnotationValidator` rejects the schema and the import fails loudly
- **AND** no partially-valid tool surface is published

#### Scenario: a non-curated schema produces no tools
- **GIVEN** a schema in the OFF list (e.g. `GLLine`, `Employee`, `XBRLMapping`, `MatchingRule`)
- **WHEN** the MCP tool list is enumerated
- **THEN** no `shillinq.{that-schema}.{verb}` tool exists

### Requirement: REQ-MCP-003 — Shillinq MUST NOT expose any MCP write verb, and MUST NOT expose any schema bearing credentials or special-category PII

No `create`, `update`, or `delete` verb MUST be declared on any shillinq schema. Financial
writes are refused, not deferred: an invoice, payment, ledger posting, or statutory filing
authored by an agent carries real-money and Archiefwet consequences, the ledger is legally
append-only (`GLTransaction` declares `irreversible`, `postingLocked`, `periodLocked`,
`retentionUntil`), a correction is itself a legally-significant posting so there is no clean
undo, and MCP writes are attributed to the session user rather than the agent principal —
which would make the audit trail unable to distinguish agent action from human action.
Re-enabling any write verb MUST require agent-principal attribution in the audit trail **and**
a human-in-the-loop approval gate, both of which are platform capabilities shillinq does not
own. Independently, any schema carrying credential material (`WidgetAccessKey.apiKeyHash`,
`ConfirmationToken.tokenString`) or special-category PII (`Employee.bsn`, `Werknemer.bsn`,
`IBAangifte.bsn`, `IB47Record.ontvangerBSN`) MUST be excluded **outright** — including from
`get` — because such masking as exists lives in shillinq's controllers and is bypassed by a
tool that reads the stored object directly through OpenRegister.

#### Scenario: no shillinq write tool is derivable
- **WHEN** the MCP tool list is enumerated
- **THEN** no `shillinq.*.create`, `shillinq.*.update`, or `shillinq.*.delete` tool exists
- **AND** no shillinq tool declares `destructiveHint: true` or a write `scope`

#### Scenario: an agent cannot issue an invoice, post to the ledger, or file a return
- **GIVEN** an agent instructed to create an invoice, record a payment, or submit a VAT return
- **WHEN** it searches the tool registry for a shillinq write tool
- **THEN** none exists, and the action cannot be taken through MCP at all

#### Scenario: BSN- and credential-bearing schemas are absent from the surface entirely
- **GIVEN** the payroll, detachering, and bookings-widget schemas
- **WHEN** the MCP tool list is enumerated
- **THEN** no `search` **or** `get` tool exists for any of them
- **AND** no BSN, API-key hash, or confirmation-token value can be reached through a shillinq tool
</content>
