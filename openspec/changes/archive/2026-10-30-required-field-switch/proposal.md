---
kind: code
depends_on: [platform-required-fields]
---

# Proposal: required-field-switch

## Summary

Board ShInstellingen draws the "Verplichte velden" tab as a list of required
fields, each with an on/off switch. A `FieldRequirement` only knows
`lifecycleState` (active or retired), so the switch had nothing to write and
the tab showed a link only. This change gives a requirement an `enabled`
field, stops checking saves against a requirement that is switched off, and
draws the switches on the admin tab as manifest config over the library's
`toggle-table` widget.

## Motivation

Approved by Ruben on 16 Oct 2026 as one of four shillinq data model items.
Retiring a requirement ends it; switching it off pauses it. An administrator
who wants to stop the cost centre check during a migration should not lose
the requirement and its reason to do so.

## Affected Projects

- [x] Project: `shillinq`: the `enabled` field, the check that skips a switched-off requirement, a list endpoint and the admin tab.

## Scope

### In Scope

- `FieldRequirement.enabled` (boolean, default true), schema 0.2.0.
- `FieldRequirements` skips a requirement with `enabled: false`.
- `GET /api/field-requirements`: the active requirements of the caller's administrations, switched on or off, each with a title "<field> on <record type>".
- The Required fields admin tab draws one switch per requirement (`toggle-table`, `bind.mode: each`), declared in `src/manifest.d/platform-required-fields.json` under `adminTab`.

### Out of Scope

- Adding a requirement from the admin tab: the Required fields page keeps doing that, and the tab keeps its link to it.
- The board's Save button: a switch saves at once, the library widget's behaviour.

## Approach

The register change reaches an instance through the InitializeSettings repair
step, so the PR moves `<version>` in `appinfo/info.xml` and records the new
schema version in `tests/fixtures/register-versions.lock.json`. A requirement
without the field counts as switched on, so existing records keep working
before and after the upgrade.
