---
sidebar_position: 14
title: AI-assisted Receipt & Bill Capture
description: How photographed receipts and PDF supplier invoices are pre-filled from docudesk's document extraction, with per-field confidence and a mandatory human review.
---

# AI-assisted Receipt & Bill Capture

Photographing a receipt or dropping in a PDF supplier invoice should not
mean re-typing every field by hand. Shillinq consumes docudesk's document
intelligence (ADR-010, ADR-022) to pre-fill a draft with the extracted
values — but a person always confirms before anything is booked. Shillinq
never runs OCR itself and never shows a fabricated field: every value comes
either from docudesk's extraction or from you.

## Goal

By the end of this guide you will understand how an extraction draft
appears, what the confidence badges mean, how to correct a field, and how
to ask docudesk to try again.

## Prerequisites

- A Nextcloud account with **Shillinq** and **docudesk** both installed and
  enabled.
- A receipt photo or supplier-invoice PDF already stored in docudesk (via
  the normal upload/scan flow).

## Section 1 — How a draft appears

When docudesk finishes extracting a document it notifies shillinq, which
creates an **uncommitted draft**:

- A photographed receipt becomes a **Receipt** draft under **Receipts**.
- A PDF supplier invoice becomes a pending entry in the **Import bill**
  dialog's upload step, under "Extracted fields".

Nothing is booked yet — the draft only becomes a real bill or expense once
you review and save it.

## Section 2 — Reading the confidence badges

Every field the extraction touched shows a badge: a percentage plus a
short label — never colour alone, so the status is always readable.

| Badge | Meaning |
|---|---|
| **Extracted** | docudesk populated this field; confidence is at or above the review threshold. |
| **Needs review** | Confidence is below the review threshold (80%) — double-check this value before saving. |
| **Corrected** | You already edited this field; your value is what will be saved. |
| **Manual** | This field is not something docudesk extracts (e.g. the GL account) — you always fill it in. |

When the draft's **overall** confidence is 90% or higher, saving can be a
single click. Below that, you are asked to look over the fields first. In
both cases **saving is always an explicit action you take** — a high
confidence score never books anything automatically.

## Section 3 — Correcting a field

Every extracted field is a normal, editable input. Change whatever is
wrong and click **Save**. Shillinq records, on the server, which fields you
changed — the original extracted value and its confidence stay available
for audit even after your correction, they are never silently discarded.

## Section 4 — Asking docudesk to try again

If a draft's confidence is low (for example a blurry photo), open the
draft and click **Request extraction**. This asks docudesk to re-run
extraction on the same source document; once it completes, the draft
updates in place with the new values — no duplicate record is created.

## Section 5 — When docudesk has no result

If you upload a PDF directly into the **Import bill** dialog and no
docudesk extraction is available for it, the dialog tells you honestly
that PDF extraction is not available yet and asks for a UBL/e-invoice XML
or CSV file instead — it never shows made-up "extracted" values.

## Troubleshooting

| Problem | Cause | Solution |
|---|---|---|
| No draft appears after scanning | docudesk has not finished, or `callbackEvent` was not requested | Wait a moment, or use **Request extraction** on the source document. |
| "Request extraction" shows an error | docudesk is unreachable or not installed | Retry later; the existing UBL/CSV import paths are unaffected. |
| A field stays empty after extraction | docudesk could not determine that value | Fill it in manually — the field will show as **Manual**/**Corrected**. |

## Related documentation

- [Journal entries](./journals.md)
- [Matching rules](./matching-rules.md)
