---
kind: code
depends_on: [case-payment-requests, portal-payment-initiation]
---

# Proposal: extracurricular-fee-to-shillinq

## Summary

A school asks parents for money several times a year: the ouderbijdrage, the
overblijfbijdrage, the schoolreisje, a club or course after school. Shillinq
already owns invoices, payment requests, the portal pay flow and dunning, so a
school contribution becomes an ordinary `ARInvoice` with no order behind it.
Shillinq raises one per guardian, in bulk, from a chargeable definition that
another app owns (learniq's `FeeItem`, portaliq's `activityOffer`). Each invoice
carries one `PaymentRequest` that points back at that definition, so the owning
app can read the payment state. A settled signal tells the owning app when the
money is in, without shillinq ever calling it. A voluntary contribution says so
on the invoice and is never dunned beyond one reminder, as the Wet vrijwillige
ouderbijdrage requires.

## Motivation

Decision D19 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`)
closes the D12 proposal: school contributions are shillinq invoices paid from
portaliq. Learniq retires its own `Order`, `OrderLine` and `PaymentTransaction`
and keeps `FeeItem` and `Entitlement`. Recon E
(`learniq-mi/learniq/_round2/recon/E-roles-and-lesson-shop.md`, section 1b)
found learniq's school-trip fee running through a learniq-local order stack that
bypasses shillinq, with an active learniq change deepening it. Its question 3,
option A, recommends routing new extracurricular charges through shillinq's
`PaymentRequest` and `portal-payment-initiation`. Section 4 names this change,
row `extracurricular-fee-to-shillinq`, size L.

The market asks for it. Recon E section 2 cites `G-new-8` in
`_round1/compare/proposed-rows.md` line 115: "Extracurricular activities:
enrolment, choices, waitlist, staffing and payment/invoicing", evidenced against
Gibbon's Activities module (PO VO). The scored M1 rows next to it are 9.6
"Agenda and events per group with sign-up" and 9.8 "Activiteitenplanner and
ouderhulp sign-ups", both rung 4, tier A, evidenced against Social Schools,
Klasbord, Kwieb and ParnasSys. Portaliq builds the sign-up half
(`extracurricular-activity-offer`, portaliq #746); this change is the money half.

Reading shillinq's `development` on 2026-09-27 showed four gaps between what
exists and what a school contribution needs:

1. `ObjectPaymentRequestValidator` allows one pending request per
   `(subject, requestType)`. A bulk raise for 400 guardians on one `FeeItem`
   would refuse guardian two.
2. `PaymentReconciliationService::reconcile()` books a receipt against a revenue
   account for every `subjectKind: object` request, even when an invoice stands
   behind it. The invoice already carries the revenue, so the income would be
   booked twice.
3. The portal pay flow reads and writes `ARInvoice.state`, while the invoice's
   lifecycle field is `lifecycleState`. No issued invoice is found payable, and a
   capture never moves the invoice to `paid`, so dunning would continue on money
   already received.
4. Guardians sign in to the portal with audience `parent` (learniq's own portal
   contribution). Shillinq's portal contribution and its pay endpoint serve only
   `customer`, so a guardian sees no invoice and cannot pay one.

## Affected Projects

- [ ] Project: `shillinq`: the bulk raise, the invoice-backed payment request, the
  voluntary dunning rule, the settled signal and the `parent` portal audience.
- [ ] Project: `learniq`: consumes the raise and the settled signal in its
  `payments-to-shillinq-migration` change. No code here.
- [ ] Project: `portaliq`: consumes the raise and the settled signal for
  `activityOffer` places. No code here.

## Scope

### In Scope

- A bulk raise, `POST /apps/shillinq/api/contributions/raise` and the same call
  in process, that turns one chargeable definition plus a list of guardians into
  one issued `ARInvoice` and one `PaymentRequest` per guardian. It is idempotent
  per guardian and child, so a repeated or chunked call never bills twice.
- `PaymentRequest.subject` gains `app`, so the reference names app, register,
  schema and id. A new `beneficiary` names the child the charge is for and joins
  the uniqueness key. `requestType` gains `contribution`.
- An `ARInvoice.contribution` group: kind, voluntary, chargeable, beneficiary and
  the raise batch.
- Debtor resolution: an existing `CustomerMaster`, a guardian's portal account, or
  a name and an email. A missing `CustomerMaster` is created, and a guardian's
  portal account is linked to it through portaliq's claim event.
- The invoice-backed request settles its invoice on capture and books no second
  receipt.
- The voluntary rule: the invoice text says the contribution is voluntary and
  that the child takes part either way. Dunning sends at most one reminder, with
  no collection costs, no interest and no hand-over to a collection agency.
- The settled signal: `PaymentRequest.settledAt` and `settledVia`, stamped once
  when the request first reports paid, by the provider or by hand. Consumers read
  it from OpenRegister's `ObjectUpdatedEvent` in process, or from integriq's
  outbound webhook out of process.
- The portal: shillinq serves the `parent` audience with the invoice and payment
  collections and the pay action. The pay flow reads `lifecycleState`.

### Out of Scope

- A school screen for raising contributions. The owning apps call the raise from
  their own screens (learniq's fee page, portaliq's activity roster).
- Learniq's migration off `Order`, `OrderLine` and `PaymentTransaction`, and its
  `Entitlement` activation on the signal. That is learniq's
  `payments-to-shillinq-migration`.
- Writing into another app's register. Shillinq never writes
  `activitySignup.paymentRequestRef` or `Entitlement` fields (ADR-066).
- A reduction or exemption scheme (a lower ouderbijdrage for low incomes). The
  owning app passes a per-guardian amount when it applies one.
- SEPA direct debit for contributions. The mandate path exists and stays as is.
- Refunds. A captured contribution is reversed through the existing credit path.

## Approach

One new service does the raise. It reads nothing from the owning app's schema:
the caller passes the definition's reference, the description, the amount and the
voluntary flag, and shillinq stamps the reference. Each guardian costs one
`CustomerMaster` lookup (or create), one issued `ARInvoice` and one
`PaymentRequest` with `subjectKind: object` and an `invoiceReference`. The
existing validator gains the `beneficiary` dimension. The reconciliation takes
the invoice branch whenever `invoiceReference` is set. A small dunning policy
reads `ARInvoice.contribution.voluntary` and is consulted where a stage is picked,
where a run is executed and where a dossier is handed to a collection agency.
`PaymentSettlementService` stamps `settledAt` from its own `report()`, so the two
routes to "paid" share one definition. The portal contribution adds a `parent`
manifest that reuses the customer's AR collections. Details are in design.md.

## New Dependencies

None. Portaliq's `PortalAccountClaimRequestedEvent` is dispatched duck-typed
behind `class_exists()`, as ADR-046 requires of portal integrations.

## Impact

- Schemas: `PaymentRequest` 0.3.0 to 0.4.0 (additive), `ARInvoice` gains the
  `contribution` group in a new fragment (additive).
- Code: new `ContributionRaiseService`, `ContributionInvoiceBuilder`,
  `ContributionDebtorResolver`, `VoluntaryContributionPolicy`,
  `ContributionController`; changes in `ObjectPaymentRequestValidator`,
  `PaymentReconciliationService`, `PaymentSettlementService`,
  `PaymentRequestActionController`, `PaymentRequestLeafProvider`,
  `DunningRunService`, `PortalPaymentSessionService` and
  `PortalContributionProvider`.
- API: one new route. The pay endpoint accepts the `parent` audience.

## Cross-Project Dependencies

- learniq `payments-to-shillinq-migration` (lane r2-unwind) calls the raise per
  `FeeItem` and activates `Entitlement` on the settled signal.
- portaliq `extracurricular-activity-offer` (#746) calls the raise from the
  activity roster and writes its own `activitySignup.paymentRequestRef` from the
  raise response. Its contract.md currently says shillinq writes that field; the
  PR body asks portaliq to change it.
- integriq `events-cloudevents` REQ-004 forwards the update as a CloudEvent. No
  integriq change.

## Risks

### Risk 1: A guardian without a portal link never sees the invoice
**Severity:** High. **Mitigation:** the raise links a portal account when the
caller passes the guardian's `portalSubjectRef`, and reports `portalLinked` per
guardian. Without it the invoice still exists and the existing "send payment
link" action mails the link.

### Risk 2: A voluntary contribution gets dunned by a path the policy misses
**Severity:** High. **Mitigation:** the policy sits in the three places a
reminder can start (`tickInvoice`, `executeStage`, `transferToIncasso`), each with
a test from the caller. A missing invoice is treated as not voluntary only when
it carries no `contribution` group.

### Risk 3: A large school bills in one request
**Severity:** Medium. **Mitigation:** one call takes at most 200 guardians and the
raise is idempotent per guardian and child, so the owning app chunks and a
retried chunk skips what already stands.

### Risk 4: The settled edge fires twice
**Severity:** Low. **Mitigation:** `settledAt` is written once and never cleared.
The reconciliation's replay guard turns a repeated webhook into a no-op, so no
second save happens.

## Rollback Strategy

Revert the PR. The schema changes are additive, so existing rows stay valid. Any
contribution invoices already raised remain ordinary issued invoices with their
payment requests; the `parent` manifest disappears and guardians fall back to the
mailed link.

## Open Questions

- Should a voluntary contribution's single reminder use its own template, with
  wording that repeats that the child takes part either way? This change caps
  the ladder and strips costs; the template itself is left to the school's
  dunning ladder configuration.
