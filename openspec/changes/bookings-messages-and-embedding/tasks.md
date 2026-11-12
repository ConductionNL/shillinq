# Tasks: bookings-messages-and-embedding

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Build

- [ ] 1.1 Send the confirmation request in `lib/Service/Booking/ConfirmationTokenService.php` through `OCP\Mail\IMailer` instead of the integriq lookup, and record the send (REQ-BCF-013). Verify: PHPUnit with a mailer double asserting the attachment.
- [ ] 1.2 Send reminders and the other booking mails in `BookingNotificationService` and `ConfirmationMailer` through `IMailer` (REQ-BET-011). Verify: PHPUnit per template type.
- [ ] 1.3 A job that calls `SmsReminderDispatcher` for due reminders, and a not connected state while the provider is the log adapter (REQ-SMS-021). Verify: PHPUnit; `appinfo/info.xml` lists the job.
- [ ] 1.4 Repeat option on the booking form calling `/api/v1/appointment-series` (REQ-BRS-004). Verify: Vitest; Playwright books a weekly series.
- [ ] 1.5 CORS preflight route and allowed origins per widget key in `WidgetApiController` (REQ-WSW-011). Verify: PHPUnit for an allowed and a refused origin; route-auth gate.
- [ ] 1.6 English and Dutch strings, then live: book from a page on another origin and see the confirmation mail arrive.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
