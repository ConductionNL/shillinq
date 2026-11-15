---
kind: code
---

# Proposal: bookings-messages-and-embedding

## Summary

Bookings keep templates, channels and a booking widget, but no message reaches a customer and the widget does not work on another website. The confirmation request and the booking mails go to integriq classes and routes that do not exist, the SMS dispatcher has no caller and its provider is the log adapter, the recurring series endpoint has no page, and the widget API sets no CORS headers. This change sends the messages through Nextcloud's mailer and the configured SMS provider, offers a repeat option, and makes the widget work cross-origin.

## Motivation

Capability rows `bok-confirm`, `bok-email`, `bok-sms`, `bok-recurring`, `bok-online` were marked built. The closing round of 12 Nov 2026 (decision 389) found that the code does not do what the rows say, so they go back to specified with this change as their plan.

## Affected projects

- shillinq only.
