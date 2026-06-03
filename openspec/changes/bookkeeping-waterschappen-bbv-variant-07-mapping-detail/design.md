# Design — Member 07: mapping detail

## Scope

This `kind: code` member builds the Budget Mapping detail page, its two
pickers, inline validation, and save/delete. It does not re-declare the
validation constraints — those are the schema rules from member 03;
the page surfaces them inline.

## Decisions carried from the giant

- Detail uses `CnDetailPage` + `CnFormDialog` + `CnObjectSidebar`
  (audit tab) — platform components, no custom form code.
- GL Account picker reads the T1 Chart of Accounts register; Programme
  picker reads `BBVProgramme` filtered to the current fiscal year.
- Inline validation mirrors the schema's per-account ≤ 100% rule for UX
  feedback, but OpenRegister remains the enforcement authority
  (ADR-022) — the page must not be the only guard.

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| Detail form | `CnDetailPage` / `CnFormDialog` | form fields + actions |
| Audit sidebar | `CnObjectSidebar` | audit-trail tab |
| Pickers | autocomplete over OR registers | account + programme |
| Persistence | `objectStore.saveObject` / `deleteObject` | from member 06 store |

## Security (ADR-005)

Writes go through `objectStore.saveObject`/`deleteObject`, which call
OpenRegister object endpoints carrying RBAC + the member-03 validation.
Delete is confirm-gated and only offered for existing records. No
client-only authorisation; the server validates every write.

## i18n note

Hardcoded strings here are extracted to keys in member 10. Any dialog
lives in its own file (hydra-gate-modal-isolation); NcSelect pickers
carry an `inputLabel` (hydra-gate-nc-input-labels).
