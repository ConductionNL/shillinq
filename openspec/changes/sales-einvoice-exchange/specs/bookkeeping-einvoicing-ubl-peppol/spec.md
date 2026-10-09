# bookkeeping-einvoicing-ubl-peppol Specification (delta)

## Purpose

An e-invoice shillinq sends is handed to integriq on the channel integriq
consumes, its delivery status and any rejection come back to the invoice, and
a self-billed invoice a customer sends over Peppol becomes a sales invoice to
review. From shillinq matrix rows `sal-peppol-send`, `sal-einvoice-rejection`
and `sal-self-billing`. This delta replaces the transport channel that
REQ-EINV-005 describes.

## ADDED Requirements

### Requirement: Sending hands the invoice to integriq on its CloudEvent register (REQ-SEIX-001)

When a user sends an issued `ARInvoice` as an e-invoice, shillinq SHALL save one
object in integriq's register, schema `event`, of type
`nl.conduction.peppol.outbound.requested`, whose `data` carries `sourceApp`,
`objectUri`, `recipientPeppolId`, `documentType` and `payloadFileUri`. The
invoice MUST go to `deliveryStatus` `queued` only after that object was saved,
and the invoice path MUST NOT call the log-only local transmission port.

#### Scenario: A bookkeeper sends an e-invoice to a municipality

- GIVEN a bookkeeper on the AR invoice page of issued invoice 2026-0420 for Gemeente Voorbeeld, which has a valid Peppol participant id
- WHEN they choose Send e-invoice
- THEN integriq's event register holds one outbound request naming invoice 2026-0420 and its UBL file
- AND the page shows delivery status queued

#### Scenario: Without integriq nothing pretends to be queued

- GIVEN an instance without integriq installed
- WHEN the bookkeeper chooses Send e-invoice on issued invoice 2026-0420
- THEN the page says Peppol sending is not available on this instance and offers Send by email
- AND the invoice stays at delivery status not-sent

### Requirement: The payload named in the hand-off is a stored file (REQ-SEIX-002)

Before the hand-off, shillinq SHALL store the invoice's UBL XML and hybrid PDF
in the administration's Nextcloud Files, and `payloadFileUri` MUST reference
the stored XML. A placeholder reference that resolves to nothing MUST NOT be
written.

#### Scenario: The e-invoice file can be opened afterwards

- GIVEN invoice 2026-0420 sent as an e-invoice
- WHEN a bookkeeper opens the payload file link on its AR invoice page
- THEN the UBL XML of invoice 2026-0420 opens from the administration's e-invoices folder

### Requirement: Delivery status from integriq updates the invoice (REQ-SEIX-003)

Shillinq SHALL take every `nl.conduction.peppol.delivery.status` CloudEvent
integriq saves in its event register and apply it to the `ARInvoice` its
`objectUri` names, following the existing allowed transitions (`queued` to
`sent`, `failed` or `rejected`; `sent` to `delivered` or `rejected`), and SHALL
write `transmissionId` and `deliveryDetail` from it. A status for an unknown
invoice or an illegal transition MUST be logged and ignored.

#### Scenario: The access point confirms delivery

- GIVEN invoice 2026-0420 at delivery status queued
- WHEN integriq reports sent and then delivered for it
- THEN its AR invoice page shows delivered and the transmission id integriq assigned

### Requirement: A rejected e-invoice reaches the bookkeeper (REQ-SEIX-004)

When an e-invoice is reported `rejected`, shillinq SHALL notify every member
of the invoice's administration whose AdministrationMembership role owns its
receivables (`debiteurenadmin`, `boekhouder`, `controller` or `eigenaar`;
the membership schema has no `ar-controller` role, #1754) through a
notification that Nextcloud can render and that links to the invoice, and the
notification MUST carry the rejection reason. `AccountsReceivable` SHALL offer
a filter that lists rejected e-invoices.

#### Scenario: A municipality rejects an invoice for a missing order number

- GIVEN invoice 2026-0420 sent to Gemeente Voorbeeld
- WHEN integriq reports it rejected with the reason "Ordernummer ontbreekt"
- THEN each member of the administration with one of those roles sees a notification naming invoice 2026-0420 and the reason, which opens its AR invoice page
- AND the Rejected e-invoices filter on the Accounts Receivable page lists invoice 2026-0420

### Requirement: A customer's self-billing agreement is recorded (REQ-SEIX-005)

`CustomerMaster` SHALL carry a `selfBillingAgreement` with the date agreed, the
end date and an optional document reference, editable on `CustomerDetail`, and
`ARInvoice` SHALL declare `selfBilled` and `selfBillingMention`.

#### Scenario: A farm records the agreement with its buyer

- GIVEN the customer page of Aardappelverwerking De Kuil B.V.
- WHEN the bookkeeper records a self-billing agreement from 2026-01-05 to 2026-12-31
- THEN the customer page shows the agreement and its end date

### Requirement: A received self-billed invoice becomes a sales invoice to review (REQ-SEIX-006)

When integriq reports an inbound document whose UBL is a self-billed invoice,
shillinq SHALL match the seller party to an administration and the buyer party
to a `CustomerMaster` with a current self-billing agreement, and SHALL write an
`ARInvoice` in `draft` with `selfBilled` true, the mention "Factuur uitgereikt
door afnemer", the lines and VAT from the document and the customer's own
invoice number, once per sender and number. Without a match or a current
agreement it MUST NOT write an invoice and SHALL record a refusal with the
reason. Any other inbound invoice SHALL go to the supplier invoice intake.

#### Scenario: A potato processor self-bills a delivery

- GIVEN Akkerbouwbedrijf Jansen with a current agreement for Aardappelverwerking De Kuil B.V.
- WHEN De Kuil sends self-billed invoice SB-2026-00042 over Peppol for 30,000 kg at EUR 6,000 plus 9 percent VAT
- THEN the Accounts Receivable page lists a draft invoice of EUR 6,540 for De Kuil marked as issued by the customer, with number SB-2026-00042

#### Scenario: A self-billed invoice without an agreement is refused

- GIVEN a customer without a self-billing agreement
- WHEN a self-billed invoice from that customer arrives
- THEN no invoice is written
- AND a refusal naming the sender and the reason no agreement is recorded is listed for the bookkeeper

### Requirement: A self-billed invoice is booked only after review (REQ-SEIX-007)

A draft `ARInvoice` with `selfBilled` true SHALL be issued only by a user on
`ARInvoiceDetail`, and the `issue` transition MUST refuse it when the
customer's agreement is absent or expired on the invoice date. The user MAY
reject it with a reason instead, which SHALL leave it unbooked.

#### Scenario: The bookkeeper accepts the self-billed invoice

- GIVEN draft self-billed invoice SB-2026-00042 that matches the delivery
- WHEN the bookkeeper issues it on its AR invoice page
- THEN it shows state issued and a posted general ledger transaction for EUR 6,540
