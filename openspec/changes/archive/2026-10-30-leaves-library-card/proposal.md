# leaves-library-card

## Why

On a case the payment-requests and contract leaves drew no card of their own, and DqZaak lists them inside the
"Gekoppeld" side card ("Leges en betalingen, contract en projecten: niets gekoppeld"). nextcloud-vue now hosts a leaf in
the library card (integration-leaf-card, `chrome: "card"` + `onHeader`) and lists leaves in a `linked` card
(linked-integrations-card, `onSummary`).

## What changes

- Both descriptors declare `chrome: "card"`.
- Given `onHeader`, a panel reports its count and empty state and drops its own empty line.
- Given `onSummary`, a panel draws nothing and reports its count, its noun ("leges en betalingen", "contract") and a row
  per item. Nothing is reported while loading or after a failed read.
