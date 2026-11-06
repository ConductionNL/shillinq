---
kind: code
depends_on: []
---

# Proposal: contacts-kvk-lookup

## Summary

A user adding a customer or a supplier types a company name or KvK number,
picks the company from the KvK Handelsregister, and the legal name, trade name
and address fill in. Shillinq declares the lookup on two properties and turns
the KvK connection on. The lookup itself is integriq's and the key is
openregister's; both already ship.

## Motivation

Matrix row **`plt-kvk`**, "Look a company up in the KvK register and fill in
its details." (`openspec/parity/capabilities.json`), state specified, rated no.
The row is linked to integriq's archived `2026-09-29-registry-backed-field-source`,
which delivered the provider half:

- integriq `lib/PropertySource/Provider/KvkPropertySource.php`: provider id
  `kvk`, `suggest(query)` returning identifier and label, `resolve(kvkNumber)`
  returning the registry record, behind
  `GET /apps/integriq/api/property-sources/kvk/suggest` and `/resolve`.
- openregister `lib/Service/Schemas/PropertySourceDeclaration.php`: the
  property key `x-openregister-property-source`
  `{provider, mode: live|default, config}`, validated at schema save, and
  `SchemasController` publishing it as `propertyMetadata`.

The re-rating of 2026-10-07 named what is missing in shillinq: no schema
property declares the key (`git grep 'property-source'` over `lib`, `src`,
`appinfo` finds nothing) and `lib/Settings/connections.json` lists `kvk` as
`available: false`, "Nothing reaches the KvK".

Three competitors rate the row yes. Moneybird: "Klik op het zoekresultaat om de
KVK-gegevens alvast in te vullen". Exact Online: "Relaties aanmaken via KVK".
SnelStart: "Relatiegegevens ophalen bij Handelsregister KVK".

## Affected projects

- [ ] Project: `shillinq`: the key on `CustomerMaster.kvkNumber` and
  `Payee.kvkNumber` with a fill map, the `kvk` connection switched on, tests
  and docs.

## Scope

### In scope

- Declaring `x-openregister-property-source` with provider `kvk` and mode
  `default` on `CustomerMaster.kvkNumber` and `Payee.kvkNumber`.
- A fill map in the declaration's `config`: which KvK fields fill which sibling
  properties of the record.
- Switching the `kvk` entry in `connections.json` on, keeping its
  `sourceTemplate`.
- The behaviour when the connection is not configured or the KvK does not
  answer: the field stays a plain text field and says why.

### Out of scope

- Rendering the type-ahead and applying the fill map in a form. That belongs to
  the shared form in nextcloud-vue, which reads `propertyMetadata`; it does not
  do so yet (`git grep property-source` over nextcloud-vue `src/` finds
  nothing). Listed as a cross-repo need, not built here.
- Keeping a stored customer in sync with later KvK changes. Mode `default`
  copies once; design.md says why.
- The VAT number. The KvK does not publish it.

## Risks

- **The form cannot show the lookup until nextcloud-vue does.** Until then the
  declaration is accepted and inert: the field stays plain text, exactly as
  today. Nothing breaks, and tasks 3.1 and 3.2 are the live checks that prove
  the end-to-end flow once the renderer lands.
- **A fill overwrites what a user typed.** The spec forbids it: the fill writes
  only empty fields and asks before replacing a filled one.

## Rollback

Revert the PR. The key leaves the two properties and `kvk` returns to
unavailable. Stored records keep their values.
