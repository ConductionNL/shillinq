# Design: semantic-invoice-consume

## Context

PO decision 2026-07-05: cross-app flows use schema.org-style shared semantic
primitives (ADR-048 + the semantic-handoff ADR being authored with the hydra
change `semantic-object-handoff`). The chain *pipelinq quote → contract → AR
invoice* lands in shillinq. OpenRegister mechanics live at HEAD already:

- `openregister/lib/Service/SemanticTypeResolver.php` — resolves a kind URI to
  the installed schema whose `configuration.implements` (default
  `[configuration.jsonld.type]`) matches; null-safe; disabled app = no
  provider; deterministic pick + WARN on ambiguity.
- Precedent: shillinq `Payee` declares
  `configuration.implements: ["https://openregister.app/ns#Vendor", "https://schema.org/Organization"]`
  (`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`), consumed
  by pipelinq's `product.vendor` semantic reference (ADR-048).
- `x-openregister-handoff` does **not** exist anywhere at HEAD (verified by
  grep across openregister, hydra, shillinq) — it is the new dialect owned by
  `semantic-object-handoff`.

### Verified schema inventory (shillinq HEAD) — the landing-zone candidates

| Deployed schema (post-merge slug) | Declared in | Notes |
|---|---|---|
| `ARInvoice` | base `add-shillinq-bookkeeping-compliance.json` v0.1.0 + ~10 additive overlays (`add-shillinq-invoice-lines` EN16931 lines v0.5, `checks-*` VAT/compliance, `abstract-arinvoice-types` v0.12, `ar-invoice-payment-links`, `bookkeeping-sepa-direct-debit`, …) | AR sub-ledger invoice; lifecycle `lifecycleState` draft → issued (materialises GLTransaction + credit check) → paid; dunning + payment matching + notifications attach here |
| `Invoice` | `bookkeeping-quote-order-invoice.json` v0.1.0 (21 props) **union-merged with** `bookings-deposit-to-invoice.json` `Invoice` v0.1.0 (15 props) | newer QOI flow document; lifecycle `status` draft → sent (materialises GL + AR open item) → paid; duplicate-slug debt |
| `Contract` | `contract-lifecycle-management.json` v0.1.0 (22 props, direction inbound/outbound, counterpartyReference = NC contact) **union-merged with** `bookkeeping-ifrs15-revenue.json` `Contract` v0.1.0 (14 props) | CLM lifecycle draft → active → expiring/expired/renewed/terminated |
| `SalesOrder` | `bookkeeping-quote-order-invoice.json` v0.1.0 (18 props) **union-merged with** monolith `shillinq_register.json` v0.1.0 (10 props) | quote → order → invoice flow |
| `Quote` | `bookkeeping-quote-order-invoice.json` v0.1.0 | lifecycle draft → sent → accepted / declined / expired |
| `Order` ×3 collision + `Subsidie` ×3 | see `abstract-order-primitive` BLOCKER | **do not touch** |

Fragment merge mechanics (verified `lib/Service/SettingsService.php:1290+`):
`register.d/*.json` glob-sorted, deep-merged — associative arrays key-union
(recursing), list arrays concatenate, scalars overwrite. So an overlay fragment
can add `configuration.implements` onto a schema declared elsewhere (bridge-01
precedent: `Appointment.pipelinqContactId`), and two `implements` lists on the
same slug would concatenate.

## Goals / Non-Goals

**Goals**: make shillinq the resolvable provider of the four finance kinds;
declare kind-keyed acceptance of the quote→contract→invoice handoff chain with
provenance, draft-arrival, and finance notifications; keep the whole consume
side in one re-pointable fragment.

**Non-Goals**: dialect/engine implementation (hydra + OR), pipelinq produce
side, schema consolidation, recurring billing, customer-identity bridging, UI.

## Decisions

### D1 — `ARInvoice` (not the QOI `Invoice`) holds the `ns#Invoice` marker

Two AR customer-invoice schemas exist (duplicate debt, see inventory). Exactly
one may carry the marker (deterministic resolution, no WARN noise). `ARInvoice`
wins on evidence:

- It is the *operational* AR object: dunning (`bookkeeping-credit-control-dunning`),
  payment links, SEPA, EN16931/UBL invoice lines, and the live notification
  rules all attach to `ARInvoice`.
- `abstract-arinvoice-types.json` (v0.12) shows it is the schema actively being
  abstracted as THE AR invoice type.
- The QOI `Invoice` slug is itself union-merged with the bookings `Invoice`
  (two declarations, one slug) — a mushier target.

When the foreseen Invoice/ARInvoice merge lands (abstract-order-primitive
names the Invoice merge as its next template), the marker moves with it
(REQ-SIC-006). Alternative considered: marking the QOI `Invoice` because the
handoff arrives from a quote flow — rejected; the chain's *financial* landing
zone is the sub-ledger invoice, and H2's source is the Contract, not the QOI
SalesOrder.

### D2 — One ADR-037 overlay fragment, additive only

All consume-side declarations live in
`lib/Settings/register.d/semantic-invoice-consume.json` under
`components.schemas.<Schema>`: `configuration.implements`, the two provenance
properties, `x-openregister-handoff`, `x-openregister-notifications`, and the
seed objects. Never edit the owning fragments (concurrent-build safety +
single-file re-point for consolidation). Note: `Quote` / `SalesOrder` /
`Contract` have **no** `configuration` key at HEAD, `ARInvoice`'s is `null`,
and the QOI `Invoice` HAS a configuration object (objectNameField,
linkedTypes: mail) — the overlay merges cleanly in all three shapes
(key-union; scalar-null overwritten by the object), but apply MUST verify the
merged output (memory gotcha: union merges can corrupt `required` — the
overlay declares no `required` key anywhere).

### D3 — Handoff acceptance keyed to kind, provisional dialect shape

Declared on the TARGET schemas (house pattern: schema-level `x-openregister-*`
like lifecycle/notifications). Provisional shape — **align to the landed
`semantic-object-handoff` dialect at apply time, verify against HEAD**:

```jsonc
// on Contract (target of H1)
"x-openregister-handoff": {
  "accept": {
    "quote-accepted-to-contract": {
      "sourceKind": "https://openregister.app/ns#Quote",
      "onSourceState": "accepted",
      "targetKind": "https://openregister.app/ns#Contract",
      "mapping": {                    // target field <- source expression
        "title": "{{title}}",
        "contractType": "sales",
        "direction": "outbound",
        "counterpartyReference": "{{counterpartyReference}}", // via bookings-pipelinq-customer-bridge identity, see D6
        "startDate": "{{acceptedAt}}",
        "totalContractValue": "{{totalAmount}}",
        "currency": "{{currency}}",
        "administrationId": "@config.defaultAdministrationId"
      },
      "provenanceProperty": "sourceQuoteReference",
      "idempotencyKey": "correlationId"
    }
  }
}
```

H2 analogously on `ARInvoice`: `sourceKind: ns#Contract`,
`onSourceState: active`, condition `direction = outbound` AND provenance
present (proposal Open Question 3), `provenanceProperty:
sourceContractReference`, amounts from `totalContractValue` (initial invoice
only — no schedule). Source field names in H1's mapping are placeholders until
the pipelinq quote schema exists (it does NOT at pipelinq HEAD) — implementer
MUST re-verify.

### D4 — Arrival state is the schema's `initialState` (`draft`), never mapped

`ARInvoice.issued` materialises a balanced GLTransaction and runs the
credit-limit check; QOI-style `sent` would dispatch. The mapping deliberately
contains no lifecycle field; the lifecycle declaration fixes `draft`
(REQ-SIC-004). Operators advance the state through the existing guarded
transitions.

### D5 — H2 creates ONE initial invoice; recurring billing stays put

`RecurringInvoiceProfile` (`recurring-invoicing.json` +
`RecurringInvoiceGenerator`) already owns schedule-driven invoicing. H2 is a
single draft invoice per activated handed-off contract, idempotent on the
handoff correlation id. Subscription contracts get a RecurringInvoiceProfile
by the operator — out of scope here (long-term unification: one write path).

### D6 — Reference, don't duplicate, the existing pipelinq bridges

- Customer identity: `bookings-pipelinq-customer-bridge-*` chain (fragment 01
  declares the pipelinq-contact link pattern; NC addressbook contact is the
  canonical counterparty per `Contract.counterpartyReference` / REQ-CLM-001).
  The H1 counterparty mapping consumes that identity; it does not invent a new
  customer link.
- Product/vendor: `shillinq-product-vendor-to-pipelinq` (done) — untouched;
  the `Payee`→`ns#Vendor` marker it relies on is the precedent this change
  extends.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Kind markers (`implements`) | declarative (`configuration.implements`) | ADR-048 mandates it; consumed by OR resolver |
| Handoff acceptance H1/H2 | declarative (`x-openregister-handoff`) | the whole point of the new dialect; execution is OR-side (ADR-041 events under the hood) — shillinq ships **zero PHP** |
| Provenance links | declarative schema properties (`referenceSemanticType`) | ADR-048 §2 |
| Arrival state | declarative (existing `x-openregister-lifecycle.initialState`) | no guard code needed — the mapping simply cannot set state |
| Handoff-received alerts | declarative (`x-openregister-notifications`, gate-18 dialect) | matches `shillinq-notifications.json` house shape |

No imperative exception is claimed; no `lib/Service/*` class is added.

## Seed Data (ADR-001)

Shipped in the fragment under `components.objects` (house precedent:
AP-core seeds; NOTE the memory gotcha — register.d fragment objects go LIVE, so
seeds are realistic, safe, and clearly example-named). General organisation
data, consultancy flavour:

- 1 `Contract` "CT-2026-HANDOFF-001 — Adviesdiensten Gemeente Voorbeeld":
  contractType `sales`, direction `outbound`, status `draft`,
  totalContractValue 48000 EUR, startDate 2026-07-01,
  `sourceQuoteReference: "00000000-0000-0000-0000-000000000000"` (nil UUID —
  placeholder provenance; the pipelinq source does not exist in a fresh env).
- 1 `ARInvoice` "INV-2026-HANDOFF-001": lifecycleState `draft`, netAmount
  4000.00, vatAmount 840.00, grossAmount 4840.00 EUR, invoiceDate 2026-07-05,
  dueDate 2026-08-04,
  `sourceContractReference` = the seeded contract's UUID.

These make the provenance rendering, the notification condition and the
draft-arrival assertion verifiable in a clean environment without pipelinq.

## Risks / Trade-offs

- [Dialect drift — the fragment is written against a provisional shape] →
  `depends_on: semantic-object-handoff`; apply-time verification against HEAD;
  markers/provenance/notifications are dialect-independent and ship first.
- [Union-merge corruption when overlaying `configuration` onto schemas that
  have `configuration: null`] → overlay carries no `required`; apply verifies
  the merged register output (occ import + schema inspection).
- [Ambiguous ns#Quote when pipelinq's quote lands (two providers)] → accepted:
  source-kind matching doesn't resolve-by-kind; resolver WARN is the designed
  observability for consumer-side resolution (ADR-048).
- [Handed-off drafts pile up unnoticed] → REQ-SIC-005 notifications; the
  finance group triages drafts through the existing index views.

## Apply-time dialect alignment (2026-07-06 — landed OR engine verified)

The OR engine landed on openregister `origin/development`
(`lib/Service/Handoff/`: HandoffKindContracts, HandoffAnnotationValidator,
HandoffContractBindingValidator, HandoffMappingEvaluator, HandoffService,
`lib/Listener/HandoffLifecycleListener`). D3's provisional shape was wrong in
these verified ways; the fragment and the spec delta were aligned to HEAD:

1. **Acceptance ≠ target-side rule.** The consume side is a
   `configuration.handoffContract` binding block on the PROVIDER schema
   (kind URI → {contractField: ownProperty}, every mandatory field bound);
   `x-openregister-handoff` is an EMITTER array (id, targetSemanticType,
   trigger `manual`|`lifecycle:<state>`, mapping over kind-contract fields
   with exactly one of `from|const|template|semanticRef|provenance` per field,
   `whenUnavailable: hide|queue`, optional `onSuccess.set`). Kind contracts
   are fixed in `HandoffKindContracts` (ns#Quote/ns#Contract/ns#Invoice/
   ns#Case) — `ns#SalesOrder` has NO contract, so SalesOrder carries the
   marker only.
2. **No condition grammar.** H2's "outbound + provenance-carrying" gate is not
   expressible; `lifecycle:active` would auto-draft AR invoices for inbound
   contracts. H2 ships `trigger: manual` (narrowest safe scope, OQ3). No
   engine idempotency either — the manual trigger is the v1 dedupe boundary.
3. **`source` is the provenance contract field; created-filters are
   scalar-only.** The notification created-filter string-casts objects to
   `''`, so binding `source` to an envelope-object property would make the
   REQ-SIC-005 rules dead config. The shillinq emitters map `source` as a
   scalar URN template (`shillinq:quote:{{quoteNumber}}`,
   `shillinq:contract:{{contractNumber}}`) into string properties;
   uuid-level provenance comes free from the engine's
   `handoff:<id>:originated-from` relations + audit rows.
   `referenceSemanticType` was dropped from the provenance properties (they
   hold URNs, not resolvable uuid references — the Related widget renders the
   engine relation instead).
4. **Runtime chain blocked by union-merged `required` (pre-existing).** The
   merged Contract requires contractNumber/contractType/customerId/
   fixedConsideration/signedAt…, ARInvoice requires invoiceNumber/periodId/
   administrationId/invoiceDate/netAmount… — none are kind-contract fields,
   so handoff CREATES fail target validation until `abstract-order-primitive`
   dedups required and/or an ADR-041 intake listener (numbering etc., per the
   hydra order-chain contract) lands. H1's lifecycle-triggered attempt is
   logged-and-swallowed by `HandoffLifecycleListener` (the quote transition
   itself is never blocked).
5. **Misplaced notification block was doubly dead.** Besides living under
   `components.ARInvoice` (never read — `ImportHandler` iterates
   `components.schemas` only), it filtered a non-existent `state` field with a
   non-canonical `{all: […]}`/`notIn`/`before` grammar. Fixed by relocation +
   modernisation (overdue → scheduled filter `lifecycleState: "overdue"`,
   paid → updated condition `lifecycleState equals paid`).

## Migration Plan

None — purely additive config. Import happens via the existing
`importFromApp` version gate (fragment signature bump). Rollback: delete the
fragment (see proposal).

## Open Questions

Carried in the proposal (administration targeting; ADR number of the
semantic-handoff ADR; H2 provenance-only vs all outbound contracts).
