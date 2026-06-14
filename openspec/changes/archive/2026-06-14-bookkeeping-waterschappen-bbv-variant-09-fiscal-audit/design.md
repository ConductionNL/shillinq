# Design — Member 09: fiscal scoping + audit

## Scope

This `kind: code` member applies fiscal-year scoping across the BBV
consumers (members 05–08) and verifies audit-trail integration. It
adds no new register or UI surface.

## Decisions carried from the giant

- **D5** — fiscal-year scoping is implicit: all programme, mapping, and
  dashboard queries filter by the active administration's current
  fiscal year; multi-year views are out of scope.
- **D6** — audit trail is automatic via OpenRegister's immutable audit
  (ADR-022); no app-local audit service.

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| Fiscal year context | Shillinq Administration (T1) | inherit current FY |
| Audit trail | OR immutable audit | verify, do not reimplement |

## Security (ADR-005)

Fiscal-year scoping is also a data-isolation control: a query for one
administration MUST NOT surface another administration's programmes,
mappings, or GL spend. The scope filter is server-side (derived from the
session's active administration), not a client parameter — preventing a
cross-administration IDOR.

## Audit verification

The member confirms that create/update/delete on both registers writes
a complete OR audit record (timestamp, user id, action, before/after
state). No new service is authored — the verification is the
deliverable.
