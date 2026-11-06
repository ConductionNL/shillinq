---
kind: code
depends_on: [mcp-action-tool-surface]
---

# Proposal: platform-help-agent

## Summary

A user stuck on a screen asks the in-app assistant how to do something and
gets an answer that fits shillinq, at any hour. The assistant is already on
every shillinq page: the app shell mounts the fleet's AI companion, which
hermiq answers. What it lacks is shillinq's own knowledge, so a how-to answer
rests on the language model alone. This change ships a shillinq helper agent
for hermiq and gives it shillinq's user guide as its knowledge.

## Motivation

One platform row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`plt-help-assistant`**, "Get how-to answers from an in-app assistant at
any hour." The matrix rated it no, built state none: "no in-app assistant;
pages link out to documentationUrl entries on shillinq.conduction.nl". A
re-read of the code on 2026-09-27 found the assistant: `src/App.vue:15`
passes `:aiCompanion="true"` to `CnAppRoot`, which mounts `CnAiCompanion`
(nextcloud-vue `CnAppRoot.vue:412`) whenever hermiq answers its health
probe. This pass corrects the row to partial; the missing half is shillinq
knowledge. Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "De Support Agent in Exact AI Assistant staat 24/7 voor je klaar om al jouw how-to vragen te beantwoorden".
- moneybird: https://www.moneybird.nl/helpcentrum/, "Gebruik onze AI-bot die meteen met je meedenkt", and the in-app help widget (https://helpcenter.moneybird.nl/nl/articles/361728-de-helpwidget).
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/technical-suppo-3041266, "de virtuele collega, onze chatbot in het product".
- odoo: https://www.odoo.com/odoo-19-release-notes, "Ask AI for help using top bar button", agents answering from "documents, Knowledge articles, website links, or PDFs".

The sibling row `plt-nl-query` ("Ask questions about your finances in plain
language") is covered by the open change `mcp-action-tool-surface`, whose
REQ-MCP-005 routes a question such as "what invoices are overdue?" to a
shillinq read tool through the same companion.

## Affected Projects

- [ ] Project: `shillinq`: a helper agent template, the user guide held as searchable help articles, and page-aware prompts.

## Scope

### In Scope

- A "Shillinq helper" agent template shillinq offers to hermiq's agent catalogue when both apps are installed.
- `HelpArticle` objects seeded from shillinq's user guide (`docs/`), so OpenRegister's vector search can ground answers in them.
- Answers citing the article and its documentation link, and taking the current page into account.

### Out of Scope

- The companion widget, the chat backend and model choice: nextcloud-vue and hermiq own them (hydra ADR-034).
- Tools that act on data: `mcp-action-tool-surface`.

## Approach

Ship data, not a chat: an agent template in hermiq's package format and the
user guide as objects. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/`: an agent template file and a `HelpArticle` schema fragment.
- `lib/Repair/`: seeding help articles from `docs/` at install and upgrade.
- `lib/Listener/`: offering the template when hermiq is present.

## Cross-Project Dependencies

- hermiq: `AgentTemplateService` imports a portable, secret-free agent package into the tenant's catalogue and instantiates it ("Use this template"); agents carry `enableRag`, `ragIncludeObjects`, `ragIncludeFiles`, `views`, `tools` and `applicationSlug` (hermiq `lib/Settings/hermiq_register.json`, `Agent`). No hermiq change is needed if the import accepts a package from another app; task 1.1 confirms that.
- nextcloud-vue: the companion lists agents whose tools belong to the current app (`CnAiChatPanel.vue` `relevantAgentOptions`), so the helper appears on shillinq pages.

## Risks

### Risk 1: Answers drift from the product
**Severity:** Medium. **Mitigation:** the articles are reseeded from `docs/` on every upgrade, and every answer cites the article it used.

## Rollback Strategy

Stop offering the template and remove the seeded articles. The companion
keeps working without shillinq knowledge.

## Open Questions

None.
