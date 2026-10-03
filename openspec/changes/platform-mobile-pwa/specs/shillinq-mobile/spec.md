# shillinq-mobile Specification

## Purpose

Receipts and invoice drafts are made on a phone, with or without a
connection. From shillinq matrix rows `plt-mobile` and `plt-offline`.

## ADDED Requirements

### Requirement: Shillinq Mobile is installable on a phone (REQ-PMP-001)

Shillinq SHALL serve an installable web app "Shillinq Mobile" on its own
scope, opening on a home screen with the user's drafts and receipts.

#### Scenario: An entrepreneur installs the app

- GIVEN an entrepreneur on their phone at /apps/shillinq/mobile
- WHEN they choose Add to home screen
- THEN a "Shillinq Mobile" icon opens the app full screen on its home screen

### Requirement: A receipt is captured with the camera (REQ-PMP-002)

The receipt screen SHALL take a photo with the phone's camera and the
receipt's amount, date and category, and create a receipt record.

#### Scenario: A receipt at the till

- GIVEN the entrepreneur on the receipt screen with a connection
- WHEN they photograph a receipt and save it with amount EUR 24.20
- THEN the receipt appears in shillinq's receipts with the photo attached

### Requirement: Work done offline is queued and synced once (REQ-PMP-003)

Without a connection, receipts and invoice drafts SHALL be kept on the
phone and shown as queued, and SHALL be sent when the connection returns.
An item sent twice MUST result in one record.

#### Scenario: On a train without signal

- GIVEN no connection
- WHEN the entrepreneur saves a receipt and an invoice draft
- THEN both show queued on the home screen
- AND once the connection returns both show synced and exist once in shillinq

### Requirement: An invoice is issued from the phone only online (REQ-PMP-004)

The invoice screen SHALL offer Issue only with a connection, through the
normal issue transition.

#### Scenario: Issuing on the road

- GIVEN a synced draft and a connection
- WHEN the entrepreneur presses Issue
- THEN the invoice gets its number and shows issued
