<?php

/**
 * Shillinq RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * ALL FIFTY OWNERS MOVE TOGETHER. The map below covers every property name in
 * the cluster, and each was checked to be free of a collision with its English
 * target before being added. A register-scoped step cannot rename a column for
 * one owner and not the rest — the others would silently read null.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so this is reversible
 *     and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
class RenameDutchColumns implements IRepairStep
{
    /**
     * Slug prefix of the registers in scope.
     *
     * @var string
     */
    private const REGISTER_SLUG_PREFIX = 'shillinq';

    /**
     * Old snake_case column name => new snake_case column name.
     *
     * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
     * `requested_amount`, and a camelCase column is exactly what its
     * de-duplication path then drops.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'aangevraagd_bedrag'                        => 'requested_amount',
        'aanslag_bedrag'                            => 'assessment_amount',
        'aftrek_bedrag'                             => 'deduction_amount',
        'bedrag'                                    => 'amount',
        'bedrag_betrokken'                          => 'amount_involved',
        'bedrag_excl_btw'                           => 'amount_excl_vat',
        'bedrag_fout'                               => 'amount_error',
        'bedrag_incl_btw'                           => 'amount_incl_vat',
        'bedrag_nieuw_cents'                        => 'new_amount_cents',
        'bedrag_onzekerheid'                        => 'amount_uncertainty',
        'bedrag_oorspronkelijk_cents'               => 'original_amount_cents',
        'bedrag_wijziging_cents'                    => 'amount_change_cents',
        'belastbaar_bedrag_grens'                   => 'taxable_amount_threshold',
        'betaald_bedrag'                            => 'paid_amount',
        'bijtelling_bedrag'                         => 'benefit_in_kind_amount',
        'btw_bedrag'                                => 'vat_amount',
        'btw_suppletie_bedrag'                      => 'vat_supplementary_amount',
        'commercieel_bedrag'                        => 'commercial_amount',
        'commerciele_winst_bedrag'                  => 'commercial_profit_amount',
        'correctie_bedrag'                          => 'correction_amount',
        'factuur_bedrag'                            => 'invoice_amount',
        'fiscaal_bedrag'                            => 'fiscal_amount',
        'forfaitair_cap_bedrag'                     => 'flat_rate_cap_amount',
        'geautoriseerd_bedrag'                      => 'authorised_amount',
        'gefactureerd_bedrag'                       => 'invoiced_amount',
        'geleverd_bedrag'                           => 'delivered_amount',
        'gerealiseerd_bedrag'                       => 'realised_amount',
        'geschat_belastbaar_bedrag'                 => 'estimated_taxable_amount',
        'incassokosten_bedrag'                      => 'collection_cost_amount',
        'innovatiebox_bedrag'                       => 'innovation_box_amount',
        'max_bedrag'                                => 'max_amount',
        'max_bedrag_per_eenheid'                    => 'max_amount_per_unit',
        'min_buffer_bedrag'                         => 'min_buffer_amount',
        'oorspronkelijk_bedrag'                     => 'original_amount',
        'openstaand_bedrag'                         => 'outstanding_amount',
        'rente_bedrag'                              => 'interest_amount',
        'standaard_bedrag'                          => 'standard_amount',
        'startersaftrek_bedrag'                     => 'starter_deduction_amount',
        'teruggevorderd_bedrag'                     => 'reclaimed_amount',
        'toegekend_bedrag'                          => 'awarded_amount',
        'tolerantiegrens_fout_bedrag'               => 'tolerance_error_amount',
        'tolerantiegrens_onzekerheid_bedrag'        => 'tolerance_uncertainty_amount',
        'uitbetaald_bedrag'                         => 'paid_out_amount',
        'uitkering_bedrag'                          => 'benefit_amount',
        'valuation_bedrag'                          => 'valuation_amount',
        'vast_bedrag'                               => 'fixed_amount',
        'vastgesteld_bedrag'                        => 'determined_amount',
        'vastgesteld_belastbaar_bedrag'             => 'determined_taxable_amount',
        'verleend_bedrag'                           => 'granted_amount',
        'zelfstandigenaftrek_bedrag'                => 'self_employed_deduction_amount',


        // Batch 2 — the naam/totaal/jaar/omschrijving/saldo/omzet/regeling/
        // programma/boekjaar/onderneming/eind/nummer clusters, 137 names.
        // Same rule as above: every one was checked to be collision-free
        // against its English target before being added here.
        'aandeel_omzet12mnd'                        => 'revenue_share12m',
        'aanslag_jaar'                              => 'assessment_year',
        'actualisatie_frequentie_jaar'              => 'update_frequency_years',
        'afschrijving_jaar_cents'                   => 'depreciation_year_cents',
        'afschrijvingstermijn_jaar'                 => 'depreciation_period_years',
        'asset_naam'                                => 'asset_name',
        'balans_totaal'                             => 'balance_sheet_total',
        'baten_totaal'                              => 'revenue_total',
        'bbv_saldo_baten_lasten'                    => 'municipal_accounting_balance_revenue_expenses',
        'betalingen_totaal'                         => 'payments_total',
        'boekjaar'                                  => 'financial_year',
        'boekjaar_eind'                             => 'financial_year_end',
        'boekjaar_start'                            => 'financial_year_start',
        'boekjaar_tot'                              => 'financial_year_until',
        'boekjaar_van'                              => 'financial_year_from',
        'boekwaarde_begin_jaar_cents'               => 'book_value_year_start_cents',
        'btw_nummer'                                => 'vat_number',
        'concurrent_naam'                           => 'competitor_name',
        'deal_naam'                                 => 'deal_name',
        'deelnemer_naam'                            => 'participant_name',
        'dotaties_jaar_cents'                       => 'additions_year_cents',
        'drempel_jaar'                              => 'threshold_year',
        'eind_datum'                                => 'end_date',
        'eind_saldo'                                => 'closing_balance',
        'emu_saldo_afwijking'                       => 'emu_balance_deviation',
        'emu_saldo_afwijking_percentage'            => 'emu_balance_deviation_percentage',
        'emu_saldo_begroot'                         => 'emu_balance_budgeted',
        'emu_saldo_berekend'                        => 'emu_balance_calculated',
        'ex_nummer'                                 => 'ex_number',
        'expat30_pct_regeling'                      => 'expat30_pct_scheme',
        'feitelijke_eind_datum'                     => 'actual_end_date',
        'fiscalist_becon_nummer'                    => 'tax_advisor_becon_number',
        'gerealiseerde_omzet'                       => 'realised_revenue',
        'hoofdfunctie_naam'                         => 'main_function_name',
        'horizon_eind'                              => 'horizon_end',
        'huidig_jaar'                               => 'current_year',
        'indicator_omschrijving'                    => 'indicator_description',
        'inflows_totaal'                            => 'inflows_total',
        'jaar'                                      => 'year',
        'jaar_van_gebruik'                          => 'year_of_use',
        'kostprijs_omzet'                           => 'cost_of_revenue',
        'kvk_nummer'                                => 'chamber_of_commerce_number',
        'kwekersrecht_nummer'                       => 'plant_breeders_right_number',
        'lasten_totaal'                             => 'expenses_total',
        'leverancier_naam'                          => 'supplier_name',
        'lock_in_eind_datum'                        => 'lock_in_end_date',
        'looptijd_eind'                             => 'term_end',
        'lopende_omzet'                             => 'current_revenue',
        'maand_van_jaar'                            => 'month_of_year',
        'naam'                                      => 'name',
        'netto_omzet'                               => 'net_revenue',
        'nummer'                                    => 'number',
        'octrooi_nummer'                            => 'patent_number',
        'omschrijving'                              => 'description',
        'omschrijving_iv3'                          => 'description_iv3',
        'omzet'                                     => 'revenue',
        'omzet_aandeel'                             => 'revenue_share',
        'omzet_exclusief_btw'                       => 'revenue_excluding_vat',
        'omzet_op_moment'                           => 'revenue_at_moment',
        'omzettings_regeling'                       => 'conversion_scheme',
        'onderneming_id'                            => 'enterprise_id',
        'ontvanger_naam'                            => 'recipient_name',
        'opdracht_naam'                             => 'assignment_name',
        'opening_saldo'                             => 'opening_balance',
        'outflows_totaal'                           => 'outflows_total',
        'pauze_eind'                                => 'pause_end',
        'pensioen_regeling'                         => 'pension_scheme',
        'periode_eind'                              => 'period_end',
        'periode_jaar'                              => 'period_year',
        'prognose_einde_jaar'                       => 'forecast_year_end',
        'programma'                                 => 'programme',
        'programma_assigned_at'                     => 'programme_assigned_at',
        'programma_code'                            => 'programme_code',
        'programma_focus'                           => 'programme_focus',
        'programma_id'                              => 'programme_id',
        'programma_structure'                       => 'programme_structure',
        'project_naam'                              => 'project_name',
        'raadsbesluit_nummer'                       => 'council_resolution_number',
        'regeling_artikel'                          => 'scheme_article',
        'regeling_code'                             => 'scheme_code',
        'regeling_naam'                             => 'scheme_name',
        'regeling_tot_zakelijk'                     => 'scheme_until_business',
        'rvo_project_nummer'                        => 'rvo_project_number',
        's_en_ocertificaat_nummer'                  => 'rnd_certificate_number',
        'saldo_begin_jaar_cents'                    => 'balance_year_start_cents',
        'saldo_eind_jaar_cents'                     => 'balance_year_end_cents',
        'saldo_incidenteel'                         => 'balance_incidental',
        'saldo_na'                                  => 'balance_after',
        'saldo_na_mutaties'                         => 'balance_after_movements',
        'saldo_open'                                => 'balance_open',
        'saldo_structureel'                         => 'balance_structural',
        'saldo_voor_mutaties'                       => 'balance_before_movements',
        'sector_omschrijving'                       => 'sector_description',
        'size_criteria_netto_omzet'                 => 'size_criteria_net_revenue',
        'sleutel_naam'                              => 'key_name',
        'so_verklaring_nummer'                      => 'rnd_declaration_number',
        'subsidie_regeling'                         => 'subsidy_scheme',
        'taak_omschrijving'                         => 'task_description',
        'taakveld_naam'                             => 'task_field_name',
        'totaal'                                    => 'total',
        'totaal_afdracht'                           => 'total_remittance',
        'totaal_aftrek'                             => 'total_deduction',
        'totaal_aftrekbaar'                         => 'total_deductible',
        'totaal_box1_inkomen'                       => 'total_box1_income',
        'totaal_box3_inkomen'                       => 'total_box3_income',
        'totaal_brutoloon'                          => 'total_gross_pay',
        'totaal_eindheffingen_wkr'                  => 'total_final_levies_work_related_costs',
        'totaal_euomzet'                            => 'total_eu_revenue',
        'totaal_geconstateerde_fouten'              => 'total_identified_errors',
        'totaal_geconstateerde_onzekerheden'        => 'total_identified_uncertainties',
        'totaal_heffingskortingen'                  => 'total_tax_credits',
        'totaal_lasten_inclusief_mutaties_reserves' => 'total_expenses_including_reserve_movements',
        'totaal_lhafdracht'                         => 'total_payroll_tax_remittance',
        'totaal_loonheffing'                        => 'total_payroll_tax',
        'totaal_netto_betaald'                      => 'total_net_paid',
        'totaal_premies_sv'                         => 'total_social_insurance_contributions',
        'totaal_premies_svafdracht'                 => 'total_social_insurance_remittance',
        'totaal_prognose'                           => 'total_forecast',
        'totaal_rendementsgrondslag'                => 'total_yield_basis',
        'totaal_score'                              => 'total_score',
        'totaal_uren'                               => 'total_hours',
        'totaal_verschuldigd'                       => 'total_due',
        'totaal_zvw'                                => 'total_health_insurance',
        'totaal_zvwafdracht'                        => 'total_health_insurance_remittance',
        'uitkering_jaar'                            => 'distribution_year',
        'verplichting_nummer'                       => 'commitment_number',
        'verrekend_boekjaar'                        => 'settled_financial_year',
        'verwachte_eind_datum'                      => 'expected_end_date',
        'verwachte_omzet'                           => 'expected_revenue',
        'voorgaande_omzet'                          => 'previous_revenue',
        'vorig_jaar'                                => 'previous_year',
        'vrijvallen_jaar_cents'                     => 'releases_year_cents',
        'wbso_verklaring_nummer'                    => 'rnd_tax_credit_declaration_number',
        'week_eind'                                 => 'week_end',
        'winst_uit_onderneming'                     => 'profit_from_enterprise',
        'zzp_naam'                                  => 'freelancer_name',


        // Batch 3 — composed from a Dutch->English TOKEN dictionary rather
        // than translated name by name, so compounds stay consistent.
        // Synonym merges (kenmerk/referentie, geldigVan/geldigVanaf,
        // werknemerId/medewerkerId) were checked NOT to co-occur in any
        // schema before being allowed to collapse onto one English name.
        'aangifte'                                  => 'tax_return',
        'aangifte_id'                               => 'tax_return_id',
        'aangifte_type'                             => 'tax_return_type',
        'aanvraag_date'                             => 'request_date',
        'abb_referentie'                            => 'abb_reference',
        'aftrek_percentage'                         => 'deduction_percentage',
        'art29_obverklaring'                        => 'art29_obdeclaration',
        'awf_tarief'                                => 'awf_rate',
        'backfill_reden'                            => 'backfill_reason',
        'base_begroting'                            => 'base_budget',
        'baten'                                     => 'revenue',
        'baten_cents'                               => 'revenue_cents',
        'baten_of_lasten'                           => 'revenue_of_expenses',
        'begroting_id'                              => 'budget_id',
        'begroting_version'                         => 'budget_version',
        'belastingdienst_kenmerk'                   => 'tax_authority_reference',
        'belastingdienst_reference'                 => 'tax_authority_reference',
        'belastingdienst_referentie'                => 'tax_authority_reference',
        'belastingjaar'                             => 'tax_year',
        'berekend'                                  => 'calculated',
        'berekend_door'                             => 'calculated_by',
        'berekend_op'                               => 'calculated_on',
        'beschikking'                               => 'decision',
        'beschikking_date'                          => 'decision_date',
        'beschikking_number'                        => 'decision_number',
        'beschikking_uri'                           => 'decision_uri',
        'beschrijving'                              => 'description',
        'betaling_type_code'                        => 'payment_type_code',
        'betalings_historie_betaald_voor_verval'    => 'payment_history_paid_for_expiry',
        'betalings_risico_score'                    => 'payment_risk_score',
        'boeking_id'                                => 'entry_id',
        'bron'                                      => 'source',
        'bron_referentie'                           => 'source_reference',
        'bruto'                                     => 'gross',
        'bruto_winst'                               => 'gross_profit',
        'btw_aangifte_periode'                      => 'btw_tax_return_period',
        'categorie'                                 => 'category',
        'contract_waarde'                           => 'contract_value',
        'dag_van_maand'                             => 'dag_from_month',
        'dagen'                                     => 'days',
        'dagen_na_verval_datum'                     => 'days_after_expiry_date',
        'days_above_drempel'                        => 'days_above_threshold',
        'drempel'                                   => 'threshold',
        'drempel_2024'                              => 'threshold_2024',
        'drempel_eubrut'                            => 'threshold_eubrut',
        'drempel_status'                            => 'threshold_status',
        'eenheid'                                   => 'unit',
        'eenheid_label'                             => 'unit_label',
        'emu_schuld_bruto'                          => 'emu_debt_gross',
        'factuur_datum'                             => 'invoice_date',
        'factuur_id'                                => 'invoice_id',
        'fiscaal_loon'                              => 'fiscal_loon',
        'fiscal_eenheid_id'                         => 'fiscal_unit_id',
        'fiscale_bron'                              => 'fiscal_source',
        'fiscale_partner'                           => 'fiscal_partner',
        'gebruiker'                                 => 'user',
        'geldig_tot'                                => 'valid_to',
        'geldig_van'                                => 'valid_from',
        'geldig_vanaf'                              => 'valid_from',
        'grondslag'                                 => 'basis',
        'indicator_eenheid'                         => 'indicator_unit',
        'indicator_waarde'                          => 'indicator_value',
        'ingediend'                                 => 'submitted',
        'ingediend_in_aangifte'                     => 'submitted_in_tax_return',
        'ingediend_op'                              => 'submitted_on',
        'interne_kenmerk'                           => 'interne_reference',
        'kanaal'                                    => 'channel',
        'kans_van_betaling'                         => 'kans_from_payment',
        'kenmerk'                                   => 'reference',
        'klant_id'                                  => 'customer_id',
        'kosten_lookup'                             => 'cost_lookup',
        'kostenplaats'                              => 'cost_centre',
        'kostenplaats_code'                         => 'cost_centre_code',
        'lasten'                                    => 'expenses',
        'lasten_cents'                              => 'expenses_cents',
        'linked_claims_voorziening_detail'          => 'linked_claims_provision_detail',
        'looptijd_start'                            => 'term_start',
        'looptijd_tot'                              => 'term_to',
        'looptijd_van'                              => 'term_from',
        'maand_waarde'                              => 'month_value',
        'medewerker_id'                             => 'employee_id',
        'medewerker_ids'                            => 'employee_ids',
        'mkb_winstvrijstelling'                     => 'mkb_profit_exemption',
        'mkb_winstvrijstelling_amount'              => 'mkb_profit_exemption_amount',
        'mkb_winstvrijstelling_percentage'          => 'mkb_profit_exemption_percentage',
        'model_versie'                              => 'model_version',
        'mutatie_reserves_cents'                    => 'movement_reserves_cents',
        'mutaties'                                  => 'movements',
        'mutaties_reserves'                         => 'movements_reserves',
        'mva_categorie'                             => 'mva_category',
        'netto_betaald'                             => 'net_paid',
        'netto_mutatie'                             => 'net_movement',
        'nexus_teller_voor_uplift'                  => 'nexus_teller_for_uplift',
        'nieuw_probability'                         => 'new_probability',
        'nieuw_regime'                              => 'new_regime',
        'norm_grondslag'                            => 'norm_basis',
        'organisatie'                               => 'organisation',
        'outflows_btw_afdracht'                     => 'outflows_btw_remittance',
        'overall_risico'                            => 'overall_risk',
        'override_reden'                            => 'override_reason',
        'ozb_categorie'                             => 'ozb_category',
        'per_categorie'                             => 'per_category',
        'per_maand'                                 => 'per_month',
        'per_maand_prognose'                        => 'per_month_prognose',
        'periode'                                   => 'period',
        'periode_id'                                => 'period_id',
        'periode_nr'                                => 'period_nr',
        'periode_start'                             => 'period_start',
        'periode_type'                              => 'period_type',
        'rapportage_grondslag'                      => 'rapportage_basis',
        'reden'                                     => 'reason',
        'referentie'                                => 'reference',
        'rente_risico_status'                       => 'rente_risk_status',
        'resultaat'                                 => 'result',
        'rol_vereist'                               => 'rol_required',
        'routine_marketing_winst'                   => 'routine_marketing_profit',
        'rvo_beschikking'                           => 'rvo_decision',
        'rvo_referentie'                            => 'rvo_reference',
        'score_schaal'                              => 'score_scale',
        'so_aftrek'                                 => 'so_deduction',
        'so_verklaring'                             => 'so_declaration',
        'so_verklaring_periode'                     => 'so_declaration_period',
        'so_verklaring_referentie'                  => 'so_declaration_reference',
        'soort'                                     => 'kind',
        'soort_verplichting'                        => 'kind_commitment',
        'spaardoel_btw'                             => 'savingsgoal_btw',
        'spaardoel_buffer'                          => 'savingsgoal_buffer',
        'spaardoel_ib'                              => 'savingsgoal_ib',
        'subsidie'                                  => 'subsidy',
        'subsidie_id'                               => 'subsidy_id',
        'subsidie_name'                             => 'subsidy_name',
        'subsidie_number'                           => 'subsidy_number',
        'taakveld'                                  => 'task_field',
        'taakveld_code'                             => 'task_field_code',
        'tarief'                                    => 'rate',
        'tarief_grondslag'                          => 'rate_basis',
        'telt_mee_in_emu_schuld'                    => 'telt_mee_in_emu_debt',
        'toegepast'                                 => 'applied',
        'toegepast_incl_btw'                        => 'applied_incl_btw',
        'toelichting'                               => 'notes',
        'trigger_factuur_id'                        => 'trigger_invoice_id',
        'uren'                                      => 'hours',
        'vanaf'                                     => 'from',
        'vastgesteld_bij'                           => 'determined_at',
        'vastgesteld_door_college_op'               => 'determined_by_college_on',
        'vastgesteld_op'                            => 'determined_on',
        'verklaring_college'                        => 'declaration_college',
        'verklaring_document_uri'                   => 'declaration_document_uri',
        'verklaring_file'                           => 'declaration_file',
        'verklaring_status'                         => 'declaration_status',
        'verplichting'                              => 'commitment',
        'verplichting_id'                           => 'commitment_id',
        'versie'                                    => 'version',
        'verval_datum'                              => 'expiry_date',
        'verwacht_ontvangst_datum'                  => 'expected_receipt_date',
        'verwacht_ontvangst_week'                   => 'expected_receipt_week',
        'vpb_aangifte_id'                           => 'vpb_tax_return_id',
        'waarde'                                    => 'value',
        'wba_geldig_tot'                            => 'wba_valid_to',
        'werknemer_id'                              => 'employee_id',
        'zvw_tarief'                                => 'zvw_rate',
    ];

    /**
     * Constructor.
     *
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Human-readable step name.
     *
     * @return string
     *
     * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
     */
    public function getName(): string
    {
        return 'Move shillinq data from the Dutch columns to the English ones';

    }//end getName()

    /**
     * Run the column migration across every shillinq shard table.
     *
     * @param IOutput $output Repair output.
     *
     * @return void
     *
     * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
     */
    public function run(IOutput $output): void
    {
        $tables = $this->shardTables();
        if ($tables === []) {
            $output->info('RenameDutchColumns: no shillinq shard tables on this install; nothing to do.');
            return;
        }

        $renamed = 0;
        $copied  = 0;
        $refused = 0;

        foreach ($tables as $table) {
            $columns = $this->columnsOf(table: $table);
            $qTable  = $this->quote(identifier: $table);

            foreach (self::COLUMN_MAP as $old => $new) {
                if (in_array($old, $columns, true) === false) {
                    continue;
                }

                if ($this->hasCollision(columns: $columns, target: $new) === true) {
                    $this->logger->warning(
                        'RenameDutchColumns: two sources target one destination; migrating neither.',
                        ['table' => $table, 'source' => $old, 'destination' => $new]
                    );
                    $refused++;
                    continue;
                }

                if (in_array($new, $columns, true) === false) {
                    $sql = 'ALTER TABLE '.$qTable.' RENAME COLUMN '
                        .$this->quote(identifier: $old).' TO '.$this->quote(identifier: $new);
                    if ($this->exec(sql: $sql) === true) {
                        $renamed++;
                    }

                    continue;
                }

                $qNew = $this->quote(identifier: $new);
                $qOld = $this->quote(identifier: $old);
                $sql  = 'UPDATE '.$qTable.' SET '.$qNew.' = '.$qOld
                    .' WHERE '.$qNew.' IS NULL AND '.$qOld.' IS NOT NULL';
                if ($this->exec(sql: $sql) === true) {
                    $copied++;
                }
            }//end foreach
        }//end foreach

        $output->info(
            'RenameDutchColumns: '.$renamed.' renamed, '.$copied.' back-filled, '
            .$refused.' refused, across '.count($tables).' shard table(s).'
        );

    }//end run()

    /**
     * Whether another mapped source already targets the same destination here.
     *
     * @param array<int, string> $columns Column names present in the table.
     * @param string             $target  The destination column name.
     *
     * @return bool True when two sources compete for one destination.
     */
    private function hasCollision(array $columns, string $target): bool
    {
        $sources = 0;
        foreach (self::COLUMN_MAP as $old => $new) {
            if ($new === $target && in_array($old, $columns, true) === true) {
                $sources++;
            }
        }

        return $sources > 1;

    }//end hasCollision()

    /**
     * Resolve the shard tables of every register whose slug starts with the prefix.
     *
     * Table discovery goes through information_schema, NOT IDBConnection:
     * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
     * either is a runtime fatal that `php -l` and phpcs both report as clean.
     * Matching anchors on the `openregister_table_` MARKER rather than a computed
     * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
     * which a raw information_schema string never resolves.
     *
     * @return array<int, string>
     */
    private function shardTables(): array
    {
        try {
            $ids = $this->db->executeQuery(
                'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
                [self::REGISTER_SLUG_PREFIX.'%']
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->logger->warning(
                'RenameDutchColumns: could not resolve the shillinq registers; skipping.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        if ($ids === []) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
            );
            $stmt->bindValue('pattern', '%openregister\_table\_%');
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RenameDutchColumns: could not list tables; skipping.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $wanted = [];
        foreach ($ids as $id) {
            $wanted[] = 'openregister_table_'.((int) $id).'_';
        }

        $tables = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $name = (string) ($row['table_name'] ?? '');
            if ($name === '') {
                continue;
            }

            foreach ($wanted as $marker) {
                $at = strpos($name, $marker);
                if ($at !== false && ctype_digit(substr($name, ($at + strlen($marker)))) === true) {
                    $tables[] = $name;
                }
            }
        }

        return array_values(array_unique($tables));

    }//end shardTables()

    /**
     * List the column names of a table.
     *
     * @param string $table Table name.
     *
     * @return array<int, string>
     */
    private function columnsOf(string $table): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
            );
            $stmt->bindValue('table', $table);
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RenameDutchColumns: could not read columns; skipping table.',
                ['table' => $table, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $columns = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $name = (string) ($row['column_name'] ?? '');
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;

    }//end columnsOf()

    /**
     * Execute one statement, logging and swallowing failure.
     *
     * @param string $sql The statement.
     *
     * @return bool Whether it succeeded.
     */
    private function exec(string $sql): bool
    {
        try {
            $this->db->executeStatement($sql);
            return true;
        } catch (Exception $e) {
            $this->logger->warning(
                'RenameDutchColumns: statement failed; leaving the column as it was.',
                ['sql' => $sql, 'exception' => $e->getMessage()]
            );
            return false;
        }

    }//end exec()

    /**
     * Quote an identifier for the active platform.
     *
     * @param string $identifier Table or column name.
     *
     * @return string
     */
    private function quote(string $identifier): string
    {
        return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);

    }//end quote()
}//end class
