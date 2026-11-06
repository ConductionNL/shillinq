---
kind: code
depends_on: []
---

# Proposal: platform-custom-record-types

## Summary

An organisation keeps things shillinq does not know about: vehicle
registrations, grant applications, a list of rented parking spaces. It wants
to define such a record type once, with its own fields, and work with it
inside the finance app. Open Register can already hold an administrator's
own schema, but shillinq's pages are fixed in its manifest, so such a
record type never shows up. This change lets an administrator pick record
types they defined in shillinq's register and gives each one a list and a
detail page inside shillinq.

## Motivation

One platform row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the build-all pass of 2026-09-28: two competitors rate it yes.

**`plt-custom-entities`**, "Define your own record types with their own
fields and use them across the app." Rated no, built state none. Origin:
changelog
(https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage?language=en_GB).
Matrix note: "An admin could define a schema in OpenRegister, but no
shillinq page would show it (same ground as plt-custom-fields). Open
Register's half shipped in ConductionNL/openregister
openspec/changes/archive/2026-06-14-openregister-runtime-schema-api
(runtime record types); shillinq pages that show them are shillinq's, so
the owner moved to ConductionNL/shillinq."

Competitors rated yes: exact-online and odoo (evidence in the matrix row).

## Affected Projects

- [ ] Project: `shillinq`: a setting listing the chosen record types, a service telling shipped schemas from custom ones, generic list and detail pages.

## Scope

### In Scope

- An admin settings section listing the schemas in the shillinq register that the app did not ship, with a switch to show each in shillinq.
- A "Eigen gegevens" page with one card per shown record type.
- A generic list page and detail page driven by the schema in the route.

### Out of Scope

- Defining the schema; that happens in Open Register's schema editor (its runtime schema API).
- Adding fields to shillinq's own schemas (`plt-custom-fields`, owned by openregister).
- Booking custom records into the ledger or linking them into shillinq's own forms.

## Approach

Route sentinels already let one manifest page take its schema from the URL
(`CnPageRenderer` resolves `@route.<param>` in page config), so two
generic pages serve every custom type. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/`: `CustomRecordTypeService`.
- `lib/Controller/`: one read endpoint and one admin write endpoint.
- `src/manifest.json`: three pages and a menu entry; an admin settings section.

## Cross-Project Dependencies

None: the route sentinel is in nextcloud-vue development today.

## Risks

### Risk 1: A route opens a shipped schema or another register
**Severity:** Medium. **Mitigation:** the generic pages only open schemas on the shown list; the server check refuses any other slug, so a typed URL cannot reach an unlisted schema.

## Rollback Strategy

Remove the menu entry and the pages; the records stay in Open Register.

## Open Questions

None.
