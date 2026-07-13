# Tasks: shillinq-mcp-adoption (kind: config)

Scope note: **config only**. No PHP, no provider, no `#[McpTool]`, no seed data, no write
verb. The single deliverable is one new register fragment. The curation table (design.md D2)
and the grouped exclusion rationale are normative — do not add a schema that is not in the
table, and do not add a verb that is not `search`/`get`.

## 1. Declare the read-only dialect

- [ ] 1.1 Create `lib/Settings/register.d/zzz-mcp-tool-surface.json` containing **only**
      `components.schemas.<Slug>.configuration["x-openregister-mcp"]` for the 12 schemas in
      design.md D2 — no `properties`, no `title`, nothing else that could union-merge over the
      base schema. The `zzz-` prefix MUST sort last of all fragments.
- [ ] 1.2 For each of the 12: `enabled: true`; `tools` containing **only** `search` and `get`;
      each verb with `scope: "read"`, `readOnlyHint: true`, and the agent-facing `description`
      prose from design.md D2 (say what it returns and when to reach for it — this is what the
      LLM reads).
- [ ] 1.3 Add `search.filters` exactly as listed in the design.md D2 table. Every entry MUST be
      a real property of that schema (union across all fragments defining it) — an unknown
      filter fails the whole register import.
- [ ] 1.4 Confirm **no** `create`/`update`/`delete` key, no `destructiveHint`, and no write
      `scope` appears anywhere in the fragment (REQ-MCP-003).

## 2. Verify

- [ ] 2.1 `python3 -m json.tool lib/Settings/register.d/zzz-mcp-tool-surface.json` — valid JSON.
- [ ] 2.2 Assert every declared filter is a real property: for each of the 12 slugs, diff the
      fragment's `filters` against the union of `properties` keys across every register file
      that defines that slug. Zero unknown filters.
- [ ] 2.3 Re-import the register (`occ` repair / `importFromApp`) and confirm it succeeds — the
      fragment signature bumps the register version, so the version-gated import re-runs and
      `McpAnnotationValidator` gets a chance to reject a bad dialect.
- [ ] 2.4 Confirm the base schemas are unchanged after the merge: the 12 schemas keep every
      property they had at HEAD, and gain only `configuration["x-openregister-mcp"]`.
- [ ] 2.5 Grep the whole register for `"create"`, `"update"`, `"delete"`, `destructiveHint`
      inside any `x-openregister-mcp` block — expect **zero** hits fleet-wide for shillinq.
- [ ] 2.6 Live-verify the `ARInvoice` bet (proposal Open Question 1): confirm customer invoice
      rows actually materialise in `ARInvoice` and not only in `Invoice`. If they do not, fix
      the fragment before the derived tools are trusted.

## 3. Close out

- [ ] 3.1 `openspec validate shillinq-mcp-adoption --type change --strict`.
- [ ] 3.2 CHANGELOG entry under Unreleased/Added: read-only MCP tool surface (ADR-063) — 12
      curated schemas, 24 read tools, zero write tools; reference ADR-063 in the commit/PR body.

## Acceptance criteria

- Exactly 12 schemas carry `x-openregister-mcp`; each declares only `search` + `get`, `scope: read`, `readOnlyHint: true`.
- Every declared `search` filter is a verified real property of its schema; the register imports cleanly.
- Zero write verbs and zero credential-/BSN-bearing schemas anywhere in the surface.
- No PHP added; shillinq still ships no MCP provider.

## Quality reminders (plain-text, not checkboxes)

- Re-validate JSON after every edit — one malformed fragment blocks the entire register import.
- Never restate a property in the fragment; declare only the dialect key (ADR-037 key-union merge).
- Any future schema promotion must argue against a named category in design.md's OFF table.
- Tests (ADR-009): N/A — no PHP, no API surface, no UI. The gate is the register import + the filter cross-check in 2.2.
- Docs (ADR-010): N/A — no user-facing feature; the agent-facing prose lives in the dialect itself.
</content>
