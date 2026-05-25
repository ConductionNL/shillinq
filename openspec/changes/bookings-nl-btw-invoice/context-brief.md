---
status: draft
---

# Booking NL BTW + Kassakoppeling-shape Invoice

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Verkoop / Facturen → Kassakoppeling tab on bookings-generated invoices

**Rationale:** BTW + kassakoppeling-shape compliance for bookings invoices.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Service / product VAT on invoice in NL kassakoppeling-friendly shape.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 14/21 competitors
- **Dependencies:** none

## Competitor Evidence (from intelligence-db)

- acuity-scheduling :: HIPAA Compliance :: HIPAA-grade encryption + BAA (Premium only)
- booksy :: Mobile Payments :: Tap-to-pay, card reader, mobile POS
- boulevard :: Integrated Payments :: PCI-compliant card processing built in
- cal-com :: GDPR Compliance :: EU-friendly, self-host option for sovereignty
- dvi-salonsoftware :: BTW 9% diensten + 21% retail + kassakoppeling Belastingdienst :: NL fiscaal compliant; audit log per bon
- easy-appointments :: GDPR Tools :: Data export/delete tools for AVG compliance
- erpnext-pos :: India GST + UK VAT engine; NL custom via Frappe app :: Built-in GST/VAT; community NL kassakoppeling module needed
- fresha :: Integrated POS :: Built-in point-of-sale with card processing
- indico :: SAML/OIDC SSO :: Enterprise SSO including institutional
- korona-cloud :: DE TSE / KassenSichV ready; EU VAT incl. NL :: DE fiscal compliance built-in; NL BTW supported; kassakoppeling-friendly audit log
- lightspeed-retail :: NL BTW 21%/9% + kassakoppeling (NF525-style audit) :: Tax rates per product; audit log per ticket; CCV/PIN integration
- mews :: EU Compliance :: EU VAT, e-invoicing, fiscal compliance
- mews :: Mews POS :: In-property point of sale
- mews-pos :: NL BTW 21%/9% + service charge handling :: EU VAT engine; per-item rate; service charge as separate line
- mindbody :: Payment Processing :: Built-in card processing
- mindbody :: Retail POS :: In-studio retail product sales
- odoo-pos :: EU fiscal compliance modules (BE, FR, DE, IT) :: Country-specific fiscal modules; NL lacks official kassakoppeling - community module
- pretix :: EU Banking Native :: iDEAL, Bancontact, SEPA Direct Debit
- pretix :: GDPR-by-Design :: Built in Germany with strict EU privacy
- pretix :: Invoicing :: PDF invoices with EU VAT handling
- resy :: No Per-Cover Fee :: Flat subscription, no per-reservation charge
- salonized :: AVG Consent :: GDPR/AVG-compliant consent capture for client data
- salonized :: BTW 9% (services) and 21% (retail) handling :: Per-item BTW; supports kappers low/high rate split
- salonized :: Digital Invoice :: Auto-generated digital invoice on checkout, NL BTW
- salonized :: Kassakoppeling / POS :: Integrated cash register, NL kassakoppeling-compliant

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
