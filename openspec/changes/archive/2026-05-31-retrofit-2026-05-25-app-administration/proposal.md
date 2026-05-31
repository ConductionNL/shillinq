# Proposal: retrofit-2026-05-25-app-administration

`kind: retrofit` — reverse-engineered spec for already-shipped code.

## Summary

Shillinq ships a small **application-administration** surface that has
been live since the app scaffold but was never captured in a spec: a
settings controller/service pair (`GET`/`POST /api/settings`, plus a
forced `load` re-import), a health endpoint, a metrics endpoint, the
generic OpenRegister object store the Vue shell uses to read register
data, and the admin/in-app settings forms that consume them.

This change reverse-specs that observed behavior so every method in the
cluster carries an `@spec` reference (ADR-003 spec-coverage), without
altering a single line of runtime code. All tasks are `[x]` — the code
already exists.

## Motivation

Gate-16 spec-coverage flags every settings/observability/object-store
method as `missing @spec`. These are real, user-observable behaviors
(an admin saves a register ID; a monitoring probe hits `/health`), not
framework glue, so the correct closure is a reverse-spec rather than an
`@spec exclude`. The SPA template shell (`DashboardController`,
`App.vue` bootstrap, the deep-link scaffold stub) remains genuine glue
and is handled with reasoned `@spec exclude` markers, not this change.

## Affected Projects

- [x] Project: shillinq — annotation-only; adds one `app-administration`
  capability spec and `@spec` docblock/JSDoc references on the covered
  methods. No behavioral change.

## Scope

### In Scope

- New `app-administration` capability spec with 5 REQs covering settings
  read/write, configuration re-import, health/metrics observability, and
  the generic object store.
- `@spec` annotations on the covered backend and frontend methods.

### Out of Scope

- Any change to runtime behavior, endpoints, or schemas.
- The SPA template shell and deep-link scaffold stub (excluded as glue).

## Risks

Negligible — annotation-only. Spec text describes observed behavior; if
the code is later found buggy the spec is the place to tighten it.

## Rollback

Revert the commit; no data or schema migration involved.

## Open Questions

- The metrics endpoint currently returns an empty `metrics: []` array
  (placeholder). The REQ documents this observed shape; a future change
  can populate real metrics.
- The deep-link listener still registers the scaffold's `example` schema;
  tracked as glue, to be wired to real schemas when domain pages land.
