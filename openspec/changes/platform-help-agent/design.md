# Design: platform-help-agent

Read at shillinq development `79f438f33`, hermiq development and
nextcloud-vue development on 2026-09-27.

## Context

- **The companion is mounted.** `src/App.vue:15` sets `:aiCompanion="true"` on `CnAppRoot`. nextcloud-vue's `CnAppRoot.vue:412` renders `<CnAiCompanion v-if="aiCompanion" :chatAppId="chatAppId" />`; `CnAiCompanion.vue` probes `/index.php/apps/{chatAppId}/api/chat/health` and hides when it fails, and receives the page context (`appId`, `pageKind`, ...) that `CnAppRoot` provides as `cnAiContext`. Hermiq answers (hydra ADR-034, amendment 2026-07-05).
- **Agent choice.** `CnAiChatPanel.vue` (`relevantAgentOptions`, around line 596) offers the agents whose tools start with the current `appId`.
- **Agents and templates in hermiq.** `Agent` (hermiq `lib/Settings/hermiq_register.json`) has `applicationSlug`, `tools`, `views`, `enableRag`, `ragSearchMode`, `ragNumSources`, `ragIncludeFiles`, `ragIncludeObjects`, `searchFiles`. `AgentTemplateService` keeps a tenant-scoped catalogue, exports and imports portable packages (quarantined and scanned when externally sourced) and instantiates them. `ContextRetrievalHandler::retrieveContext()` (hermiq `lib/Service/Engine/ContextRetrievalHandler.php:101`) retrieves sources by the agent's RAG settings.
- **Knowledge today.** shillinq's user guide is 142 markdown files under `docs/` (Docusaurus), published on shillinq.conduction.nl; 211 page configs in `src/manifest.json` carry a `documentationUrl`. None of it is in a store the assistant searches.
- **Read tools.** `lib/Settings/register.d/zzz-mcp-tool-surface.json` declares search and get tools; `mcp-action-tool-surface` adds a tool per action.

## Goals / Non-Goals

**Goals**
- On any shillinq page, a user picks the shillinq helper and gets a how-to answer grounded in the user guide, with a link.

**Non-Goals**
- The widget, the backend, acting on data.

## Decisions

### D1. The user guide becomes help articles

New schema `HelpArticle` in the shillinq register: `slug`, `title`,
`body` (markdown), `documentationUrl`, `pageIds` (manifest page ids the
article explains, taken from the pages whose `documentationUrl` points at
it), `language`, `sourcePath`, `sourceHash`. A repair step reads `docs/`
from the installed app, upserts one article per page keyed by `sourcePath`,
and skips unchanged files by `sourceHash`. OpenRegister vectorises objects,
so no file copying is needed.

Alternative considered: RAG over the markdown as Nextcloud files. Rejected:
the files would have to be copied into a user's storage and kept in step;
objects in the app's own register already are.

### D2. The helper is an agent template, offered not forced

`lib/Settings/hermiq-agent-templates/shillinq-helper.json` in hermiq's
portable package format: name "Shillinq helper", `applicationSlug`
shillinq, `enableRag` true, `ragIncludeObjects` true with `views`
restricted to `HelpArticle`, `tools` the shillinq read tools, and
instructions to answer how-to questions from the articles, cite the
`documentationUrl`, and prefer articles whose `pageIds` include the current
page. A listener offers the package to hermiq's catalogue when hermiq is
installed; an administrator instantiates it with "Use this template".

### D3. Absence is visible

Without hermiq the companion stays hidden (its own probe), and pages keep
their documentation links. The help article pages remain readable as an
index under Documentation.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Help articles | Declarative: a schema and seed data | Content as objects. |
| Seeding from `docs/` | Imperative, a repair step | Reads files shipped with the app. |
| The agent | Declarative: a template file | Hermiq's own package format. |

## Seed Data

From `docs/`: the article "Een verkoopfactuur maken" with `pageIds`
`ARInvoices`, `ARInvoiceDetail`; "Btw-aangifte voorbereiden" with
`VATReturns`; "Periode afsluiten" with `PeriodClose`, `PeriodCloseDetail`.
A user on the period close page asking "hoe sluit ik september af?" gets the
steps of "Periode afsluiten" and its link.

## Risks / Trade-offs

- [hermiq's import only accepts packages uploaded by a person] → then the listener raises an admin notification with the package to import, and task 1.1 records the finding.

## Migration Plan

The repair step seeds articles on upgrade.

## Open Questions

None.
