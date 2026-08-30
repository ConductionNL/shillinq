# Spec: contracts-single-home

## Purpose

This capability makes shillinq's `contract-lifecycle-management` `Contract`
schema the fleet's single, canonical, unambiguous ADR-051 `ns#Contract`
implementer, by ending its accidental register-merge collision with
`bookkeeping-ifrs15-revenue`'s revenue-recognition contract (renamed
`RevenueContract`), and adds the mechanical guard rail
(`tests/validate-registers.js`) and gate-19 e2e coverage needed to keep it
that way. The schema-identity changes this depends on live in the
`bookkeeping-ifrs15-revenue` and `semantic-invoice-consume` deltas alongside
this file; this file covers what is new to this capability specifically.

## ADDED Requirements

### Requirement: Same-slug full-schema-definition collision detection

`tests/validate-registers.js` MUST fail the build when two or more
`register.d` source files each declare a **full** schema body (both `type`
and `required` keys present) for the identical `components.schemas` key.
This is distinct from the existing case-insensitive slug-divergence check
(`checkSlugCaseCollisions`), which only catches slugs that differ by case
and does not catch two fragments declaring the identical key as separate
full definitions — the exact defect class this capability exists to close
(`Contract` declared in full by both `contract-lifecycle-management.json`
and, before this change, `bookkeeping-ifrs15-revenue.json`). A fragment that
only adds partial augmentation (e.g. `configuration`, additional
`properties`, `x-openregister-handoff`, notification rules — no `type` /
`required` of its own) MUST NOT be flagged; that is the legitimate ADR-037
overlay pattern used by `semantic-invoice-consume.json`.

#### Scenario: Two full definitions for the same slug fail validation

- **GIVEN** two `register.d` files each declare
  `components.schemas.SameSlug` with both a `type` and a `required` array
- **WHEN** `node tests/validate-registers.js` runs
- **THEN** it MUST exit non-zero and name both colliding files in its error
  output, in the same format as the existing `checkSlugCaseCollisions`
  output

#### Scenario: A partial augmentation fragment does not trigger the check

- **GIVEN** one `register.d` file declares a full `Contract` definition and
  a second declares only `configuration.implements` +
  `configuration.handoffContract` + one additional `property` for
  `components.schemas.Contract` (no `type`, no `required`)
- **WHEN** `node tests/validate-registers.js` runs
- **THEN** it MUST NOT flag this as a same-slug collision

#### Scenario: The fixed register passes with exactly one Contract and one RevenueContract

- **GIVEN** the post-rename register (`Contract` declared only by
  `contract-lifecycle-management.json` plus the `semantic-invoice-consume.
  json` overlay; `RevenueContract` declared only by
  `bookkeeping-ifrs15-revenue.json`)
- **WHEN** `node tests/validate-registers.js` runs
- **THEN** the same-slug collision check MUST pass for both slugs

@e2e exclude static register-fragment validation with no browser-observable behaviour; enforced by `node tests/validate-registers.js` on every PR, not a runtime UI flow

### Requirement: Contract pages render after the un-merge

Both the generic Contract Lifecycle Management pages (`/contracts` index,
`ContractDetail`) and the renamed Revenue Contract pages
(`/ifrs-15/contracts` index, `ContractDetail` for `RevenueContract`) MUST
continue to render inside the shillinq manifest shell after the schema
rename and register un-merge, with no route, menu-id, or manifest-byte
change (only the `page.config.schema` values inside the six
`bookkeeping-ifrs15-revenue` pages change from `"Contract"` to
`"RevenueContract"`).

**Feature tier**: MVP

#### Scenario: CLM Contracts index and detail render

- **GIVEN** the post-rename register is imported
- **WHEN** an operator navigates to `/apps/shillinq/#/contracts`
- **THEN** the Contracts index page MUST render inside the manifest shell,
  and opening a contract MUST render its detail page, with no
  `additionalProperties`/`required` errors from a leftover IFRS-15 field

#### Scenario: Revenue Contracts index and detail render post-rename

- **GIVEN** the post-rename register is imported
- **WHEN** an operator navigates to `/apps/shillinq/#/ifrs-15/contracts`
- **THEN** the Revenue Contracts index page MUST render, and opening a
  revenue contract MUST render its detail page, sourced from the
  `RevenueContract` schema

@e2e contracts-single-home::clm-contracts-index-and-detail-render
@e2e contracts-single-home::revenue-contracts-index-and-detail-render-post-rename
