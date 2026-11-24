---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: ledger-sealed-entries

## Summary

An auditor needs to prove that a posted entry still says what it said when
it was posted. Shillinq seals the export packages it hands out, and
OpenRegister chains its audit rows, but neither covers the posted entry
itself: an edit made past the application leaves no trace. This change
seals every posted ledger transaction into a chain per administration and
lets a controller check the chain from the general ledger page.

## Motivation

One ledger row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`led-tamper-proof`**, "Seal posted entries so any later tampering can be
proven." Rated partial, built. Matrix evidence:
"lib/Lifecycle/AuditTrailGuard.php:9 keeps audit records insert-only;
lib/Service/AuditExportService.php:31 and
lib/Service/AccountantsdossierExportService.php:23 stamp a SHA-256 over
exported packages so later tampering is detectable. Posted entries
themselves are not hash-chained." Note: "Export packages are sealed,
individual posted entries are not." Changelog demand:
https://www.odoo.com/odoo-19-release-notes. odoo is rated yes
(odoo/odoo@19.0 `addons/account/models/account_journal.py:145`
`restrict_mode_hash_table` "Secure Posted Entries with Hash", and the Secure
Entries wizard under Closing).

## Affected Projects

- [ ] Project: `shillinq`: a seal on each posted transaction, a chain per administration, and a check a controller can run.

## Scope

### In Scope

- Sealing a `GLTransaction` when it reaches `posted`: a SHA-256 over its header and lines chained to the previous seal of the same administration.
- A check that recomputes the chain and names the first transaction whose content or link no longer matches.
- The check on the general ledger page and as an `occ` command.

### Out of Scope

- Sealing drafts. A draft may still change; only posted content is a record.
- Replacing OpenRegister's audit chain. It proves the audit log was not rewritten; this proves the posted content was not.
- Sealing historic postings retroactively beyond a single genesis pass (see design.md, Migration Plan).

## Approach

A listener on `ObjectTransitionedEvent` to `posted` for `GLTransaction`
computes the seal under a per-administration lock and writes three fields
on the transaction. The check walks the chain in sequence order. Details
are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/shillinq_register.json` or a new `register.d` fragment: `sealSequence`, `sealHash`, `previousSealHash`, `sealedAt` on `GLTransaction`.
- `lib/Listener/`: one listener; `lib/Service/`: the seal and check logic; `lib/Command/`: one command.
- `src/manifest.json` `GeneralLedger`: a header action.

## Cross-Project Dependencies

None. OpenRegister's `AuditHashService::verifyChain()` and `AuditSealJob`
(openregister development) are the precedent this follows, including the
lock that stops two seals chaining onto one predecessor.

## Risks

### Risk 1: Two posts at once chain onto the same predecessor
**Severity:** Medium. **Mitigation:** a per-administration lock around reading the chain head and writing the seal, the lesson OpenRegister recorded in `RechainAuditTrailCommand`.

### Risk 2: A legitimate reversal looks like tampering
**Severity:** Low. **Mitigation:** a reversal is a new posted transaction with its own seal; the reversed one changes only its `state`, which is excluded from the sealed content.

## Rollback Strategy

Unregister the listener. Seals already written stay and can still be checked.

## Open Questions

None.
