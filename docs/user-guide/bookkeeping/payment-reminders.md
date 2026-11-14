---
sidebar_position: 11
title: Automatic payment reminders
description: Let Shillinq send reminders for overdue invoices every day, by each customer's ladder, and handle the stages that need a person.
---

# Automatic payment reminders

Shillinq can chase overdue invoices for you. Once a day it marks issued invoices past their due date as overdue. It then sends each overdue invoice the reminder that is due on its ladder. Reminders are off until you switch them on, per administration.

## Switch reminders on

1. Open the administration under **Administrations > Administrations**.
2. Choose **Switch on reminders**.
3. Shillinq lists every overdue invoice with the stage and channel the first run would send. Nothing is sent yet.
4. Check the list, then confirm.

The administration now shows **Automatic payment reminders** as on. The next daily run sends the reminders you saw. To stop reminders, open the administration, edit it and turn **Automatic payment reminders** off.

## Ladders

A ladder is the list of stages an overdue invoice goes through. Each stage has a number of days after the due date, a channel and a text. Shillinq ships two ladders:

| Stage | Standard ladder | Government ladder |
|---|---|---|
| 1 | Friendly reminder by email, on the due date | Reminder by email, on the due date |
| 2 | Second reminder by email, after 14 days | Second reminder by email, after 30 days |
| 3 | Final notice by email and registered post, after 30 days | Final reminder by email, after 60 days |
| 4 | Notice of default by registered post, after 60 days | Escalation to the account manager by email, after 90 days |
| 5 | Hand-over to a collection agency, after 90 days | |

Shillinq sends one stage per run. An invoice that is already 120 days overdue starts at the friendly reminder, not at the last stage. The next stage waits until its own gap since the previous stage has passed. A stage that failed is tried again in the next run.

### Which ladder a customer gets

Shillinq picks the ladder in this order:

1. the ladder named in **Dunning Policy Ref** on the customer;
2. otherwise the administration's default ladder;
3. then a customer ladder override, when an active one exists for that customer and that ladder.

Overrides live under **Bookkeeping > Customer overrides**. Use one to pause or change a stage for one customer without touching the ladder everyone else gets.

### What a reminder says

Open a ladder under **Bookkeeping > Dunning Ladders** to see the subject and body of each stage. Each text exists in Dutch and English. The customer gets the language set on the customer or the invoice, otherwise the language of the customer's country, otherwise Dutch. Shillinq fills in the customer's name, the invoice number and dates, the amount still open, your IBAN, the payment term and any collection costs and interest, and attaches the invoice as a PDF.

## Stages that need a person

Shillinq sends email itself. It does not post letters or contact a collection agency. A stage with registered post or a collection agency, and any stage for a customer without an email address, is recorded as **manual**. Everyone in the `ar-controller` group then gets a Nextcloud notification to do it by hand. A final notice by email and registered post sends the email and still counts as manual, because the letter is yours to post.

## Collection costs for consumers

A consumer is a customer with no KvK number and no VAT number. For a consumer, Shillinq adds collection costs only once the final notice (the 14-day letter) was delivered at least 15 days earlier. Until then the reminder names no costs. A business customer is charged costs from the stage that sets them.

## Before sending: what Shillinq checks

Just before it sends, Shillinq reads the invoice again. It skips an invoice that was paid, written off or disputed since the run started, and an invoice whose reminders are paused, for example by a payment plan.

## Follow the runs

**Bookkeeping > Dunning Runs** lists every reminder sent, with its stage, channel and outcome: delivered, failed or manual. A failed run names the reason.

Choose **Next run** to see, per administration, whether reminders are on, how the last run went, and which stage each overdue invoice gets in the next run. Nothing is sent from this view.

:::note
Reminders ship switched off. Upgrading Shillinq sends nothing until someone switches reminders on for an administration.
:::

Next: agree a [payment plan](./payment-plans.md) with a customer who cannot pay at once. Reminders for the invoices in a plan pause while the plan runs.
