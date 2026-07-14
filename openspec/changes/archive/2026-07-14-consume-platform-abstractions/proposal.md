---
kind: config
depends_on: []
---

# Proposal: consume-platform-abstractions

## Summary

Four items from `scratchpad/routing-audit-2026-07-14.md` claimed shillinq needed to
**build** something the platform (OpenRegister / openconnector) already ships. This
change consumes what already exists — mostly manifest JSON and one three-line JS
bootstrap fix, no bespoke service classes — and explicitly **refuses** the one item
whose "zero PHP" premise did not survive verification against HEAD.

1. **NC Flow + Talk leaves** — `FlowProvider`/`TalkProvider` already ship in
   OpenRegister (`openregister/lib/Service/Integration/Providers/{Flow,Talk}Provider.php`,
   both spec `status: done`). shillinq's `src/main.js` never called the three
   bootstrap functions (`installIntegrationRegistry`, `registerBuiltinIntegrations`,
   `registerLeafIntegrations`) that populate the shared JS integration registry
   from shillinq's OWN bundle — decidesk and pipelinq already do this. Without it,
   every shillinq object detail page's sidebar registry mode has nothing to render.
2. **Missing-document worklist** — two `type:"index"` manifest pages filtering
   `sourceDocumentUri: null` (OpenRegister's declarative `IS NULL` filter operator,
   the same idiom the existing `UnmatchedItems` page already uses for
   `resolutionStatus: null`). No bespoke worklist service.
3. **Inventory quarantine status** — **refused**. The audit's premise ("declarative
   `x-openregister-lifecycle` edit, zero PHP") does not hold at HEAD: nothing
   mirrors `Product.status` onto `InventoryStock.status` today, the quarantine
   concept lives on the sibling `InventoryLot.lotStatus` schema instead, and
   neither status is ever read by `SalesDispatchStockIssueService` at dispatch
   time — so blocking a sale genuinely requires a PHP check, not a schema edit.
   Filed as shillinq#443 instead of forcing a fake "zero PHP" fix through this
   change.
4. **Public REST API exposure** — **refused as a boundary crossing**. The gateway
   itself (`openconnector/lib/Service/EndpointService.php:364` →
   `handleSchemaRequest()`) is real and needs no new PHP, but the `Endpoint`
   config can only be declaratively authored inside openconnector's own
   `configurations/` folder (a different repo), `Consumer` has no declarative
   import path at all (an imperative object-creation call), and the `apiKey`
   auth type does not actually enforce against the Consumer record today
   (verified against HEAD). Filed as openconnector#159. Documented the real
   path/auth shape in `docs/api/third-party-rest-access.md` instead (which
   differs from OR's own self-generated OAS, per the audit).

## Motivation

ADR-022 requires apps to consume a leaf/abstraction the platform already ships
rather than reimplement it. All three of the audit's "already provided" claims for
items 1/2/4 turned out to be real gaps in *activation*, not missing platform
capability — small, mechanical, low-risk fixes. Item 3's claim did not hold up:
verifying it against HEAD is exactly what this change is supposed to do before
writing code, and forcing a "zero PHP" label onto a change that actually needs a
new PHP status check would be the same kind of abstraction-boundary violation this
change exists to prevent, just inverted.

## Affected Projects

- [x] Project: `shillinq` — `src/main.js` bootstrap fix (item 1), two new
  manifest index pages (item 2), a `docs/` page (item 4). Item 3 refused
  (filed shillinq#443); item 4's config refused as a boundary crossing (filed
  openconnector#159).
- [ ] Project: `openregister` — read-only; `FlowProvider`/`TalkProvider` already
  ship, unmodified.
- [ ] Project: `openconnector` — read-only; `EndpointService::handleSchemaRequest()`
  already ships, unmodified. Two dead/missing capabilities (`apiKey` auth not
  wired to `Consumer`, no `ConsumerHandler` for declarative import) filed as
  openconnector#159, not fixed here.
