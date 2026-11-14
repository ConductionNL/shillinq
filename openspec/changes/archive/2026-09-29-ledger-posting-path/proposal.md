---
kind: code
depends_on: []
---

# Proposal: ledger-posting-path

## Summary

A user who presses Post on a general ledger transaction or a journal entry
gets an error, because both post transitions declare a lifecycle action
that no handler answers. This change registers the two missing handlers,
`evaluate-allocation-rules` and `materialise-gl-transaction`, so posting
works from the ledger pages, from a second open fiscal year, and for the
payroll journal humaniq hands in.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) share one missing
half. The OpenSpec pass of 2026-09-27 decided `build` for all three
(`openspec/parity/gap-decisions.json`).

**`led-double-entry`**, "Keep double-entry books where every entry balances
debit against credit." Rated partial, built. The matrix evidence: "the same
post transition declares action `evaluate-allocation-rules`, and no handler
for it is registered in shillinq, while OpenRegister's
LifecycleActionRegistry (... BUILTINS = set-fields only) throws on an
unresolved action, so a user-driven post from the GL page aborts." All five
competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Reference-financial-glaccounts-fingl-createdgltransr, "every activity posts paired debit and credit G/L transactions".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207163-een-memoriaalboeking-maken, "De bedragen dienen aan de credit en aan de debet zijde in balans te zijn".
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/hoe-maak-ik-een-boeking, bookings through daybooks, each line posted to a grootboekrekening.
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/memoriaal-boeki-3062311, "Zodra de boeking in balans is, dan kan deze als [Concept] of [Definitief] worden opgeslagen".
- odoo: odoo/odoo@19.0 `addons/account/models/account_move.py:2784` `_check_balanced`, driven 2026-09-26: an unbalanced entry is refused with "The entry is not balanced."

**`led-open-years`**, "Keep more than one fiscal year open for posting at the
same time." Rated partial, built: "Several years can be open; the post path
they would receive entries on is broken." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. All five
competitors rate it yes, for example twinfield
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/de-jaarafsluiti-3041100,
"bij een concept jaarafsluiting kan er nog in het betreffende boekjaar
worden geboekt") and moneybird
(https://helpcenter.moneybird.nl/nl/articles/208002-jaarafsluiting).

**`ppl-payroll-journal`**, "Book payroll results into the ledger as journal
entries." Rated partial, built: "The journal is computed in code with no
caller, and nothing posts it." humaniq already computes the payroll journal
and writes it into shillinq's `JournalEntry` register (humaniq matrix row
`pay-gl-journal`, built, `lib/Command/GlPostRunCommand.php`), so what is
missing on this side is posting that entry. Four competitors rate it yes:
exact-online (https://www.exact.com/nl/producten/salaris/features-en-prijzen),
moneybird (https://www.moneybird.nl/product/koppelingen/salarisadministratie/),
snelstart (https://www.snelstart.nl/boekhouders-en-accountants/functies/salarisadministratie)
and odoo (https://www.odoo.com/documentation/19.0/applications/finance/fiscal_localizations/netherlands.html).

## Affected Projects

- [ ] Project: `shillinq`: two lifecycle action handlers, their DI registration, and the inventory of every transition that declares them.

## Scope

### In Scope

- A handler for `evaluate-allocation-rules`, declared on `GLTransaction.post`.
- A handler for `materialise-gl-transaction`, declared on `JournalEntry.post` and `JournalEntry.postDirect`, and on the other transitions listed in design.md.
- An inventory of every transition that declares either action, and for each one a decision: served by the handler, or the declaration removed because a PHP poster already books it.
- Posting a journal entry that humaniq wrote for an approved payroll run.

### Out of Scope

- Retiring shillinq's own payroll journal builder (`PayrollService::bouwLoonjournaalpost`, `GET /api/payroll/journaalpost`). Payroll runs in humaniq (matrix row `ppl-payroll`, decided no); the retirement is its own change once this path is proven.
- A limit on how many fiscal years may be open at once. No competitor imposes one.
- Allocation rules with a cadence other than `per-posting`.

## Approach

Register each handler in the Nextcloud server container under its declared
action name, which is how OpenRegister's `LifecycleActionRegistry` resolves a
name it does not ship. Both handlers are thin: they read the object being
transitioned and the `actionParameters` of the declaration and write objects
through OpenRegister's `ObjectService`. Details and the per-transition
inventory are in design.md.

## New Dependencies

None.

## Impact

- `lib/AppInfo/Application.php`: two service registrations.
- `lib/Lifecycle/Action/`: two new handler classes beside `AppendReopenHistoryAction`.
- `lib/Settings/register.d/`: declarations removed where a PHP poster already books the transition.
- Every transition in the inventory stops aborting.

## Cross-Project Dependencies

- OpenRegister: `LifecycleActionInterface` and `LifecycleActionRegistry` as they stand on development (84352bae). No change needed there.
- humaniq: its payroll hand-off (`lib/Flow/PayrollGlPostNode.php`) writes the `JournalEntry` this change posts. No change needed there.

## Risks

### Risk 1: Double posting where a PHP listener already books
**Severity:** High. **Mitigation:** the inventory in design.md names, per declaring transition, whether a PHP poster (for example `CogsPosterService` behind `StockMoveTransitionedListener`) already writes the `GLTransaction`. Those declarations are removed, not served. The handler is idempotent on the declaration's `idempotencyKey` and `backReferenceField`.

### Risk 2: Allocation rules that were never run start moving amounts
**Severity:** Medium. **Mitigation:** the seeded `AllocationRule` records are listed in the release note, and a rule only runs when its state is `active`.

## Rollback Strategy

Remove the two service registrations. Posting returns to aborting, which is
the current behaviour; no data written by a successful post is touched.

## Open Questions

None.
