# bookkeeping-credit-control-dunning Specification (delta)

## Purpose

Overdue invoices are chased by themselves, on each customer's ladder, with
escalating wording, and the runs list shows what really went out. From shillinq
matrix rows `rec-reminders` and `rec-ladder-per-customer`, and pipelinq matrix
row `prod-overdue-chasing`.

## ADDED Requirements

### Requirement: A daily job marks overdue invoices and chases them (REQ-RAD-001)

For every administration with dunning enabled, a background job SHALL run once a
day, move each issued invoice past its due date to `overdue`, and send each
overdue invoice the next ladder stage that is due. The job MUST NOT stop when one
invoice fails, and SHALL report per run how many reminders were sent, failed,
handed over for manual action or skipped.

#### Scenario: The first reminder goes out without anyone acting

- GIVEN dunning enabled for Adviesbureau Kade B.V. with ladder Standaard, and invoice 2026-0412 of Bakkerij De Korenaar B.V. due on 2026-10-15 and unpaid
- WHEN the daily job runs on 2026-10-22
- THEN the invoice shows state overdue
- AND the Dunning runs page lists stage 1 for invoice 2026-0412 sent by email on 2026-10-22

### Requirement: Stages escalate one at a time (REQ-RAD-002)

The job SHALL send the lowest-numbered stage that has not been sent for the
invoice and whose threshold is reached, and SHALL send a later stage only when at
least the difference between the two thresholds has passed since the previous
stage was sent. A stage MUST NOT be sent twice for the same invoice.

#### Scenario: A long-overdue invoice starts at the friendly reminder

- GIVEN an invoice 120 days overdue that never received a reminder, when dunning is first enabled
- WHEN the job runs
- THEN stage 1, the friendly reminder, is sent, not the collection-agency stage

### Requirement: A reminder mail carries the stage's wording and the invoice (REQ-RAD-003)

An email stage SHALL be sent to the customer's invoice address with the stage's
subject and body in the customer's language, merge fields filled, and the
invoice PDF attached. The run SHALL be recorded as delivered only when the mail
server accepted the message, and as failed with the reason otherwise; a failed
stage SHALL be tried again on the next daily run.

#### Scenario: The third stage announces collection costs

- GIVEN invoice 2026-0412 at stage 3 of ladder Standaard
- WHEN the job sends it
- THEN Bakkerij De Korenaar B.V. receives the mail "Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen" naming invoice 2026-0412 and the outstanding amount, with the invoice attached
- AND the run shows delivered

### Requirement: Each customer is chased on its own ladder (REQ-RAD-004)

The job SHALL use the ladder the customer's `dunningPolicyRef` names, else the
administration's default ladder, and SHALL apply the customer's active
`KlantLadderOverride` on top of it. An invoice without any ladder SHALL be
skipped and counted in the job report.

#### Scenario: A municipality gets a gentler schedule

- GIVEN an active override for Gemeente Voorbeeld with email stages at day 14 and day 30 only
- WHEN its invoice 2026-0420 is 14 days overdue and the job runs
- THEN the first reminder of the override is sent and no stage of the Standaard ladder is

### Requirement: Paid, disputed and paused invoices are not chased (REQ-RAD-005)

The job MUST NOT send a stage for an invoice that is paid, written off, disputed,
or has an active dunning pause, and SHALL read that state when it sends, not when
it listed the invoice.

#### Scenario: A payment arrives in the morning

- GIVEN invoice 2026-0412 due for stage 2 today
- WHEN its payment is matched at 08:00 and the job runs at 09:00
- THEN no stage 2 is sent

### Requirement: Postal and collection-agency stages are handed to a person (REQ-RAD-006)

A stage on a channel without a working adapter SHALL be recorded with delivery
status `MANUAL` and SHALL notify every `ar-controller` of the administration to
act on it. Such a stage MUST NOT be recorded as delivered.

#### Scenario: The notice of default must be posted

- GIVEN invoice 2026-0412 due for stage 4 on registered post
- WHEN the job runs
- THEN the Dunning runs page shows stage 4 as manual
- AND each ar-controller receives a notification to send the notice of default for invoice 2026-0412

### Requirement: A consumer is charged collection costs only after the 14-day letter period (REQ-RAD-007)

For a consumer debtor, a stage carrying collection costs SHALL add them only when
a stage with statutory effect `14_DAYS_BRIEF_BIK` was delivered at least 15 days
before; otherwise the stage SHALL be sent without the costs.

#### Scenario: Costs are held back until the period has passed

- GIVEN a consumer invoice whose 14-day letter was delivered 10 days ago
- WHEN a later stage with collection costs becomes due
- THEN the stage is sent without collection costs

### Requirement: The next run can be previewed (REQ-RAD-008)

The `DunningRuns` page SHALL show, per invoice, the stage and channel the next
run would send, computed by the same selection as the job without writing
anything. Enabling dunning for an administration SHALL show this list first.

#### Scenario: A bookkeeper checks before switching dunning on

- GIVEN dunning still disabled for Adviesbureau Kade B.V. and twelve overdue invoices
- WHEN the bookkeeper opens the Next run panel on the Dunning runs page
- THEN twelve rows show the stage and channel each invoice would get, and no run has been written
