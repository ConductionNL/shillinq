# Commitment domain — Dutch → English rename map

Ruben, 2026-08-10: *"that still leaves dutch words like verplichting in code and
schema's WE DO NOT WANT THAT"*. There is **no standardised-term exemption** for
internal code and schemas. BBV/SBR/XBRL/IV3 **wire** field names stay Dutch, but
only inside the export/mapping layer.

Decisions taken: **greenfield — no data migration**, seeds carry the new names.
**Verplichting first**, end to end, as the template for the other 29 schemas.

`Commitment` is not invented: `lib/Service/Commitment/CommitmentMaterialisationService.php`,
`CommitmentMaterialisationListener` and `src/views/BudgetLineCommitments.vue`
already use it for exactly this concept.

## Schemas

| Dutch | English |
|---|---|
| `Verplichting` | `Commitment` |
| `Verplichtingsregel` | `CommitmentLine` |
| `Verplichtingsmutatie` | `CommitmentMovement` |

## Commitment

| Dutch | English |
|---|---|
| `verplichtingsnummer` / `verplichtingNummer` | `commitmentNumber` |
| `bronReferentie` | `sourceReference` |
| `bron` | `source` |
| `soort` | `commitmentType` |
| `aangaandatum` | `commitmentDate` |
| `looptijd_van` / `looptijdStart` | `termStart` |
| `looptijd_tot` / `looptijdEind` | `termEnd` |
| `tegenpartij` | `counterparty` |
| `totaalbedrag_excl_btw` | `totalAmountExclVat` |
| `totaalbedrag_incl_btw` | `totalAmountInclVat` |
| `bedrag` | `amount` |
| `valuta` | `currency` |
| `btw_regime` | `vatRegime` |
| `mandaat_toegepast` | `mandateApplied` |
| `override_reden` | `overrideReason` |
| `interne_kenmerk` | `internalReference` |
| `rechtmatigheidstoetsen` | `lawfulnessChecks` |
| `documenten` | `documents` |
| `omschrijving` | `description` |
| `kostenplaats` | `costCentre` |
| `grootboekrekening` | `glAccount` |
| `gegundeLeverancierKvk` | `awardedSupplierCocNumber` |
| `mijlpalen` | `milestones` |

`commitmentType` enum: `inkooporder`→`purchaseOrder`, `raamovereenkomst`→`frameworkAgreement`,
`arbeidscontract`→`employmentContract`, `subsidiebeschikking`→`grantDecision`,
`huurovereenkomst`→`rentalAgreement`, `leasing`→`lease`, `overig`→`other`.

`status` enum: `concept`→`draft`, `in_goedkeuring`→`pendingApproval`,
`aangegaan`→`committed`, `deels_geleverd`→`partiallyDelivered`,
`deels_gefactureerd`→`partiallyInvoiced`, `deels_betaald`→`partiallyPaid`,
`afgesloten`→`closed`, `geannuleerd`→`cancelled`.

`vatRegime` enum: `standaard`→`standard`, `verlegd`→`reverseCharge`, `vrijgesteld`→`exempt`.

## CommitmentLine

| Dutch | English |
|---|---|
| `verplichting` | `commitment` |
| `regelnummer` | `lineNumber` |
| `boekjaar` | `fiscalYear` |
| `bedrag_excl_btw` | `amountExclVat` |
| `bedrag_incl_btw` | `amountInclVat` |
| `programma` | `programme` |
| `btw_code` | `vatCode` |
| `verwacht_geleverd_op` | `expectedDeliveryDate` |
| `geleverd_bedrag` | `deliveredAmount` |
| `gefactureerd_bedrag` | `invoicedAmount` |
| `betaald_bedrag` | `paidAmount` |
| `restant_verplicht` | `remainingCommitted` |
| `afgesloten` | `closed` |

## CommitmentMovement

| Dutch | English |
|---|---|
| `verplichting` | `commitment` |
| `verplichtingsregel` | `commitmentLine` |
| `datum` | `date` |
| `soort` | `movementType` |
| `toelichting` | `notes` |
| `gerelateerde_factuur` | `relatedInvoice` |
| `gerelateerde_betaling` | `relatedPayment` |
| `journaalpost` | `journalEntry` |
| `gebruiker` | `user` |

`movementType` enum: `aangegaan`→`committed`, `verhoogd`→`increased`,
`verlaagd`→`decreased`, `prestatie_ontvangen`→`performanceReceived`,
`gefactureerd`→`invoiced`, `betaald`→`paid`, `afgesloten`→`closed`,
`geannuleerd`→`cancelled`, `teruggevorderd`→`reclaimed`.

⚠️ `soort` maps to **two different** English names depending on the schema
(`commitmentType` on Commitment, `movementType` on CommitmentMovement). A blind
global replace of `soort` is wrong.

## Also in scope (same fragment pair)

`20-bookkeeping-tenderned-integratie.json` contributes additive properties to
`Commitment` and must use the same names. Its own `TenderNedAanbesteding` and
`OpdrachtUitvoering` schemas are the TenderNed integration and are renamed in
the follow-up slice, not here.

## Fixed en route (shillinq#485)

Two fragments both declared `Verplichting.required`; ADR-037 concatenates list
values, so the schema demanded **both** vocabularies at once and no payload
could satisfy it. `TenderNedAwardDetectedListener` therefore wrote an object
that was always rejected, and its `catch` is fail-soft — so TenderNed award
detection **silently created nothing**. The TenderNed fragment is now additive
(`required: []`); `bookkeeping-verplichtingenadministratie.json` owns the schema.
