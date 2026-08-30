# Design — Member 06: mapping index

## Scope

This `kind: code` member builds the Budget Mapping index page and its
object store. It authors no detail-page or picker logic (member 07).

## Decisions carried from the giant

- Index uses the platform `CnIndexPage` list pattern — no custom table
  component.
- The store uses `createObjectStore` (Options API), per the Shillinq
  store convention — no bespoke Vuex module.

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| List view | `CnIndexPage` | columns + search + filter |
| Object store | `createObjectStore` | `('budget-bbv-mapping', 'BudgetBBVMapping', 'Mappings')` |
| Relations / audit | store plugins | register `relations`, `auditTrails` |

Search: by GL account number or programme code. Filters: fiscal year,
allocation range, effective-date range. Add → detail `id=new`; row
click → detail `id=<uuid>`.

## Security (ADR-005)

The index reads `BudgetBBVMapping` records scoped to the active
administration's fiscal year (full scoping in member 09). Reads honour
OpenRegister RBAC; the page introduces no direct write path.
