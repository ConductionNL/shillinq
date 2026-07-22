# Tasks: shillinq-mcp-adoption (kind: config)

Scope note: **config only**. No PHP, no provider, no `#[McpTool]`, no seed data, no write
verb. The single deliverable is one new register fragment. The curation table (design.md D2)
and the grouped exclusion rationale are normative — do not add a schema that is not in the
table, and do not add a verb that is not `search`/`get`.

## 1. Declare the read-only dialect

- [x] 1.1 Create `lib/Settings/register.d/zzz-mcp-tool-surface.json` containing **only**
      `components.schemas.<Slug>.configuration["x-openregister-mcp"]` for the 12 schemas in
      design.md D2 — no `properties`, no `title`, nothing else that could union-merge over the
      base schema. The `zzz-` prefix MUST sort last of all fragments.
- [x] 1.2 For each of the 12: `enabled: true`; `tools` containing **only** `search` and `get`;
      each verb with `scope: "read"`, `readOnlyHint: true`, and the agent-facing `description`
      prose from design.md D2 (say what it returns and when to reach for it — this is what the
      LLM reads).
- [x] 1.3 Add `search.filters` exactly as listed in the design.md D2 table. Every entry MUST be
      a real property of that schema (union across all fragments defining it) — an unknown
      filter fails the whole register import.
- [x] 1.4 Confirm **no** `create`/`update`/`delete` key, no `destructiveHint`, and no write
      `scope` appears anywhere in the fragment (REQ-MCP-003).

## 2. Verify

- [x] 2.1 `python3 -m json.tool lib/Settings/register.d/zzz-mcp-tool-surface.json` — valid JSON.
- [x] 2.2 Assert every declared filter is a real property: for each of the 12 slugs, diff the
      fragment's `filters` against the union of `properties` keys across every register file
      that defines that slug. Zero unknown filters.
- [x] 2.3 Re-import the register (`occ` repair / `importFromApp`) and confirm it succeeds — the
      fragment signature bumps the register version, so the version-gated import re-runs and
      `McpAnnotationValidator` gets a chance to reject a bad dialect. No live Nextcloud instance
      was available in this worktree, so this was verified by loading OpenRegister's real
      `McpAnnotationValidator` class (unmodified, from the sibling `openregister` checkout) and
      running it against each of the 12 schemas' merged (union) property set — all 12 pass with
      zero errors. See scratchpad `validate_mcp.php` harness.
- [x] 2.4 Confirm the base schemas are unchanged after the merge: the 12 schemas keep every
      property they had at HEAD, and gain only `configuration["x-openregister-mcp"]`. Verified
      structurally: every schema entry in the fragment has exactly one key (`configuration`),
      and every `configuration` object has exactly one key (`x-openregister-mcp`) — no property
      can be restated or clobbered by construction.
- [x] 2.5 Grep the whole register for `"create"`, `"update"`, `"delete"`, `destructiveHint`
      inside any `x-openregister-mcp` block — expect **zero** hits fleet-wide for shillinq.
      Confirmed zero hits; the new fragment is the only file in the register carrying
      `x-openregister-mcp`.
- [x] 2.6 Live-verify the `ARInvoice` bet (proposal Open Question 1): confirm customer invoice
      rows actually materialise in `ARInvoice` and not only in `Invoice`. If they do not, fix
      the fragment before the derived tools are trusted. No live DB was available, so this was
      verified at the code level: `RecurringInvoiceGenerator::saveObject(schema: 'ARInvoice', ...)`
      (line 189) is the customer-invoice generation write path, and
      `ARInvoiceEInvoiceController` handles outbound UBL/Peppol e-invoicing specifically for
      `ARInvoice` — confirming it is a live, populated record, not an empty bet. `Invoice` is
      separately read by `DunningRunService::klantPaidInvoiceWithin()` for an unrelated "good
      customer" signal, which does not contradict the ARInvoice bet.

## 3. Close out

- [x] 3.1 `openspec validate shillinq-mcp-adoption --type change --strict`.
- [x] 3.2 CHANGELOG entry under Unreleased/Added: read-only MCP tool surface (ADR-063) — 12
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
