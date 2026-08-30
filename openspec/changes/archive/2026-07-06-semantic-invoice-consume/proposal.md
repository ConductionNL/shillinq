---
kind: config
depends_on:
  - semantic-object-handoff   # hydra cross-app change (../hydra/openspec/changes/, authored in parallel) — defines the x-openregister-handoff dialect + OR execution engine
---

# Change: semantic-invoice-consume

## Summary

Shillinq becomes the **consume side** of the cross-app semantic handoff chain
*pipelinq quote → contract → AR invoice* (PO decision 2026-07-05). Shillinq's
existing schemas are marked as the installed providers of the canonical kinds
`https://openregister.app/ns#Contract`, `ns#Invoice`, `ns#SalesOrder` and
`ns#Quote` via the ADR-048 `configuration.implements` marker (precedent already
live: `Payee` implements `ns#Vendor` in
`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`, consumed by
pipelinq's `product.vendor` reference). A single new ADR-037 overlay fragment
declares kind-keyed `x-openregister-handoff` acceptance rules (dialect owned by
the parallel hydra change `semantic-object-handoff`), provenance link
properties back to the source objects, draft-state lifecycle placement, and
ADR-031 `x-openregister-notifications` rules for handed-off objects. Everything
is declarative JSON — no PHP.

## Motivation

Real workflows cross app boundaries: a sales quote is accepted in pipelinq, but
the resulting contract and customer (AR) invoice belong in shillinq, the app
that owns the finance domain. Per ADR-048 (cross-app semantic references) and
the parallel `semantic-object-handoff` change, OpenRegister's
`SemanticTypeResolver` (`openregister/lib/Service/SemanticTypeResolver.php`,
live at HEAD) resolves which *installed* schema implements a canonical kind —
null-safe when no provider is installed. Today no shillinq schema advertises
the Contract/Invoice/Quote/SalesOrder kinds (`grep implements
lib/Settings/register.d/*.json` matches only `Payee` → `ns#Vendor`), so the
handoff chain has no landing zone. Without provenance links and a safe initial
lifecycle state, handed-off objects would also be indistinguishable from
operator-created ones and could trigger accounting side effects (`ARInvoice`
issue materialises a balanced GLTransaction) without operator review.

## Affected Projects

- [x] Project: shillinq — new overlay fragment `lib/Settings/register.d/semantic-invoice-consume.json` (kind markers, handoff acceptance, provenance properties, notifications); 1-key relocation fix in `lib/Settings/register.d/shillinq-notifications.json` (pre-existing misplacement, see Risks)
- [ ] Project: openregister — NO changes here; the `x-openregister-handoff` dialect + execution engine land via the hydra change `semantic-object-handoff` (referenced, not duplicated)
- [ ] Project: pipelinq — NO changes here; the produce side (quote schema + accepted-state trigger) is authored in parallel (pipelinq has **no quote schema at HEAD** — verified against `pipelinq/lib/Settings/pipelinq_register.json`)

## Scope

### In Scope

- `configuration.implements` markers (ADR-048) on exactly ONE deployed schema
  per kind:
  - `ARInvoice` → `["https://openregister.app/ns#Invoice", "https://schema.org/Invoice"]`
  - `Contract` → `["https://openregister.app/ns#Contract"]`
  - `SalesOrder` → `["https://openregister.app/ns#SalesOrder", "https://schema.org/Order"]`
  - `Quote` → `["https://openregister.app/ns#Quote"]`
- Kind-keyed `x-openregister-handoff` **acceptance** declarations:
  - H1: `ns#Quote` reaching its accepted terminal state → create a draft
    `ns#Contract` object with provenance.
  - H2: a handed-off `ns#Contract` transitioning to `active` with
    `direction = outbound` → create ONE draft `ns#Invoice` object with
    provenance (recurring billing stays with `RecurringInvoiceProfile`).
- Provenance link properties: `Contract.sourceQuoteReference`
  (`referenceSemanticType: ns#Quote`) and `ARInvoice.sourceContractReference`
  (`referenceSemanticType: ns#Contract`), plus retention of the handoff
  provenance envelope (sourceApp / sourceObjectId / correlationId per ADR-041).
- Lifecycle placement: handed-off objects start at the schema's existing
  `initialState` (`draft` for both Contract and ARInvoice) — never auto-issued.
- ADR-031 `x-openregister-notifications` rules notifying the finance group when
  a handed-off Contract / ARInvoice is created.
- Seed data for verification (1 handed-off Contract + 1 handed-off ARInvoice).

### Out of Scope

- The `x-openregister-handoff` dialect definition and the OR-side resolution /
  creation / event engine — owned by hydra `semantic-object-handoff` + the
  companion OpenRegister change (this proposal only *declares against* it).
- The pipelinq produce side (quote schema, accepted-state trigger, outbound
  handoff declaration).
- Customer identity mapping pipelinq-contact → NC addressbook contact — already
  covered by the `bookings-pipelinq-customer-bridge-*` chain
  (`openspec/specs/bookings-*`, fragment
  `bookings-pipelinq-customer-bridge-01-config-contact-link.json`); referenced,
  not duplicated.
- Product/vendor master resolution — already shipped
  (`openspec/specs/shillinq-product-vendor-to-pipelinq/spec.md`, status done).
- Any schema consolidation: NO new `Order`-slug schema, NO merge of the
  `Invoice`/`ARInvoice` duplicates — that is `abstract-order-primitive`
  (currently BLOCKED on exactly that dedup) and its follow-ups.
- Recurring/subscription invoicing from contracts — remains
  `RecurringInvoiceProfile` (`lib/Settings/register.d/recurring-invoicing.json`).
- UI changes (handed-off objects render through the existing index/detail
  surfaces; the provenance link renders via the standard reference widget).

## Approach

One new ADR-037 overlay fragment,
`lib/Settings/register.d/semantic-invoice-consume.json`, merged by
`SettingsService::deepMergeConfig()` (verified: associative key-union, list
concatenation — `lib/Service/SettingsService.php:1290+`). It overlays onto the
existing schema declarations (never edits their owning fragments):
`configuration.implements`, the two provenance properties, the
`x-openregister-handoff` acceptance block, and `x-openregister-notifications`
rules. See design.md for the placement evidence (why `ARInvoice`, not the QOI
`Invoice`, holds the `ns#Invoice` marker) and the consolidation-survival
design.

## New Dependencies

None (no packages). Cross-change dependency: hydra `semantic-object-handoff`
(+ its OpenRegister implementation) must land before the handoff acceptance
block becomes operative; the `implements` markers, provenance properties and
notifications are operative immediately (SemanticTypeResolver +
`configuration.implements` are live at OR HEAD).

## Impact

- Affected specs: NEW capability `semantic-invoice-consume`.
- Affected code: `lib/Settings/register.d/semantic-invoice-consume.json` (new),
  `lib/Settings/register.d/shillinq-notifications.json` (1-key fix).
- Affected systems: OR schema import (`importFromApp`, version bumped via the
  fragment signature), OR SemanticTypeResolver answers for the four kinds,
  OR notification engine.

## Cross-Project Dependencies

- **hydra `semantic-object-handoff`** (in authoring, parallel): normative owner
  of the `x-openregister-handoff` dialect (field mapping, provenance envelope,
  ADR-041 events) and the new semantic-handoff ADR. Shillinq's acceptance block
  MUST be aligned to the landed dialect at apply time — verify against HEAD.
- **pipelinq** produce side: source field names for the H1 mapping cannot be
  verified yet (no quote schema at pipelinq HEAD); the mapping ships with the
  target side fixed and the source side aligned when the pipelinq quote lands.
- **abstract-order-primitive** (shillinq, BLOCKED): this change is designed to
  survive its consolidation — see Risk 1.

## Risks

### Risk 1: Deepening the Order/Invoice schema-dedup debt
- **Severity**: High
- **Mitigation**: This change targets the semantic KINDS (stable URIs), never
  concrete slugs. It adds NO new `Order`-slug schema and no fields to the three
  colliding `Order` declarations. Exactly one deployed schema per kind carries
  the `implements` marker, and all handoff mappings are keyed to kind — when
  `abstract-order-primitive` (and the Invoice/ARInvoice merge it templates)
  consolidates schemas, the marker + acceptance block move WITH the
  consolidation by re-pointing this change's single overlay fragment; kind-keyed
  consumers (pipelinq, OR resolver) are unaffected. REQ-SIC-006 makes this
  normative.

### Risk 2: Declaring against a dialect that is still being authored
- **Severity**: Medium
- **Mitigation**: `depends_on: semantic-object-handoff`; the acceptance block's
  exact key shape is marked provisional in design.md and tasks.md instructs the
  implementer to verify the landed dialect against HEAD before writing the
  fragment. Markers/provenance/notifications do not depend on the dialect and
  ship regardless.

### Risk 3: Handed-off invoices causing accounting side effects
- **Severity**: Medium
- **Mitigation**: REQ-SIC-004 — handed-off objects start in `draft`
  (`ARInvoice` `x-openregister-lifecycle.initialState = draft`, verified in
  `add-shillinq-bookkeeping-compliance.json`); the GL-materialising `issued`
  transition remains operator-gated. The handoff never writes a non-initial
  state.

### Risk 4: Notification rules landing dead (pre-existing misplacement)
- **Severity**: Low
- **Mitigation**: `shillinq-notifications.json` at HEAD nests its ARInvoice
  rules under `components.ARInvoice` (sibling of `components.schemas`) instead
  of `components.schemas.ARInvoice` — the OR import reads
  `components.schemas`, so those rules are likely dead config. This change adds
  its rules under `components.schemas.<Schema>` (the shape every base fragment
  uses) and fixes the pre-existing misplacement in the same batch
  (feedback: always fix pre-existing problems), after confirming import
  behaviour at HEAD.

## Rollback Strategy

Delete `lib/Settings/register.d/semantic-invoice-consume.json` (and revert the
1-key notifications fix); the fragment-signature version bump triggers a
re-import without the overlay. Objects already created by handoffs remain valid
draft objects (additive properties become unknown-but-stored fields); no data
migration in either direction.

## Open Questions

1. Which shillinq administration (`administrationId` is required on
   `ARInvoice`) do handed-off objects land in? Provisional: a
   `defaultAdministrationId` config key on the acceptance declaration, resolved
   from shillinq app config at handoff time — to be ratified by the
   `semantic-object-handoff` dialect.
2. ~~ADR numbering~~ RESOLVED 2026-07-05 (owner decision): ADR-049 =
   `adr-049-declarative-widget-vocabulary`, ADR-050 = reserved for the Spectr
   re-platform ADR, and the semantic-handoff ADR is **ADR-051**
   (`hydra/openspec/architecture/adr-051-semantic-object-handoff.md`). This
   proposal continues to reference the ADR by topic + the change name
   `semantic-object-handoff` as well.
3. Should H2 (contract → first draft invoice) fire for ALL outbound contract
   activations or only pipelinq-originated ones? Provisional: only contracts
   carrying handoff provenance (narrowest safe scope); widening to all outbound
   contracts is a one-line condition change later.
