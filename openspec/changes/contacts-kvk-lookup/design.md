# Design: contacts-kvk-lookup

Read at shillinq development `ed143e2f9`, integriq and openregister development
on 2026-10-07. Shillinq is not a canvas app and no board draws this form; the
fields sit where the schema-driven create and edit forms of `CustomerDetail`
and `PayeeDetail` already put them.

## Decisions

### D1. Mode `default`, not `live`

A customer record in bookkeeping is the counterparty as it was when invoiced.
An invoice from March must keep the March name, and a dunning letter must not
change its addressee because the KvK changed overnight. Mode `default` fills a
starting value the user may change and stores it; mode `live` would read the
register each time the field shows. Alternative rejected: `live` on
`kvkNumber`. It would make the stored legal name of a posted invoice depend on
a remote register.

### D2. The fill map lives in `config.fill`

`PropertySourceDeclaration` passes `config` to the provider untouched and
validates only `provider` and `mode`. The map from the KvK record to sibling
properties is shillinq knowledge, so it sits in shillinq's declaration:

```json
"kvkNumber": {
  "type": "string",
  "nullable": true,
  "title": "KvK Number",
  "x-openregister-property-source": {
    "provider": "kvk",
    "mode": "default",
    "config": {
      "fill": {
        "legalName": "naam",
        "tradeName": "handelsnamen[0].naam"
      }
    }
  }
}
```

For `Payee`: `name` from `naam`, `tradingName` from `handelsnamen[0].naam`,
`address.street` from `_embedded.hoofdvestiging.adressen[0].straatnaam`,
`address.houseNumber` from `...huisnummer` with `huisletter` and
`huisnummerToevoeging` appended, `address.postcode`, `address.city` from
`plaats`, `address.country` fixed `NL`. `CustomerMaster` has no address
property; it fills names only.

The key name `fill` is a proposal to the renderer. If nextcloud-vue settles on
another name when it builds the renderer, this change follows it: the decision
is that the map is declared, not what the key is called.

### D3. The connection

`lib/Settings/connections.json` entry `kvk`: drop `available: false` and
`unavailableMessage`, keep `sourceTemplate: "kvk"`, add `unconfiguredMessage`
"Not set up yet. Link the KvK source in Integriq to look companies up while
adding a customer or supplier." Integriq's `ConnectionsController::link()`
creates the source from the template; no shillinq code calls the KvK.

### D4. When the lookup cannot answer

No source linked, or the KvK down: the field is a plain text field and the
form says why ("KvK lookup is not set up" or "The KvK did not answer; type the
number"). The record saves either way. This is the renderer's behaviour, and the
scenarios below are what shillinq asks of it.

## Data

Only declarations change: two properties gain the key. No new property, no
migration. `kvkNumber` keeps its type; no pattern is added in this change,
because existing records may hold numbers that a pattern would reject on their
next save.

## Inherited finding

The `Customers` index columns name `customerNumber` and `name`, which
`CustomerMaster` does not declare (it has `customerId` and `legalName`). Not
fixed here; reported in the PR body.
