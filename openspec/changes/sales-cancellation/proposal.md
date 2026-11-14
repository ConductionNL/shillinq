---
kind: code
depends_on: []
---

# Proposal: sales-cancellation

## Summary

Two ways a customer leaves are unsupported. A subscription (a recurring
invoice profile) can be ended, but nobody records why, so the reasons
customers leave cannot be seen. And a consumer who concluded a contract online
has no withdrawal button, which EU consumer law now requires on every online
interface where a distance contract is concluded. This change records a
cancellation reason whenever a subscription ends, lets a customer cancel from
the portal and asks why, shows the reasons as a report, and adds a withdrawal
function for the one online sales surface shillinq serves today, the booking
widget, rendered in portaliq with shillinq deciding, cancelling and refunding.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) share the moment a
customer ends a contract. The OpenSpec pass of 2026-09-27 decided `build` for
both (`openspec/parity/gap-decisions.json`).

**`sal-churn-reason`**, "Ask customers why they cancel a subscription and see
the reasons." Rated no, built state none. Matrix note:
"RecurringInvoiceProfile has a status but no cancellation reason; the only
cancellationReason is on SEPA mandates (lib/Lifecycle/MandateGuard.php:132)."
Demand: changelog
https://www.moneybird.nl/changelog/ontdek-waarom-klanten-hun-abonnement-stopzetten/.
Two competitors rate it yes:

- moneybird: https://www.moneybird.nl/changelog/ontdek-waarom-klanten-hun-abonnement-stopzetten/ (20 oktober 2025), "Vanaf nu kun je eenvoudig uitvragen waarom klanten hun abonnement stopzetten".
- odoo: https://www.odoo.com/documentation/19.0/applications/sales/subscriptions/closing.html, when a customer picks a reason and closes, "the subscription is marked Churned" and the reason is logged.

**`sal-withdrawal-button`**, "Offer consumers a withdrawal button on online
sales pages, as consumer law now requires." Rated no, built state none. Matrix
note: "No consumer sales page or withdrawal function in lib/ or src/." Demand:
changelog https://www.moneybird.nl/changelog/herroepingsknop-voor-online-verkopen/.
One competitor rates it yes:

- moneybird: https://www.moneybird.nl/changelog/herroepingsknop-voor-online-verkopen/ (14 juli 2026), "Webshops zijn sinds kort verplicht consumenten een herroepingsknop te bieden".

The legal basis is article 11a of the Consumer Rights Directive
(2011/83/EU), inserted by Directive (EU) 2023/2673 and applicable from
19 June 2026: a trader that concludes distance contracts through an online
interface must offer a withdrawal function there.

design.md corrects the matrix note on the second row: shillinq does serve one
consumer-facing online sales surface, the embeddable booking widget
(`widget.js`, REQ-WSW-004), where a consumer books and may pay a deposit.

This change covers both rows.

## Affected Projects

- [ ] Project: `shillinq`: cancellation reasons on `RecurringInvoiceProfile`, a reasons report, portal contributions for subscriptions and withdrawals, the withdrawal rule, record, acknowledgement and refund for widget bookings.
- [ ] Project: `portaliq`: renders the cancel and withdraw actions shillinq contributes, and a guest page reached from a signed link. No code in this repo.

## Scope

### In Scope

- A required reason when staff end a recurring profile, and the date the cancellation takes effect.
- A `subscriptions` collection for the customer audience in shillinq's portal contribution, with a cancel action that asks an optional reason.
- A cancellation reasons report on the Reports page: count and lost monthly recurring revenue per reason per quarter.
- A withdrawal function for a booking a consumer made through the booking widget: whether it is still withdrawable, the withdrawal record, cancelling the appointment without a cancellation fee, refunding any deposit in full, and a durable acknowledgement by email.
- Where the button renders: in portaliq for a signed-in customer, and on a portaliq guest page reached through a signed link in the booking confirmation mail and on the widget's confirmation step.

### Out of Scope

- A withdrawal function for subscriptions: staff create recurring profiles, so none is concluded online today. The rule applies the moment one is.
- Paid sign-ups on portaliq (school trips, activities): the contract is concluded on portaliq and the trader is the school; the button is portaliq's and the money path is the existing credit path of `extracurricular-fee-to-shillinq`.
- Retention offers or win-back mails.
- Dutch translations of the directive's label beyond the one string the portal shows.

## Approach

`RecurringInvoiceProfile` gains a `cancellation` group, and a guard on its two
end transitions requires a reason code when staff end it. A new
`subscriptions` collection and a `cancel-subscription` action in
`PortalContributionProvider` let portaliq show a customer their own profiles
and forward a cancellation with an optional reason, the same forwarding the
`pay` action uses. The report is a card on the existing Reports page backed by
declared aggregations. For bookings, `WithdrawalService` decides whether an
appointment made through the widget can still be withdrawn, and on withdrawal
cancels it through `CancellationService` with the fee set to zero, refunds the
deposit, writes a `Withdrawal` record and mails the acknowledgement; portaliq
forwards the `withdraw` action to it. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `RecurringInvoiceProfile` gains `cancellation` and `customerMasterId`; `Appointment` gains `withdrawal`; `Withdrawal` is added (all additive).
- Code: new `CancellationReasonGuard`, `SubscriptionPortalActionController`, `WithdrawalService`, `WithdrawalController`; changes in `PortalContributionProvider`, `ConfirmationMailer` and the widget's confirmation step.
- Manifest: a reason field on the end action of `RecurringInvoiceProfileDetail`, a card and page for the report.
- API: two routes for portaliq's server-to-server forwards.

## Cross-Project Dependencies

- portaliq: renders the `cancel-subscription` and `withdraw` actions shillinq contributes, with the withdrawal button labelled as article 11a requires, and serves a guest page for a signed withdrawal link so a consumer without a portal account can withdraw. If portaliq has no guest subject for a signed link, that is portaliq's change; shillinq issues and verifies the signed link.

## Risks

### Risk 1: A consumer withdraws and is charged a late-cancellation fee
**Severity:** High. **Mitigation:** a withdrawal cancels with the fee forced to zero and the refund set to everything paid; the late-fee brackets of `CancellationPolicy` apply only to a cancellation that is not a withdrawal.

### Risk 2: Asking why becomes a hurdle to cancel
**Severity:** Medium. **Mitigation:** the customer's reason is optional and asked on the same step as the confirmation; the cancellation never waits for it.

### Risk 3: The withdrawal link works for someone else's booking
**Severity:** High. **Mitigation:** the link is signed for one appointment and expires with the withdrawal period; shillinq verifies it server-side before anything changes.

## Rollback Strategy

Revert the PR. The schema changes are additive; profiles keep their recorded
reasons and withdrawals already processed keep their records.

## Open Questions

- Should the reasons list be configurable per administration? This change ships a fixed list with an "other" free text, which is what both quoted competitors show.
