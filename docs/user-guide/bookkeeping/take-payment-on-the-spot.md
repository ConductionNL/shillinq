---
sidebar_position: 11
title: Take a payment on the spot
description: Let a customer pay an invoice on your own phone through your payment provider, with a QR code when the phone cannot take it.
---

# Take a payment on the spot

With **Take payment now** a customer pays an open invoice on the spot, on your own phone, through your payment provider's point-of-sale app. No card terminal is needed.

## Register your phone once

Register your phone as a **payment device**: the provider's id of the phone or terminal (you find it in the provider's app), a name you recognise it by, and optionally the administration it takes payments for. A device without an administration takes payments for every administration you work in.

## Take the payment

1. Open the invoice. It must be issued and not yet paid.
2. Choose **Take payment now**. Shillinq asks the provider, through Integriq, for a payment of the open amount on your phone.
3. The customer taps their card or phone on yours.
4. The capture comes back as an ordinary provider payment: the invoice is marked paid and the capture is booked.

When the provider cannot send the payment to your phone, Shillinq starts an ordinary checkout instead and shows it as a QR code for the customer to scan.

## When it is refused

| Message | What to do |
|---------|-----------|
| Register your phone as a payment device first | Register your phone, as above. |
| The payment provider is not connected yet | An administrator connects the payment source in Integriq. |
| Only an issued invoice that is not paid can be paid | The invoice is a draft, already paid or written off. |
