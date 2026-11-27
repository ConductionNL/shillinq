# Design: sales-cancellation

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**Subscriptions.** A subscription is a `RecurringInvoiceProfile`
(`lib/Settings/register.d/recurring-invoicing.json:4`) with `status` `draft`,
`active`, `paused`, `ended`, and two end transitions, `endFromActive` and
`endFromPaused`, described as "Terminate the profile (manual, endDate reached,
or occurrenceCount exhausted)". Neither asks why. Staff reach them on
`RecurringInvoiceProfileDetail` (`src/manifest.d/recurring-invoicing.json:78`).
The only `cancellationReason` in shillinq is the SEPA mandate's
(`lib/Lifecycle/MandateGuard.php:132`), as the matrix says. A profile names its
customer by `customerReference`, a Nextcloud contact reference, and
`RecurringInvoiceGenerator::buildArInvoicePayload()` copies that reference into
the generated invoice's `customerId` (`lib/Service/RecurringInvoiceGenerator.php:345`),
so those invoices do not carry a `CustomerMaster` id. The portal scopes a
customer's data on the `customerMasterId` claim, so a customer cannot be shown
their own profiles without a link from the profile to its `CustomerMaster`.

**The portal.** `lib/Portal/PortalContributionProvider.php` contributes, for
audience `customer`, invoice, quote, order, contract and payment-request
collections scoped on `customerReference` or `customerMasterId`, and one action,
`pay`, of type `endpoint-forward` to `/apps/shillinq/api/portal/payments/initiate`
(:449-455). portaliq forwards the action server to server
(`portalPaymentInitiation#initiate`, `appinfo/routes.php:772`), which is the
pattern a cancel and a withdraw action can follow. No subscriptions collection
exists.

**Online sales surfaces shillinq serves.** Read to answer where a withdrawal
button is owed:

| Surface | Concludes a distance contract online? | Owner of the page |
|---|---|---|
| Booking widget (`widget.js`, `webpack.config.js:42-54`, REQ-WSW-004), posting to `/api/widget/appointments` (`appinfo/routes.php:153`) | Yes: a consumer books a service and may pay a deposit | shillinq's embed on the business's own website |
| Portal pay action | No: it pays an invoice for a contract made elsewhere | portaliq |
| School contributions (`parent` audience) | No: shillinq raises the invoice; any sign-up happens on portaliq, with the school as trader | portaliq, learniq |
| Recurring profiles | No: staff create them | shillinq, staff only |

**Matrix correction (`sal-withdrawal-button`).** The note "No consumer sales
page ... in lib/ or src/" misses the booking widget, which is a
consumer-facing online interface where a contract is concluded.

**Bookings.** `Appointment` (`register.d/10-bookings-create-appointment.json:150`)
gains `customerName`, `customerEmail`, `customerPhone` and `source` from the
widget fragment (`register.d/30-bookings-self-service-widget.json:38`), and
`appliedPolicy`, `refundAmount`, `refundStatus` from the cancellation rules
(`register.d/40-bookings-cancellation-rules.json:381`). `CancellationService`
(`lib/Service/CancellationService.php`) computes the late-fee refund
(`calculateRefund()`, :124) and applies a cancellation (`initiateCancellation()`,
:223). A deposit is refunded through `DepositPaymentAdapterInterface::initiateRefund()`
(`lib/Service/External/DepositPayment/DepositPaymentAdapterInterface.php:169`),
which is bound to the log-only `LogDepositPaymentAdapter`. The confirmation
mail (`lib/Service/ConfirmationMailer.php:99`) builds its web link from a route
`confirmationApi.portal` that `appinfo/routes.php` does not declare.

**The law.** Article 11a of Directive 2011/83/EU, inserted by Directive (EU)
2023/2673 and applicable from 19 June 2026, requires a withdrawal function on
the online interface through which the distance contract was concluded,
labelled "withdraw from contract here" or an equally unambiguous formulation,
available throughout the withdrawal period, followed by a confirmation step,
and an acknowledgement of receipt on a durable medium without undue delay. The
withdrawal period for a service is 14 days from the conclusion of the contract
(article 9); article 16 exempts, among others, leisure services for a specific
date and a service fully performed with the consumer's prior consent; article
13 requires reimbursement of all payments within 14 days.

## Goals / Non-Goals

**Goals**

- Every ended subscription carries a reason, and the reasons are visible per quarter.
- A customer can end their own subscription from the portal and is asked, not forced, to say why.
- A consumer who booked through the widget can withdraw while the law allows it, from where they booked, and gets their money back without a fee.

**Non-Goals**

- A withdrawal function for contracts shillinq does not conclude online.
- Retention flows.

## Decisions

### D1. A fixed reason list with free text

`RecurringInvoiceProfile.cancellation`: `reasonCode` (`too-expensive`,
`not-using`, `switched-provider`, `missing-feature`, `service-quality`,
`business-closed`, `other`, `not-given`), `reasonText`, `cancelledBy`
(`staff` or `customer`), `requestedAt`, `effectiveDate`. The guard
`CancellationReasonGuard::requireReason` on both end transitions refuses a
staff end without a code; `not-given` is allowed for a customer.

Alternative considered: a configurable `CancellationReason` schema.
Rejected for now: both competitors show a short fixed list, and a fixed enum
keeps the report comparable across administrations.

### D2. The profile learns its `CustomerMaster`

`RecurringInvoiceProfile.customerMasterId` is set when a profile is activated
(resolving the contact to the `CustomerMaster` with the same email), filled for
existing profiles by a repair step that lists what it could not resolve, and
from then on copied into each generated invoice's `customerId`. The
`subscriptions` portal collection scopes on it.

### D3. Cancel and withdraw are portal actions, forwarded like `pay`

`PortalContributionProvider` adds, for audience `customer`, a `subscriptions`
collection (the customer's own active and paused profiles) and two actions of
type `endpoint-forward`: `cancel-subscription` to
`/apps/shillinq/api/portal/subscriptions/cancel` and `withdraw` to
`/apps/shillinq/api/portal/withdrawals`. Both receive portaliq's server-to-server
forward with the subject assertion that `portalPaymentInitiation#initiate`
already verifies (`PortalAssertionVerifier`), and check ownership server-side.
The button text is portaliq's to render; shillinq supplies the label
"Withdraw from contract here" and its Dutch "Herroep de overeenkomst hier".

Alternative considered: a withdraw page in shillinq's widget. Rejected:
citizen and customer pages are portaliq's (ADR-108), and a new credentialed
public controller in a leaf app is what ADR-091 retires.

### D4. A guest reaches the button through a signed link

A widget booker usually has no portal account. The booking confirmation mail
and the widget's confirmation step carry a link signed for one appointment
(HMAC over appointment id and expiry, expiring with the withdrawal period),
to portaliq's guest page for the `withdraw` action. shillinq verifies the
signature on the forward. The mail's link replaces the web link built from the
undeclared `confirmationApi.portal` route.

### D5. The withdrawal rule

`WithdrawalService::isWithdrawable(appointment, now)` is true when the
appointment was booked through the widget (`source = widget`) by a consumer (no
company name or VAT id given), fewer than 14 days have passed since booking,
the appointment has not started, and the booked service is not marked
`withdrawalExempt` (a leisure service for a specific date, set per service by
staff with the exemption named). The answer carries the reason when false, so
the portal shows why the button is absent.

### D6. Withdrawing cancels without a fee and refunds everything

On a confirmed withdrawal the service writes a `Withdrawal` record
(`appointmentId`, `requestedAt`, `channel` portal or guest link,
`acknowledgedAt`, `refundAmount`, `refundStatus`), cancels the appointment
through `CancellationService::initiateCancellation()` with the fee forced to
zero and `cancelledReason = withdrawal`, credits any invoice and refunds any
deposit in full through `DepositPaymentAdapterInterface::initiateRefund()`,
and mails the acknowledgement with date and time. While the deposit adapter is
dormant, the refund is recorded as due, listed for staff, and the 14-day
deadline is shown on it.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Reason required on a staff end | Declarative guard on `endFromActive` and `endFromPaused` | A precondition on existing transitions. |
| Notifying staff of a customer cancellation or a withdrawal | Declarative: `x-openregister-notifications` on the profile's end transition with `cancelledBy = customer` and on `Withdrawal` creation | State-change notifications. |
| Reasons report | Declarative: `x-openregister-aggregations` on `RecurringInvoiceProfile` (count and sum of monthly amount grouped by `cancellation.reasonCode` and quarter of `effectiveDate`) rendered by a dashboard page | An aggregation over one schema. |
| Withdrawal rule, cancellation, refund, acknowledgement | Imperative, `WithdrawalService` | Time rules, a signed link, a refund and a mail. |

## Seed Data

`RecurringInvoiceProfile` gains `cancellation` and `customerMasterId`;
`Appointment` gains `withdrawal` (`withdrawalId`, `withdrawnAt`); the booking
service gains `withdrawalExempt` and `withdrawalExemptionReason`; `Withdrawal`
is added with the fields of D6.

Seed objects:

- Administration "Sportschool De Kracht": profile "Maandabonnement Kracht Basis" for customer "Fysio Linde B.V." at EUR 49 a month, ended on 2026-09-30 with reason `switched-provider` by the customer, text "We trainen voortaan bij een partner van onze huisarts".
- Profile "Personal training 10 lessen" ended by staff with reason `too-expensive`.
- Administration "Kapsalon Knip": an appointment "Knippen en kleuren" booked through the widget on 2026-09-20 for 2026-10-15 by consumer M. Visser, deposit EUR 25 paid, withdrawn on 2026-09-24 with the deposit refunded in full.
- A booking service "Workshop bloemschikken 12 oktober" marked `withdrawalExempt` with reason "leisure service on a specific date".

## Risks / Trade-offs

- [Staff mark a service exempt that is not] → the exemption carries a named reason and is listed on the service page; the default is not exempt.
- [Refunds cannot be paid automatically while the deposit adapter is dormant] → the refund is recorded as due with its deadline and shown to staff; nothing claims it was paid.
- [Profiles whose contact matches no `CustomerMaster`] → the repair step lists them; those customers do not see the subscription in the portal until staff link it.

## Migration Plan

A repair step fills `customerMasterId` on existing profiles where the contact
resolves and lists the rest. Ended profiles get `cancellation.reasonCode =
not-given`. Rollback is reverting the PR.

## Open Questions

- Should ending a profile at the customer's request stop the next invoice immediately, or at the end of the paid period? This change uses the end of the current billing period as `effectiveDate`.
