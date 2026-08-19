# Change: mcp-action-tool-surface

## Why

The product-owner intent for the fleet: **every app provides MCP tooling for
ALL of its actions**, so any action can in principle be automated by an AI
agent; the user grants rights per agent, very granularly, through hermiq's
two-axis **scope × reach** grant model (default-deny for writes); and even
without automation a user can command the app from chat — chat away while your
apps execute your commands.

Shillinq's current surface, established by the archived `shillinq-mcp-adoption`
change (spec `shillinq-mcp-tool-surface`), is deliberately read-only: **24
derived tools** (12 curated schemas × `search`+`get`), zero writes, zero PHP.
That was the right call *at the time* — REQ-MCP-003 refused writes because two
platform capabilities were missing: **agent-principal attribution in the audit
trail** and a **human-in-the-loop approval gate**. Both now exist: hermiq
consumes `IMcpToolProvider` tools under a default-deny grant matrix
(`ActionAuthService` — "config returns an empty array (default-deny)"), with
per-tool `scope` (`read`/`create`/`update`/`delete`) and `reach`
(`ToolReachResolver::REACH_SELF/USER/INSTANCE/EXTERNAL`), human approval gates,
and an audit trail. The precondition REQ-MCP-003 itself named is met; the
moratorium can be lifted *under those gates* — not removed.

## Reality check — what `zzz-mcp-tool-surface.json` actually produces (verified)

The declarative fragment is **live, not aspirational**. The verified chain:

1. `SettingsService` merges every `lib/Settings/register.d/*.json` fragment
   (ADR-037, `deepMergeConfig()`, SettingsService.php ~line 1507), so each
   curated schema's `configuration["x-openregister-mcp"]` reaches the merged
   register that is imported into OpenRegister.
2. OpenRegister's `lib/Mcp/BuiltIn/SchemaDerivedToolProvider.php` (ADR-063
   chain 2/3, present at HEAD) reads every schema's **validated**
   `x-openregister-mcp` block and, per owning app, emits one tool per declared
   verb with id `{appId}.{schema}.{verb}` — i.e. `shillinq.ARInvoice.search`
   … — on both MCP surfaces (`McpToolsService` JSON-RPC +
   `ToolRegistry`/`McpProviderBridge` chat).
3. `McpAnnotationValidator` rejects a `search.filters` entry that is not a
   real schema property, failing the import loudly (REQ-MCP-002 holds).

So this change **builds on** the fragment (it keeps producing the 24 read
tools) rather than replacing it. What the fragment *cannot* produce is action
tools: `SchemaDerivedToolProvider` emits **coarse CRUD only**. "Execute the
dunning run", "export a SEPA payment run", "submit the VAT return to
Digipoort" traverse services (`DunningRunService`, `PaymentRunController::
export()`, `VATReturnController::submit()`) — they are not object CRUD and can
never be derived from a schema block. Those need a hand-written provider.

## What changes

1. **`OCA\Shillinq\Mcp\ShillinqToolProvider`** implementing
   `OCA\OpenRegister\Mcp\IMcpToolProvider`, registered under the container
   alias **`OCA\OpenRegister\Mcp\IMcpToolProvider::shillinq`** (OpenRegister's
   per-app discovery convention, openregister `AppInfo/Application.php`
   ~3827–3843), exposing **action tools** with ids `shillinq.{toolName}`.
   The fleet reference is decidesk's `lib/Mcp/` set: `DecideskToolProvider`
   (dispatcher owning the catalogue), `McpMeetingGate` (per-object authorise
   ladder: validate → load → not_found → authorise, helpers return `bool`,
   never wrapped in `catch(\Throwable)`), `McpArgumentValidator`, and
   `McpMeetingScopeResolver`. Shillinq mirrors that shape:
   `ShillinqToolProvider` + `McpAdministrationGate` + `McpArgumentValidator`
   + a scope resolver.
2. **Full action coverage**: one tool per real user action enumerated from
   `lib/Controller/` + `lib/Service/` (catalogue in the spec delta — invoicing,
   dunning, payment runs, bank import/reconciliation, VAT returns, expense
   claims, requisitions/PO approval, period close, hours). Read tools are
   strictly separated from write tools; every write is annotated with `scope`
   and `reach` for hermiq's grant matrix.
3. **Spec deltas** to `shillinq-mcp-tool-surface`: REQ-MCP-001 (no-PHP rule)
   and REQ-MCP-003 (write moratorium) are MODIFIED — narrowly; the BSN /
   credential exclusions of REQ-MCP-003 are **retained verbatim and remain
   absolute**. REQ-MCP-004 (action coverage) and REQ-MCP-005 (chat command
   surface + audit) are ADDED.
4. The derived read surface (`zzz-mcp-tool-surface.json`, 24 tools) is
   **unchanged**; `SchemaDerivedToolProvider`'s hand-written-beats-derived
   collision rule guarantees the new provider cannot be shadowed by a future
   derived duplicate.

## Out of scope / dependencies

- hermiq's grant model, approval UI, and audit pipeline are consumed, not
  built here (they exist: `hermiq/lib/Mcp/HermiqToolProvider.php`,
  `Service/Engine/ToolReachResolver.php`, `Service/ActionAuthService.php`).
- No new REST endpoints: every tool delegates to the same service the
  controller action calls, in the caller's ambient Nextcloud session — RBAC /
  IDOR posture identical to the REST path.
- Payroll, detachering, and bookings-widget schemas stay excluded outright
  (BSN / credential material) — REQ-MCP-003's exclusion list is untouched.
- Statutory-filing side effects (Digipoort, Peppol, SEPA) get `reach:
  external` so hermiq's human-approval gate fronts them; this change does not
  weaken those transports.
