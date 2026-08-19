# Design: mcp-action-tool-surface

## Two mechanisms, one surface

```
declarative (unchanged)                     hand-written (new)
───────────────────────                     ──────────────────
register.d/zzz-mcp-tool-surface.json        OCA\Shillinq\Mcp\ShillinqToolProvider
  x-openregister-mcp on 12 schemas            DI alias IMcpToolProvider::shillinq
    │ SettingsService::deepMergeConfig()        │ catalogue + dispatch only
    ▼ register import                           ▼
OpenRegister SchemaDerivedToolProvider      McpAdministrationGate (authorise)
  → shillinq.{Schema}.{search|get} ×24      McpArgumentValidator (validate)
  (McpAnnotationValidator gates filters)    scope resolver (which admin/objects)
    └────────────┬──────────────────────────────┘
                 ▼
   McpToolsService JSON-RPC + ToolRegistry/McpProviderBridge (chat)
                 ▼
   hermiq: default-deny grant matrix (ActionAuthService),
   scope × reach (ToolReachResolver), human approval, audit
```

Collision safety: `SchemaDerivedToolProvider` self-suppresses a derived tool
whose id a hand-written provider already claims (hand-written > derived), so
the action provider can later absorb a CRUD verb without a duplicate.

## Key decisions

1. **Derived CRUD stays declarative; actions are hand-written.** ADR-063's
   "no hand-written PHP" rule was written when the surface was CRUD-only.
   `SchemaDerivedToolProvider` emits coarse CRUD per schema; a dunning run, a
   SEPA export, a Digipoort submission traverse services and have no schema
   home. REQ-MCP-001 is modified to state the boundary, not repealed: any tool
   expressible as `x-openregister-mcp` CRUD MUST stay in the fragment.
2. **Tool = controller action, same session, same guards.** Every action tool
   delegates to the exact service its controller action calls
   (e.g. `shillinq.dunning.execute_run` → the path behind
   `DunningController::executeRun()`), in the caller's ambient NC session. No
   impersonation, no system account. The decidesk gate pattern is copied:
   argument validation → object load → not_found → authorise, helpers return
   `bool` and are never swallowed by `catch(\Throwable)` (hydra
   unsafe-auth-resolver gate).
3. **scope × reach is declared per tool, resolved by hermiq.** Scope is the
   verb class (`read`/`create`/`update`/`delete`); reach is the blast radius
   (`self`/`user`/`instance`/`external`, `ToolReachResolver` constants).
   Shillinq only *declares*; enforcement (default-deny, approval gates) is
   hermiq's. Rule of thumb applied in the catalogue: touching only the
   caller's own data → `user`; approving/closing something that binds others
   (PO approval, period close, write-off) → `instance`; anything that leaves
   the instance (Digipoort, Peppol, SEPA file for the bank, dunning email) →
   `external`.
4. **Audit parity with the derived provider.** The derived provider writes one
   immutable hash-chained audit record per invocation
   (`AuditTrailMapper::createToolInvocationEntry`, REQ-DERIVED-006). The
   hand-written provider MUST match that: every invocation — read, write, or
   failed — is auditable, which is what makes agent-principal attribution
   real rather than claimed.
5. **`VatReturn.destroy` is exposed as the only `delete`-scope tool** —
   VATReturnController already restricts destroy to non-submitted returns;
   the ledger itself (`GLTransaction`) still has NO delete tool of any kind
   (append-only, reverse-with-correction).

## Why not x-openregister-mcp write verbs instead of a provider?

Derived `create`/`update` would write raw objects through `ObjectService`,
bypassing shillinq's domain services (sequential invoice numbering, dunning
staging, three-way-match tolerances, period-close checks). A raw
`shillinq.ARInvoice.create` is exactly the "agent-authored invoice" REQ-MCP-003
feared. Action tools go *through* the domain services, so every existing
validation and lifecycle guard runs. Derived write verbs stay forbidden.
