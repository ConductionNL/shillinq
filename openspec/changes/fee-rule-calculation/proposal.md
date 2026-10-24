# Proposal: fee-rule-calculation

## Why

A legesverordening sets out more than flat amounts. Dossiq's VTH settings (DqVthInstellingen) list three Zuiddrecht rules:

- "Bouwen, per euro bouwkosten": 2,35 % of the building costs, at least € 125;
- "Kappen": € 82,40 per tree;
- "Intrekken binnen 4 weken": 75 % refunded.

A FeeSchedule holds one amount for one case type value and has no name, so none of these three can be written. Kap and bouw applications also share the Omgevingsvergunning case type and differ only in their variant.

## What changes

FeeSchedule 0.3.0 gains:

- `name`: the rule in words.
- `variantProperty` and `variantValue`: a fee for one route of a type. The variant is part of the schedule's tuple, and a fee for the object's own variant wins over the type's fee.
- `calculation`: one of `fixed` (the default and the old behaviour), `percentage` with `percentage`, `minimumAmount` and `baseProperty`, `per_unit` with `unitPrice`, `unitLabel` and `baseProperty`, or `refund_percentage` with `percentage`.

`FeeCalculation` refuses a schedule that lacks the numbers its kind needs, and works out the amount for an object. If the object does not carry its base value yet, the amount is left off with `amountSource` `needs-base`, rather than charged as zero. A refund carries no intake amount.
