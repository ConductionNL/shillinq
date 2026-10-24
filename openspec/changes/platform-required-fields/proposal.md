---
kind: code
depends_on: []
---

# Proposal: platform-required-fields

## Summary

An organisation decides for itself which fields must be filled in: a
cost centre on every purchase invoice, a reference on every customer. In
shillinq the required fields are fixed in the register the app ships, and no
page lets an administrator add one. This change lets an administrator mark
extra fields as required per administration, enforces that on every save,
and shows it in the forms.

## Motivation

One platform row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`plt-required-fields`**, "Decide yourself which input fields must be
filled in." Rated no, built state none. Matrix evidence: "which fields are
required is fixed in the register JSON the app ships
(lib/Settings/shillinq_register.json:26, :297, :396 and the required lists
under lib/Settings/register.d/) and the forms take their fields from
src/manifest.json config.fields (:1255, :1410); no shillinq page or setting
lets an administrator mark a field as required." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. odoo rates yes
(https://www.odoo.com/documentation/19.0/applications/studio/fields.html,
"field properties include a 'Required' checkbox"); exact-online (cost
centres an employee "can or must select") and twinfield
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/dagboeken-3041094,
"leg hier vast of het factuurnummer bij een boeking verplicht, toegestaan of
niet toegestaan is") are partial.

## Affected Projects

- [ ] Project: `shillinq`: a required-field setting per administration, a pre-save check, and the forms marking those fields.
- [ ] Project: `nextcloud-vue`: a runtime required-fields input for forms (see Cross-Project Dependencies).

## Scope

### In Scope

- A `FieldRequirement` record per administration: schema, field, reason.
- A settings page listing the form fields of shillinq's schemas with a required toggle.
- A pre-save check that refuses a create or update missing an administration-required field, naming the fields.
- The forms marking those fields required.

### Out of Scope

- Making a shipped required field optional. The shipped `required` lists hold what the data model needs.
- Adding fields; custom fields are openregister's row (`plt-custom-fields`).

## Approach

Requirements are records; one pre-save listener enforces them, the way
`FeeScheduleValidationListener` enforces fee rules. Details are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/`: the `FieldRequirement` schema.
- `lib/Listener/`: one pre-save listener on `ObjectCreatingEvent` and `ObjectUpdatingEvent`.
- `src/manifest.d/`: the settings page.

## Cross-Project Dependencies

- nextcloud-vue: forms mark a field required from the schema and page config. A host-provided map of extra required fields per schema lets the form mark and pre-check them; until it exists the server refusal names the missing fields in the form's error. The addition is listed for its owner.

## Risks

### Risk 1: A requirement blocks system writes
**Severity:** Medium. **Mitigation:** the check applies to writes by a person through the object API; writes made by shillinq's own services and handlers pass a system flag and are not refused.

## Rollback Strategy

Unregister the listener; requirements stay as inert records.

## Open Questions

None.
