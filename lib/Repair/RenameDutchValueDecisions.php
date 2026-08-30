<?php

/**
 * Repair step decisions — translate stored Dutch enum VALUES to English.
 *
 * Every predicate this migration needs, with no database in sight. The step
 * itself (RenameDutchValues) reaches storage through ValueMigrationPort, so
 * everything that can be got wrong is exercised here by ordinary unit tests.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

/**
 * Pure predicates for the Dutch-to-English value migration.
 */
class RenameDutchValueDecisions
{

    /**
     * Property => stored Dutch value => English replacement.
     *
     * Keyed by the PROPERTY that declares the enum, never by the word alone:
     * `provincie` is an organisationType here and part of a proper name there,
     * and a word-scoped rewrite corrupts the second.
     *
     * @var array<string, array<string, string>>
     */
    public const VALUE_MAP = [
        'thresholdStatus' => [
            'OP_KOERS' => 'ON_RATE',
            'RISICO' => 'RISK',
            'KRITIEK' => 'CRITICAL',
            'BEHAALD' => 'ACHIEVED',
        ],
        'category' => [
            'BILLABLE_KLANTWERK' => 'BILLABLE_CLIENT_WORK',
            'ACQUISITIE' => 'ACQUISITION',
            'ADMINISTRATIE' => 'ADMINISTRATION',
            'REISTIJD_ZAKELIJK' => 'TRAVEL_TIME_BUSINESS',
            'SCHOLING' => 'TRAINING',
            'FICTIE_ZEZ' => 'FICTION_ZEZ',
            'LEVERANCIER' => 'SUPPLIER',
            'BTW' => 'VAT',
            'INVESTERING' => 'INVESTMENT',
            'OVERIG' => 'OTHER',
            'RECURRING_HUUR' => 'RECURRING_RENT',
            'RECURRING_VERZEKERING' => 'RECURRING_INSURANCE',
            'RECURRING_ABONNEMENTEN' => 'RECURRING_SUBSCRIPTIONS',
            'RECURRING_DGA_LOON' => 'RECURRING_DGA_PAY',
            'RECURRING_LIJFRENTEPREMIE' => 'RECURRING_ANNUITY_PREMIUM',
            'RECURRING_OVERIG' => 'RECURRING_OTHER',
            'standaard' => 'standard',
            'vrijgesteld' => 'exempt',
        ],
        'code' => [
            'BILLABLE_KLANTWERK' => 'BILLABLE_CLIENT_WORK',
            'ACQUISITIE' => 'ACQUISITION',
            'ADMINISTRATIE' => 'ADMINISTRATION',
            'REISTIJD_ZAKELIJK' => 'TRAVEL_TIME_BUSINESS',
            'SCHOLING' => 'TRAINING',
            'FICTIE_ZEZ' => 'FICTION_ZEZ',
        ],
        'type' => [
            'KWARTAAL_EINDE' => 'QUARTER_END',
            'PROGNOSE_RISICO_DROP' => 'FORECAST_RISK_DROP',
            'OMSLAG_RISICO' => 'APPORTIONMENT_RISK',
            'OMSLAG_KRITIEK' => 'APPORTIONMENT_CRITICAL',
            'HANDELSRENTE_B2B_6_119A_BW' => 'COMMERCIAL_INTEREST_B2_B_6_119_A_BW',
            'WETTELIJKE_RENTE_B2C_6_119_BW' => 'STATUTORY_INTEREST_B2_C_6_119_BW',
            'eliminatie-afschrijving' => 'elimination-depreciation',
            'eliminatie-voorzieningdotatie' => 'elimination-provision-contribution',
            'eliminatie-onttrekking-reserve' => 'elimination-withdrawal-reserve',
            'toevoeging-bruto-investering' => 'addition-gross-investment',
            'toevoeging-aflossing' => 'addition-repayment',
            'eliminatie-boekwinst-desinvestering' => 'elimination-book-profit-divestment',
            'correctie-transactiemoment' => 'correction-transaction-moment',
            'intercompany-eliminatie' => 'intercompany-elimination',
            'OVERSCHRIJDING' => 'OVERRUN',
            'VRIJWILLIG_NA_LOCKOUT' => 'VOLUNTARY_AFTER_LOCKOUT',
            'bezwaar' => 'objection',
            'beroep' => 'appeal',
            'hoger-beroep' => 'higher-appeal',
            'cassatie' => 'cassation',
            'FACTUURFREQUENTIE_LIJKT_OP_LOON' => 'INVOICE_FREQUENCY_APPEARS_ON_PAY',
            'CONCENTRATIE_WAARSCHUWING' => 'CONCENTRATION_WARNING',
            'LANGJARIGE_HOOFDRELATIE' => 'MULTI_YEAR_MAIN_RELATION',
            'VBAR_GRENS_ONDERSCHREDEN' => 'VBAR_GRENS_BELOW_THRESHOLD',
            'VERVANGBAARHEID_THEORETISCH' => 'REPLACEABILITY_THEORETICAL',
            'MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN' => 'MULTIPLE_ENGAGEMENT_SAME_CONCERN',
            'ICT_INTEGRATIE_IN_TEAM' => 'ICT_INTEGRATION_IN_TEAM',
            'MODELOVEREENKOMST_VERLOPEN' => 'MODELAGREEMENT_EXPIRED',
            'HERBEOORDELING_OVERDUE' => 'REASSESSMENT_OVERDUE',
            'WBA_VERLOPEN' => 'WBA_EXPIRED',
            'GETEKENDE_OVEREENKOMST' => 'SIGNED_AGREEMENT',
            'FACTUUR_EERSTE' => 'INVOICE_FIRST',
            'FACTUUR_LAATSTE' => 'INVOICE_LAST',
            'FACTUUR_TUSSENTIJDS' => 'INVOICE_INTERIM',
            'URENSTAAT_KWARTAAL' => 'TIMESHEET_QUARTER',
            'WBA_UITKOMST' => 'WBA_OUTCOME',
            'CORRECTIE_BRIEF' => 'CORRECTION_BRIEF',
            'INTERN_MEMO' => 'INTERNAL_MEMO',
            'OVERIG' => 'OTHER',
            'BUFFER_ONDERSCHRIJDING' => 'BUFFER_SHORTFALL',
            'NEGATIEF_SALDO' => 'NEGATIVE_BALANCE',
            'VOORALARM' => 'PRE_ALERT',
            'Lokale_heffingen' => 'local_levies',
            'Weerstandsvermogen' => 'Resilience',
            'Onderhoud_kapitaalgoederen' => 'maintenance_capital_goods',
            'Financiering' => 'Financing',
            'Bedrijfsvoering' => 'Operations',
            'Verbonden_partijen' => 'affiliated_parties',
            'Grondbeleid' => 'LandPolicy',
        ],
        'taxReturnType' => [
            'P_FORMULIER' => 'P_FORM',
            'W_FORMULIER' => 'W_FORM',
            'M_FORMULIER' => 'M_FORM',
            'C_FORMULIER' => 'C_FORM',
            'CORRECTIE_SUPPLETIE' => 'CORRECTION_SUPPLEMENT',
        ],
        'schemeUntilBusiness' => [
            'FORFAIT_BIJTELLING' => 'FLAT_RATE_BENEFITINKIND',
        ],
        'method' => [
            'WERKELIJK' => 'ACTUAL',
            'FORFAIT_OVERBRUGGINGSWET' => 'FLAT_RATE_BRIDGING_ACT',
            'forfaitair_25pct' => 'flat_rate_25pct',
        ],
        'organisationType' => [
            'provincie' => 'province',
            'waterschap' => 'waterAuthority',
            'GR-waterkwaliteit' => 'gr-water_quality',
            'GR-overig' => 'gr-other',
        ],
        'decisionType' => [
            'AB-besluit' => 'ab-decision',
        ],
        'materialityBase' => [
            'balanstotaal' => 'balanceSheetTotal',
        ],
        'findingType' => [
            'onzekerheid' => 'uncertainty',
        ],
        'channel' => [
            'EMAIL+POSTREGISTRATIE' => 'eMAILPostRegistration',
            'AANGETEKENDE_POST' => 'REGISTERED_POST',
            'INCASSOBUREAU_API' => 'COLLECTION_AGENCY_API',
        ],
        'periodType' => [
            'kwartaal-emu-saldo' => 'quarter-emu-balance',
            'jaar-emu-saldo' => 'year-emu-balance',
            'jaar-emu-schuld' => 'year-emu-debt',
            '4WEKEN' => '4_WEEKS',
            'MAAND' => 'MONTH',
        ],
        'direction' => [
            'saldo-verhogend' => 'balance-increasing',
            'saldo-verlagend' => 'balance-decreasing',
            'saldo-neutraal' => 'balance-neutral',
        ],
        'rjVariant' => [
            'RJ-onverkort' => 'rj-in_full',
            'IFRS-volledig' => 'ifrs-complete',
        ],
        'event_type' => [
            'DoorsnijdingsVerbod.check_run' => 'cross_cutting_prohibition_check_run',
            'ForfaitairCap.applied' => 'flatRateCapApplied',
        ],
        'prognoseStatus' => [
            'ONDER_DREMPEL' => 'UNDER_THRESHOLD',
            'WAARSCHUWING' => 'WARNING',
            'OVERSCHRIJDING_VERWACHT' => 'OVERRUN_EXPECTED',
        ],
        'trigger' => [
            'DREMPEL_80PCT' => 'THRESHOLD_80_PCT',
            'DREMPEL_90PCT' => 'THRESHOLD_90_PCT',
            'DREMPEL_100PCT' => 'THRESHOLD_100_PCT',
        ],
        'severity' => [
            'VROEG' => 'EARLY',
            'KRITIEK' => 'CRITICAL',
            'OVERSCHRIJDING' => 'OVERRUN',
            'LAAG' => 'LOW',
            'MIDDEN' => 'MEDIUM',
            'HOOG' => 'HIGH',
        ],
        'newRegime' => [
            'REGULIER_BTW' => 'REGULAR_VAT',
        ],
        'format' => [
            'ACM-standaardformulier-mo-2024' => 'acm-standard_form-mo-2024',
        ],
        'eventType' => [
            'ikp-definitief-signed' => 'ikp-final-signed',
        ],
        'entityType' => [
            'AlgemeenBelangBesluit' => 'GeneralInterestDecision',
            'provincie' => 'province',
            'waterschap' => 'waterAuthority',
        ],
        'vatFilingFrequency' => [
            'maand' => 'month',
        ],
        'vatTreatment' => [
            'standaard' => 'standard',
            'fiscale_eenheid_geen_btw' => 'fiscal_unit_none_vat',
            'terugvorderbaar' => 'recoverable',
            'niet_terugvorderbaar' => 'non_recoverable',
        ],
        'fiscalTreatment' => [
            'met_realisatie' => 'with_actuals',
            'fiscale_eenheid' => 'fiscal_unit',
        ],
        'awfRate' => [
            'LAAG' => 'LOW',
            'HOOG' => 'HIGH',
        ],
        'zvwRate' => [
            'LAAG' => 'LOW',
            'HOOG' => 'HIGH',
        ],
        'premieGroupWGF' => [
            'AWF_LAAG' => 'AWF_LOW',
            'AWF_HOOG' => 'AWF_HIGH',
        ],
        'period' => [
            '4WEKEN' => '4_WEEKS',
            'MAAND' => 'MONTH',
            'JAAR' => 'YEAR',
        ],
        'regulatoryFramework' => [
            'Pensioenwet' => 'PensionsAct',
            'vrijgesteld' => 'exempt',
            'IORP-II-buitenland' => 'iorp-ii-abroad',
        ],
        'toetstype' => [
            'automatisch' => 'automatic',
            'handmatig' => 'manual',
        ],
        'kind' => [
            'fout' => 'error',
            'onzekerheid' => 'uncertainty',
            'leverancier' => 'supplier',
            'werknemer' => 'employee',
            'subsidieontvanger' => 'grantRecipient',
            'verhuurder' => 'landlord',
            'overig' => 'other',
            'aangegaan' => 'committed',
            'verhoogd' => 'increased',
            'verlaagd' => 'decreased',
            'prestatie_ontvangen' => 'performance_received',
            'gefactureerd' => 'invoiced',
            'betaald' => 'paid',
            'afgesloten' => 'closed',
            'geannuleerd' => 'cancelled',
            'teruggevorderd' => 'reclaimed',
            // Commitment.kind (tranche 2). CommitmentMovement.kind above shares
            // this column; the two vocabularies are disjoint, so one bucket is
            // safe. Mandate.kind_commitment holds the SAME four values but in a
            // JSON ARRAY, and `rewrite()` is an equality UPDATE — see the note
            // on kind_commitment below.
            'inkooporder' => 'purchase_order',
            'arbeidscontract' => 'employment_contract',
            'subsidiebeschikking' => 'grant_decision',
            'huurovereenkomst' => 'lease_agreement',
        ],
        // NO 'kind_commitment' BUCKET, DELIBERATELY. Mandate.kind_commitment is
        // an ARRAY of the Commitment.kind values renamed just above, and
        // ValueMigrationPort::rewrite() is `UPDATE t SET c = ? WHERE c = ?` —
        // an equality match on the whole cell. Against a stored `["inkooporder",
        // "leasing"]` it matches nothing and returns 0, so the step would report
        // success having migrated no mandate at all. Adding the entry anyway
        // would be worse than leaving it out: it would look like the rename was
        // covered. Stored Mandate rows therefore need a separate array-aware
        // repair step (read-modify-write of the JSON list) before
        // MandateEnforcer::mandateApplies() will match them again; the schema
        // and both seed sources already carry the English values.
        'state' => [
            'geboekt' => 'posted',
            'ingediend_bij_MA' => 'submitted_at_ma',
            'betaald_door_EC' => 'paid_by_ec',
            'aanvraag' => 'request',
            'verleend' => 'granted',
            'vastgesteld' => 'determined',
            'teruggevorderd' => 'reclaimed',
            'afgehandeld' => 'handled',
            'INTAKE_VEREIST' => 'INTAKE_REQUIRED',
            'INTAKE_VOLTOOID' => 'INTAKE_COMPLETED',
            'ACTIEF' => 'ACTIVE',
            'BEEINDIGD' => 'ENDED',
            'offerte' => 'quote',
            'afgesloten' => 'closed',
            'uitbetaald' => 'disbursed',
            'definitief' => 'final',
        ],
        'detectionSource' => [
            'interne_audit' => 'internal_audit',
            'externe_audit' => 'external_audit',
            'klacht' => 'complaint',
            'DG_REGIO' => 'DG_REGION',
        ],
        'scope' => [
            'administratie' => 'administration',
        ],
        'rapportageBasis' => [
            'RJ-commercieel' => 'rj-commercial',
            'RJ-fiscaal' => 'rj-fiscal',
        ],
        'side' => [
            'activa' => 'assets',
            'passiva' => 'liabilities',
        ],
        'model' => [
            'A-categorisch' => 'a-categorical',
            'E-functioneel' => 'e-functional',
        ],
        'provisionType' => [
            'pensioen' => 'pension',
            'jubileum' => 'anniversary',
            'herstructurering' => 'restructuring',
            'garantie' => 'guarantee',
            'milieu' => 'environment',
            'onderhoud' => 'maintenance',
            'overig' => 'other',
        ],
        'methodType' => [
            'forfaitair' => 'flatRate',
            'werkelijke-winst' => 'actual-profit',
        ],
        'riskAppetite' => [
            'laag' => 'low',
            'hoog' => 'high',
        ],
        'intakeStatus' => [
            'INTAKE_VEREIST' => 'INTAKE_REQUIRED',
            'INTAKE_VOLTOOID' => 'INTAKE_COMPLETED',
            'ACTIEF' => 'ACTIVE',
            'BEEINDIGD' => 'ENDED',
        ],
        'branchekader' => [
            'GEEN' => 'NONE',
            'ZORG' => 'CARE',
            'BOUW' => 'CONSTRUCTION',
            'ONDERWIJS' => 'EDUCATION',
        ],
        'source' => [
            'BELASTINGDIENST_GOEDGEKEURD' => 'TAXAUTHORITY_APPROVED',
            'EIGEN_VARIANT' => 'OWN_VARIANT',
            'BRANCHE_VERENIGING' => 'SECTOR_ASSOCIATION',
        ],
        'overallRisk' => [
            'LAAG' => 'LOW',
            'MIDDEN' => 'MEDIUM',
            'HOOG' => 'HIGH',
        ],
        'bufferStatus' => [
            'BOVEN_BUFFER' => 'ABOVE_BUFFER',
            'VOORALARM' => 'PRE_ALERT',
        ],
        'paymentMethod' => [
            'AUTOMATISCHE_INCASSO_SEPA' => 'AUTOMATIC_COLLECTION_SEPA',
            'OVERIG' => 'OTHER',
        ],
        'frequency' => [
            'WEKELIJKS' => 'WEEKLY',
            'TWEEWEKELIJKS' => 'FORTNIGHTLY',
            'MAANDELIJKS' => 'MONTHLY',
            'KWARTAALS' => 'QUARTERLY',
            'JAARLIJKS' => 'ANNUALLY',
        ],
        'policy' => [
            'MIN_MONTHS_VASTE_KOSTEN' => 'MIN_MONTHS_FIXED_COST',
        ],
        'verdelingsType' => [
            'inwoner-aantal' => 'resident-count',
            'gewogen-oppervlak' => 'weighted-area',
        ],
        'bbvVariant' => [
            'waterschap' => 'waterAuthority',
            'provincie' => 'province',
        ],
        'targetDimension' => [
            'kosten-drager' => 'cost-carrier',
        ],
        'iv3Bucket' => [
            'reserves-toevoeging' => 'reserves-addition',
            'reserves-onttrekking' => 'reserves-withdrawal',
        ],
        'route' => [
            'forfaitair' => 'flatRate',
        ],
        'revenueOfExpenses' => [
            'balans' => 'balance',
        ],
        'version' => [
            'begroting' => 'budget',
            'tussenrapportage' => 'interimReport',
        ],
        'overheidslaag' => [
            'provincie' => 'province',
            'waterschap' => 'waterAuthority',
        ],
        'status' => [
            'WAARSCHUWING' => 'WARNING',
            'KRITIEK' => 'CRITICAL',
            'definitief' => 'final',
            'concept' => 'draft',
            'vastgesteld' => 'determined',
            'vrijgesteld' => 'exempt',
            // Commitment.status (tranche 2). Every one of these seven is unique
            // to bookkeeping-verplichtingenadministratie.json across all 100+
            // `status` enums in the register, which is what makes a bucket
            // keyed on the bare property name safe here.
            //
            // ApprovalStep.status is DELIBERATELY ABSENT and must stay absent
            // until its collision is resolved: two of its values,
            // `in_behandeling` and `afgewezen`, are also live in
            // Rechtmatigheidstoets.status, Rechtmatigheidsbevinding.status,
            // JournalEntry.lawfulness.status and ReviewWorkflow's nested step
            // and comment statuses. Those fragments still declare the Dutch
            // enum, so an entry here would rewrite their stored rows and leave
            // schema and data desynced — silently, because an out-of-enum
            // stored value does not error, it just stops matching a filter.
            'in_goedkeuring' => 'in_approval',
            'aangegaan' => 'committed',
            'deels_geleverd' => 'partially_delivered',
            'deels_gefactureerd' => 'partially_invoiced',
            'deels_betaald' => 'partially_paid',
            'afgesloten' => 'closed',
            'geannuleerd' => 'cancelled',
        ],
        'claimType' => [
            'overig' => 'other',
        ],
        'items' => [
            'overig' => 'other',
        ],
        'writtenOffReason' => [
            'overig' => 'other',
        ],
        'legalForm' => [
            'overig' => 'other',
            'provincie' => 'province',
            'waterschap' => 'waterAuthority',
        ],
        'declarationStatus' => [
            'concept' => 'draft',
        ],
        'vatRegime' => [
            'standaard' => 'standard',
            'vrijgesteld' => 'exempt',
        ],
        'assignmentType' => [
            'levering-in-fases' => 'delivery-in-phases',
            'dienstverlening-doorlopend' => 'service-provision-continuous',
        ],
        'largelyCriterium' => [
            'NIET_TOEPASSELIJK' => 'NON_APPLICABLE',
            'GROTENDEELS_ONDERNEMING' => 'LARGELY_ENTERPRISE',
            'NIET_GROTENDEELS_ONDERNEMING' => 'NON_LARGELY_ENTERPRISE',
        ],
        'urgency' => [
            'KRITIEK' => 'CRITICAL',
            'WAARSCHUWING' => 'WARNING',
        ],
        'paymentRiskIndication' => [
            'LAAG' => 'LOW',
            'MIDDEN' => 'MEDIUM',
            'HOOG' => 'HIGH',
        ],
        'riskLevel' => [
            'LAAG' => 'LOW',
            'HOOG' => 'HIGH',
            'LAAG_MIDDEN' => 'LOW_MIDDEN',
            'MIDDEN_HOOG' => 'MIDDEN_HIGH',
            'VERKORT_LAGE_DREMPEL' => 'ABBREVIATED_LOW_THRESHOLD',
        ],
        'submissionChannel' => [
            'DIGID_ZELFSERVICE' => 'DIGID_SELF_SERVICE',
            'PAPIER' => 'PAPER',
        ],
        'allocationKey' => [
            'OPTIMAAL_BEREKEND' => 'OPTIMAL_CALCULATED',
            'HANDMATIG' => 'MANUAL',
            'GEEN' => 'NONE',
            'omzet-aandeel' => 'revenue-share',
            'r-en-d-uren' => 'r-and-d-hours',
        ],
        'benefitInKindCategory' => [
            'REGULIER_22PCT' => 'REGULAR_22_PCT',
        ],
        'riskAssessment' => [
            'geen' => 'none',
            'laag' => 'low',
            'hoog' => 'high',
        ],
        'rvoReportStatus' => [
            'geen' => 'none',
            'definitief' => 'final',
            'verlopen' => 'expired',
        ],
        'reconciliationType' => [
            'btw-ledger-aangifte' => 'vat-ledger-return',
        ],
        'customerGroup' => [
            'OVERHEID' => 'GOVERNMENT',
        ],
        'statutoryEffect' => [
            '14_DAGEN_BRIEF_BIK' => '14_DAYS_BRIEF_BIK',
            'VERZUIM_INTREDEN' => 'DEFAULT_ENTRY',
        ],
        'bbvReconciliationCheck' => [
            'geslaagd' => 'succeeded',
            'mislukt' => 'failed',
            'niet-uitgevoerd' => 'non-executed',
        ],
        'categoryEurostat' => [
            'AF.2-deposits' => 'off_2-deposits',
            'AF.3-securities' => 'off_3-securities',
            'AF.4-loans' => 'off_4-loans',
            'AF.7-derivatives' => 'off_7-derivatives',
            'overig' => 'other',
        ],
        'answerType' => [
            'vrije-tekst' => 'free-text',
        ],
        'type_intangible_asset' => [
            'data-algoritme' => 'data-algorithm',
        ],
        'registrationChannel' => [
            'MIJN_BELASTINGDIENST_ZAKELIJK' => 'MY_TAXAUTHORITY_BUSINESS',
            'MIJN_BELASTINGDIENST_KORUS' => 'MY_TAXAUTHORITY_KORUS',
        ],
        'costPriceMethod' => [
            'integrale-kostprijs-art-25i' => 'integral-costprice-art-25i',
            'kostprijs-monitor-zonder-winstopslag' => 'costprice-monitor-without-profit-markup',
        ],
        'payrollTaxTable' => [
            'WIT_REGULIER' => 'WIT_REGULAR',
            'GROEN_REGULIER' => 'GREEN_REGULAR',
            'WIT_BIJZONDER' => 'WIT_SPECIAL',
            'GROEN_BIJZONDER' => 'GREEN_SPECIAL',
        ],
        'colour' => [
            'WIT' => 'WHITE',
            'GROEN' => 'GREEN',
        ],
        'currentStap' => [
            'concept' => 'draft',
            'vastgesteld' => 'determined',
        ],
        'vat_regime' => [
            'standaard' => 'standard',
            'vrijgesteld' => 'exempt',
            // Commitment.vat_regime (tranche 2). `verlegd` is the BTW reverse
            // charge. The word also appears in other fragments, but only ever
            // under a DIFFERENT property (btwTarief, vatTreatment, prose), and
            // this bucket is scoped to the `vat_regime` column alone.
            'verlegd' => 'reverse_charged',
        ],
        // ApprovalStep.role_required (tranche 2). The only `role_required`
        // column in the register — PurchaseOrder's approval chain spells its
        // equivalent `role`, which this bucket does not touch.
        'role_required' => [
            'budgethouder' => 'budget_holder',
            'teamleider' => 'team_lead',
            'directeur' => 'director',
            // College van B&W, the municipal executive board — not a school.
            'college' => 'municipal_executive',
        ],
        'paymentArrangement' => [
            'maandelijks' => 'monthly',
            'eenmalig' => 'oneOff',
        ],
        'followUpAuthority' => [
            'Rechtbank' => 'DistrictCourt',
            'Hof' => 'Court',
            'HogeRaad' => 'HighCouncil',
        ],
        'wbaAssessmentResult' => [
            'BUITEN_DIENSTBETREKKING' => 'OUTSIDE_EMPLOYMENT',
            'BINNEN_DIENSTBETREKKING' => 'WITHIN_EMPLOYMENT',
            'GEEN_OORDEEL' => 'NONE_OPINION',
            'NIET_VAN_TOEPASSING' => 'NON_FROM_APPLICATION',
        ],
        'perspective' => [
            'OPDRACHTNEMER' => 'CONTRACTOR',
            'OPDRACHTGEVER' => 'CLIENT',
        ],
        'durationRelationship' => [
            'MINDER_DAN_3_MAANDEN' => 'MINDER_DAN_3_MONTHS',
            '3_TOT_6_MAANDEN' => '3_TO_6_MONTHS',
            '6_TOT_12_MAANDEN' => '6_TO_12_MONTHS',
            '1_TOT_2_JAAR' => '1_TO_2_YEAR',
            'MEER_DAN_2_JAAR' => 'MEER_DAN_2_YEAR',
        ],
        'indexationRule' => [
            'CPI_AFGELOPEN_JAAR' => 'CPI_PAST_YEAR',
        ],
        'participantType' => [
            'provincie' => 'province',
            'waterschap' => 'waterAuthority',
        ],
    ];

    /**
     * Convert a property name to the column MagicMapper materialised.
     *
     * Mirrors `MagicMapper::sanitizeColumnName()`, which applies ONLY the
     * ([a-z0-9])([A-Z]) boundary — there is no acronym rule, so `premiesSVWerkgever`
     * becomes `premies_svwerkgever`. Spell it any other way and the migration
     * matches nothing and reports success.
     *
     * @param string $name Property name.
     *
     * @return string Column name.
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function columnFor(string $name): string
    {
        $column = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $column = strtolower((string) $column);
        $column = preg_replace('/[^a-z0-9_]/', '_', $column);
        $column = preg_replace('/_+/', '_', (string) $column);

        return rtrim((string) $column, '_');

    }//end columnFor()

    /**
     * Work out which value rewrites a table actually needs.
     *
     * A property whose column the table does not have is skipped: shard tables
     * are per-schema, so most carry only a few of the mapped columns, and an
     * UPDATE against a missing column is an error rather than a no-op.
     *
     * @param array<string, array<string, string>> $valueMap Property => old => new.
     * @param array<int, string>                   $columns  Columns the table has.
     *
     * @return array<int, array{column: string, old: string, new: string}>
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function plannedRewrites(array $valueMap, array $columns): array
    {
        $planned = [];

        foreach ($valueMap as $property => $values) {
            $column = $this->columnFor(name: $property);
            if (in_array($column, $columns, true) === false) {
                continue;
            }

            foreach ($values as $old => $new) {
                $planned[] = [
                    'column' => $column,
                    'old'    => (string) $old,
                    'new'    => $new,
                ];
            }
        }

        return $planned;

    }//end plannedRewrites()

    /**
     * Pull a single column out of information_schema rows.
     *
     * Defensive: a null cell yields an empty string rather than a TypeError
     * inside a repair step, where an exception aborts the upgrade.
     *
     * @param array<int, array<string, mixed>> $rows Result rows.
     * @param string                           $key  Column to read.
     *
     * @return array<int, string>
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function column(array $rows, string $key): array
    {
        return array_map(static fn (array $row): string => (string) ($row[$key] ?? ''), $rows);

    }//end column()

    /**
     * The line the step reports when there is nothing to migrate.
     *
     * @return string
     *
     * @spec exclude Operator-facing text of the vocabulary migration.
     */
    public function nothingToDoMessage(): string
    {
        return 'RenameDutchValues: no shillinq shard tables on this install; nothing to do.';

    }//end nothingToDoMessage()

    /**
     * The line the step reports after migrating.
     *
     * An operator reads this to decide whether the migration did anything, so
     * "0 row value(s)" and "nothing to do" must stay distinguishable.
     *
     * @param int $updated Rows rewritten.
     *
     * @return string
     *
     * @spec exclude Operator-facing text of the vocabulary migration.
     */
    public function summaryMessage(int $updated): string
    {
        return sprintf('RenameDutchValues: %d row value(s) translated.', $updated);

    }//end summaryMessage()
    /**
     * Map entries whose replacement differs from the source by CASE alone.
     *
     * Such an entry translates nothing while still producing a diff, so it reads
     * as a translation that was made. Where the source is an identifier it
     * renames the identifier instead: 56 entries in this map's own draft did
     * exactly that, turning `ACMReport` into `aCMReport` and renaming an entity
     * type. Returned rather than thrown so the caller decides; the test asserts
     * empty.
     *
     * @param array<string, array<string, string>> $valueMap Property => old => new.
     *
     * @return array<int, string> One "property: old -> new" line per offender.
     *
     * @spec exclude Self-check on the vocabulary migration's own map.
     */
    public function caseOnlyEntries(array $valueMap): array
    {
        $offenders = [];

        foreach ($valueMap as $property => $values) {
            foreach ($values as $old => $new) {
                $normalise = static fn (string $value): string
                    => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
                if ($normalise((string) $old) !== $normalise($new)) {
                    continue;
                }

                $offenders[] = sprintf('%s: %s -> %s', (string) $property, (string) $old, $new);
            }
        }

        return $offenders;

    }//end caseOnlyEntries()
    /**
     * The SQL LIKE pattern that matches an OpenRegister shard table.
     *
     * The underscores are ESCAPED. Unescaped, `_` is LIKE's single-character
     * wildcard, so `%openregister_table_%` also matches names this migration
     * has no business rewriting — and it would do so silently, since a wider
     * match produces more UPDATEs rather than an error.
     *
     * @return string
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function shardTablePattern(): string
    {
        return '%openregister\_table\_%';

    }//end shardTablePattern()
}//end class
