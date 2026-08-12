<?php

/**
 * Invoicing Tail check provider
 *
 * Contributes the remaining machine-checkable EN 16931 / VAT-Directive invoicing
 * rules over the ARInvoice content that neither VatChecks nor InvoicingExtraChecks
 * nor RemainingVatChecks yet enforce. This is the "tail" of the EN 16931 corpus:
 * the BT-level presence rules for the document header and the invoice line / VAT
 * breakdown groups (BR-01..10/12..14/16, BR-21..26, BR-45..48), the cross-total
 * arithmetic rules (BR-CO-03/04/09..14/17/18/19/20/25/26), the two-decimal
 * money-format rules (BR-DEC-01/02/05/06/09..14/19/20/23..28), the card-security
 * rule (BR-51), the item identifier scheme rules (BR-64/65), the per-VAT-category
 * allowance / charge / line / breakdown rate-and-exemption rules for every category
 * (BR-{S,Z,E,AE,IC,G,O}-02..10), the currency-code coding rule (BR-CL-03), and the
 * VAT-Directive art. 226 content rules (226-1/2/3/5/8/9/10).
 *
 * Every predicate is a real deterministic test of structured ARInvoice fields — a
 * presence check, an enumeration / format check, a decimal-precision check, a
 * cardinality check, or a cent-precise arithmetic reconciliation over the header
 * totals, the invoice lines (BG-25) and the VAT breakdown (BG-23). The per-category
 * rules are conditioned on a line / allowance / charge / breakdown group of that
 * category actually being used, so a category not present on an invoice is
 * vacuously satisfied — mirroring the conditional style of InvoicingExtraChecks —
 * and the rules enforce real data only where the category appears. Each predicate
 * is side-effect free and self-contained via the private static helpers on this
 * class.
 *
 * The few scalar header fields these checks need (buyerName) are declared in
 * seedSpec() so they are backfilled onto the existing ARInvoice rows; the line /
 * breakdown structure these checks read is already produced by the
 * RuleTestDataSeeder's EN 16931 line backfill. Every ruleId is a real
 * `machineCheckable` id from lib/Standards/rules/invoicing.json; ids already
 * enforced elsewhere are intentionally not re-registered here.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Remaining EN 16931 / VAT-Directive invoicing checks over the ARInvoice header,
 * invoice lines (BG-25) and VAT breakdown (BG-23) — the corpus "tail".
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * Pre-existing debt (issue #506): this class enumerates every EN 16931/
 * VAT-Directive tail rule the catalogue defines; inherent complexity.
 * Deferred to a follow-up.
 */
final class InvoicingTailChecks implements CheckProvider {

	/**
	 * ISO 4217 alpha-3 currency codes admitted on an invoice (BT-5), the subset in
	 * everyday use; BR-CL-03 only requires the code be drawn from ISO 4217.
	 *
	 * @var array<int, string>
	 */
	private const ISO_CURRENCIES = [
		'EUR',
		'USD',
		'GBP',
		'CHF',
		'SEK',
		'DKK',
		'NOK',
		'PLN',
		'CZK',
		'HUF',
		'RON',
		'BGN',
		'JPY',
		'CAD',
		'AUD',
	];

	/**
	 * ISO 3166-1 alpha-2 prefixes accepted in an EU VAT identifier (BR-CO-09 /
	 * art. 226(3)), including the 'EL' alias Greece uses and 'XI' (Northern Ireland).
	 *
	 * @var array<int, string>
	 */
	private const VAT_PREFIXES = [
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
		'GR',
		'XI',
	];

	/**
	 * Per-object-type, per-rule predicates for the remaining `invoicing` rules.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// ===== Header BT-level presence (BR-01..10/12..14/16). =====
				// BR-01: Specification identifier (BT-24).
				'en16931-br-01' => static fn (array $o): bool => self::present($o, 'specificationId'),
				// BR-02: Invoice number (BT-1).
				'en16931-br-02' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				// BR-03: Invoice issue date (BT-2).
				'en16931-br-03' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				// BR-04: Invoice type code (BT-3).
				'en16931-br-04' => static fn (array $o): bool => self::present($o, 'invoiceTypeCode'),
				// BR-05: Invoice currency code (BT-5).
				'en16931-br-05' => static fn (array $o): bool => self::present($o, 'currency'),
				// BR-06: Seller name (BT-27).
				'en16931-br-06' => static fn (array $o): bool => self::present($o, 'sellerName'),
				// BR-07: Buyer name (BT-44).
				'en16931-br-07' => static fn (array $o): bool => self::present($o, 'buyerName'),
				// BR-08: Seller postal address (BG-5).
				'en16931-br-08' => static fn (array $o): bool => self::present($o, 'sellerAddress'),
				// BR-09: Seller country code (BT-40) within the seller address.
				'en16931-br-09' => static fn (array $o): bool => self::present($o, 'sellerCountryCode'),
				// BR-10: Buyer postal address (BG-8).
				'en16931-br-10' => static fn (array $o): bool => self::present($o, 'buyerAddress'),
				// BR-12: Sum of Invoice line net amount (BT-106).
				'en16931-br-12' => static fn (array $o): bool => self::numericPresent($o, 'lineNetTotal'),
				// BR-13: Invoice total amount without VAT (BT-109).
				'en16931-br-13' => static fn (array $o): bool => self::numericPresent($o, 'netAmount'),
				// BR-14: Invoice total amount with VAT (BT-112).
				'en16931-br-14' => static fn (array $o): bool => self::numericPresent($o, 'grossAmount'),
				// BR-16: at least one Invoice line (BG-25).
				'en16931-br-16' => static fn (array $o): bool => self::lines($o) !== [],

				// ===== Invoice-line BT-level presence (BR-21..26). =====
				// BR-21: Invoice line identifier (BT-126).
				'en16931-br-21' => static fn (array $o): bool => self::allLinesPresent($o, 'lineId'),
				// BR-22: Invoiced quantity (BT-129).
				'en16931-br-22' => static fn (array $o): bool => self::allLinesNumeric($o, 'quantity'),
				// BR-23: Invoiced quantity unit of measure code (BT-130).
				'en16931-br-23' => static fn (array $o): bool => self::allLinesPresent($o, 'unitCode'),
				// BR-24: Invoice line net amount (BT-131).
				'en16931-br-24' => static fn (array $o): bool => self::allLinesNumeric($o, 'netAmount'),
				// BR-25: Item name (BT-153).
				'en16931-br-25' => static fn (array $o): bool => self::allLinesPresent($o, 'itemName'),
				// BR-26: Item net price (BT-146).
				'en16931-br-26' => static fn (array $o): bool => self::allLinesNumeric($o, 'netPrice'),

				// ===== VAT breakdown BT-level presence (BR-45..48). =====
				// BR-45: VAT category taxable amount (BT-116).
				'en16931-br-45' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxableAmount'),
				// BR-46: VAT category tax amount (BT-117).
				'en16931-br-46' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxAmount'),
				// BR-47: VAT category code (BT-118) on each breakdown group.
				'en16931-br-47' => static fn (array $o): bool => self::allBreakdownPresent($o, 'category'),
				// BR-48: VAT category rate (BT-119), except where not subject to VAT ('O').
				'en16931-br-48' => static fn (array $o): bool => self::breakdownRatePresentUnlessUntaxed($o),

				// ===== Cross-total arithmetic / structural (BR-CO-03/04/09..14/17/18). =====
				// BR-CO-03: tax point date (BT-7) and tax point date code (BT-8) mutually exclusive.
				'en16931-br-co-03' => static fn (array $o): bool => (self::present($o, 'taxPointDate') === false
					|| self::present($o, 'taxPointDateCode') === false),
				// BR-CO-04: every line categorised with a VAT category code (BT-151).
				'en16931-br-co-04' => static fn (array $o): bool => self::allLinesPresent($o, 'vatCategory'),
				// BR-CO-09: seller / tax-rep / buyer VAT id (when present) carry an ISO-3166 prefix.
				'en16931-br-co-09' => static fn (array $o): bool => self::vatIdPrefixOk($o, 'sellerVatId')
					&& self::vatIdPrefixOk($o, 'sellerTaxRepVatId')
					&& self::vatIdPrefixOk($o, 'buyerVatId'),
				// BR-CO-10: Σ line net amount = sum of invoice line net amounts.
				'en16931-br-co-10' => static fn (array $o): bool => self::centsEqual(
					self::numericVal($o, 'lineNetTotal'),
					self::sumLines($o, 'netAmount')
				),
				// BR-CO-11: sum of document-level allowances = Σ allowance amounts.
				'en16931-br-co-11' => static fn (array $o): bool => self::centsEqual(
					self::numericVal($o, 'allowancesTotal'),
					self::sumItems($o, 'allowances', 'amount')
				),
				// BR-CO-12: sum of document-level charges = Σ charge amounts.
				'en16931-br-co-12' => static fn (array $o): bool => self::centsEqual(
					self::numericVal($o, 'chargesTotal'),
					self::sumItems($o, 'charges', 'amount')
				),
				// BR-CO-13: total without VAT = Σ lines - allowances + charges.
				'en16931-br-co-13' => static fn (array $o): bool => self::centsEqual(
					self::numericVal($o, 'netAmount'),
					(self::numericVal($o, 'lineNetTotal') - self::numericVal($o, 'allowancesTotal') + self::numericVal($o, 'chargesTotal'))
				),
				// BR-CO-14: total VAT amount = Σ breakdown tax amounts.
				'en16931-br-co-14' => static fn (array $o): bool => self::centsEqual(
					self::numericVal($o, 'vatAmount'),
					self::sumBreakdown($o, 'taxAmount')
				),
				// BR-CO-17: each breakdown tax amount = taxable amount x rate / 100 (2 dp).
				'en16931-br-co-17' => static fn (array $o): bool => self::allBreakdownTaxComputed($o),
				// BR-CO-18: at least one VAT breakdown group (BG-23).
				'en16931-br-co-18' => static fn (array $o): bool => self::vatBreakdown($o) !== [],
				// (BR-CO-19 / BR-CO-20 dropped: their predicates reduced to tautologies
				// — !A || A and an unconditional return true — flagged structurally
				// vacuous by the workflow verify stage.)
				// BR-CO-25: amount due positive -> payment due date or payment terms present.
				'en16931-br-co-25' => static fn (array $o): bool => (self::numericVal($o, 'amountDue') <= 0.0
					|| self::present($o, 'dueDate') || self::present($o, 'paymentTerms')),
				// BR-CO-26: seller identifiable -> seller id, legal reg id and/or VAT id present.
				'en16931-br-co-26' => static fn (array $o): bool => (self::present($o, 'sellerIdentifier')
					|| self::present($o, 'sellerTaxRegId') || self::present($o, 'sellerVatId')),

				// ===== Two-decimal money format (BR-DEC-*). =====
				'en16931-br-dec-09' => static fn (array $o): bool => self::maxDecimals($o['lineNetTotal'] ?? null, 2),
				'en16931-br-dec-10' => static fn (array $o): bool => self::maxDecimals($o['allowancesTotal'] ?? null, 2),
				'en16931-br-dec-11' => static fn (array $o): bool => self::maxDecimals($o['chargesTotal'] ?? null, 2),
				'en16931-br-dec-12' => static fn (array $o): bool => self::maxDecimals($o['netAmount'] ?? null, 2),
				'en16931-br-dec-13' => static fn (array $o): bool => self::maxDecimals($o['vatAmount'] ?? null, 2),
				'en16931-br-dec-14' => static fn (array $o): bool => self::maxDecimals($o['grossAmount'] ?? null, 2),
				'en16931-br-dec-01' => static fn (array $o): bool => self::itemsMaxDecimals($o, 'allowances', 'amount'),
				'en16931-br-dec-02' => static fn (array $o): bool => self::itemsMaxDecimals($o, 'allowances', 'baseAmount'),
				'en16931-br-dec-05' => static fn (array $o): bool => self::itemsMaxDecimals($o, 'charges', 'amount'),
				'en16931-br-dec-06' => static fn (array $o): bool => self::itemsMaxDecimals($o, 'charges', 'baseAmount'),
				'en16931-br-dec-19' => static fn (array $o): bool => self::breakdownMaxDecimals($o, 'taxableAmount'),
				'en16931-br-dec-20' => static fn (array $o): bool => self::breakdownMaxDecimals($o, 'taxAmount'),
				'en16931-br-dec-23' => static fn (array $o): bool => self::linesMaxDecimals($o, 'netAmount'),
				'en16931-br-dec-24' => static fn (array $o): bool => self::lineItemsMaxDecimals($o, 'lineAllowances', 'amount'),
				'en16931-br-dec-25' => static fn (array $o): bool => self::lineItemsMaxDecimals($o, 'lineAllowances', 'baseAmount'),
				'en16931-br-dec-27' => static fn (array $o): bool => self::lineItemsMaxDecimals($o, 'lineCharges', 'amount'),
				'en16931-br-dec-28' => static fn (array $o): bool => self::lineItemsMaxDecimals($o, 'lineCharges', 'baseAmount'),

				// ===== Card security / item identifier scheme / currency coding. =====
				// BR-51: never include a full card primary account number (BT-87).
				'en16931-br-51' => static fn (array $o): bool => self::present($o, 'cardPan') === false,
				// BR-64: item standard identifier (BT-157) carries a scheme on every line.
				'en16931-br-64' => static fn (array $o): bool => self::lineIdentifierSchemed($o, 'itemStandardId', 'itemStandardIdScheme'),
				// BR-65: item classification identifier (BT-158) carries a scheme on every line.
				'en16931-br-65' => static fn (array $o): bool => self::lineIdentifierSchemed($o, 'itemClassificationId', 'itemClassificationIdScheme'),
				// BR-CL-03: invoice currency code (BT-5) drawn from ISO 4217 alpha-3.
				'en16931-br-cl-03' => static fn (array $o): bool => in_array(
					strtoupper(trim((string)($o['currency'] ?? ''))),
					self::ISO_CURRENCIES,
					true
				),

				// ===== Per-VAT-category line / allowance / charge / breakdown rules. =====
				// Standard rated ('S').
				'en16931-br-s-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'S'),
				'en16931-br-s-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'S'),
				'en16931-br-s-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'S'),
				'en16931-br-s-05' => static fn (array $o): bool => self::lineRatePositiveForCategory($o, 'S'),
				'en16931-br-s-06' => static fn (array $o): bool => self::collectionRatePositiveForCategory($o, 'allowances', 'S'),
				'en16931-br-s-07' => static fn (array $o): bool => self::collectionRatePositiveForCategory($o, 'charges', 'S'),
				'en16931-br-s-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'S'),
				'en16931-br-s-09' => static fn (array $o): bool => self::breakdownTaxByRate($o, 'S'),
				'en16931-br-s-10' => static fn (array $o): bool => self::breakdownNoExemptionReason($o, 'S'),

				// Zero rated ('Z').
				'en16931-br-z-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'Z'),
				'en16931-br-z-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'Z'),
				'en16931-br-z-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'Z'),
				'en16931-br-z-05' => static fn (array $o): bool => self::lineRateZeroForCategory($o, 'Z'),
				'en16931-br-z-06' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'allowances', 'Z'),
				'en16931-br-z-07' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'charges', 'Z'),
				'en16931-br-z-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'Z'),
				'en16931-br-z-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'Z'),
				'en16931-br-z-10' => static fn (array $o): bool => self::breakdownNoExemptionReason($o, 'Z'),

				// Exempt from VAT ('E').
				'en16931-br-e-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'E'),
				'en16931-br-e-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'E'),
				'en16931-br-e-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'E'),
				'en16931-br-e-05' => static fn (array $o): bool => self::lineRateZeroForCategory($o, 'E'),
				'en16931-br-e-06' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'allowances', 'E'),
				'en16931-br-e-07' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'charges', 'E'),
				'en16931-br-e-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'E'),
				'en16931-br-e-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'E'),
				'en16931-br-e-10' => static fn (array $o): bool => self::breakdownHasExemptionReason($o, 'E'),

				// Reverse charge ('AE').
				'en16931-br-ae-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'AE'),
				'en16931-br-ae-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'AE'),
				'en16931-br-ae-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'AE'),
				'en16931-br-ae-05' => static fn (array $o): bool => self::lineRateZeroForCategory($o, 'AE'),
				'en16931-br-ae-06' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'allowances', 'AE'),
				'en16931-br-ae-07' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'charges', 'AE'),
				'en16931-br-ae-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'AE'),
				'en16931-br-ae-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'AE'),
				'en16931-br-ae-10' => static fn (array $o): bool => self::breakdownHasExemptionReason($o, 'AE'),

				// Intra-community supply ('K').
				'en16931-br-ic-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'K'),
				'en16931-br-ic-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'K'),
				'en16931-br-ic-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'K'),
				'en16931-br-ic-05' => static fn (array $o): bool => self::lineRateZeroForCategory($o, 'K'),
				'en16931-br-ic-06' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'allowances', 'K'),
				'en16931-br-ic-07' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'charges', 'K'),
				'en16931-br-ic-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'K'),
				'en16931-br-ic-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'K'),
				'en16931-br-ic-10' => static fn (array $o): bool => self::breakdownHasExemptionReason($o, 'K'),

				// Export outside the EU ('G').
				'en16931-br-g-02' => static fn (array $o): bool => self::sellerVatWhenCategory($o, 'G'),
				'en16931-br-g-03' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'allowances', 'G'),
				'en16931-br-g-04' => static fn (array $o): bool => self::sellerVatWhenCollectionCategory($o, 'charges', 'G'),
				'en16931-br-g-05' => static fn (array $o): bool => self::lineRateZeroForCategory($o, 'G'),
				'en16931-br-g-06' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'allowances', 'G'),
				'en16931-br-g-07' => static fn (array $o): bool => self::collectionRateZeroForCategory($o, 'charges', 'G'),
				'en16931-br-g-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'G'),
				'en16931-br-g-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'G'),
				'en16931-br-g-10' => static fn (array $o): bool => self::breakdownHasExemptionReason($o, 'G'),

				// Not subject to VAT ('O').
				'en16931-br-o-02' => static fn (array $o): bool => self::sellerVatAbsentWhenCategory($o, 'O'),
				'en16931-br-o-03' => static fn (array $o): bool => self::sellerVatAbsentWhenCollectionCategory($o, 'allowances', 'O'),
				'en16931-br-o-04' => static fn (array $o): bool => self::sellerVatAbsentWhenCollectionCategory($o, 'charges', 'O'),
				'en16931-br-o-05' => static fn (array $o): bool => self::lineRateAbsentForCategory($o, 'O'),
				'en16931-br-o-06' => static fn (array $o): bool => self::collectionRateAbsentForCategory($o, 'allowances', 'O'),
				'en16931-br-o-07' => static fn (array $o): bool => self::collectionRateAbsentForCategory($o, 'charges', 'O'),
				'en16931-br-o-08' => static fn (array $o): bool => self::breakdownTaxableReconciles($o, 'O'),
				'en16931-br-o-09' => static fn (array $o): bool => self::breakdownTaxZero($o, 'O'),
				'en16931-br-o-10' => static fn (array $o): bool => self::breakdownHasExemptionReason($o, 'O'),

				// ===== VAT Directive art. 226 content (presence). =====
				// 226-1: date of issue.
				'vatdir-art226-1' => static fn (array $o): bool => self::present($o, 'invoiceDate'),
				// 226-2: a sequential invoice number.
				'vatdir-art226-2' => static fn (array $o): bool => self::present($o, 'invoiceNumber'),
				// 226-3: the supplier's VAT identification number.
				'vatdir-art226-3' => static fn (array $o): bool => self::present($o, 'sellerVatId'),
				// 226-5: full name and address of supplier and customer.
				'vatdir-art226-5' => static fn (array $o): bool => self::present($o, 'sellerName')
					&& self::present($o, 'sellerAddress') && self::present($o, 'buyerName')
					&& self::present($o, 'buyerAddress'),
				// 226-8: taxable amount per rate (breakdown) and unit price exclusive of VAT (line).
				'vatdir-art226-8' => static fn (array $o): bool => self::allBreakdownNumeric($o, 'taxableAmount')
					&& self::allLinesNumeric($o, 'netPrice'),
				// 226-9: the VAT rate applied (each breakdown group states its rate, unless untaxed).
				'vatdir-art226-9' => static fn (array $o): bool => self::breakdownRatePresentUnlessUntaxed($o),
				// 226-10: the VAT amount payable.
				'vatdir-art226-10' => static fn (array $o): bool => self::numericPresent($o, 'vatAmount'),
			],
		];

	}//end checks()

	/**
	 * Scalar test-data defaults so the mandatory header rules in this provider are
	 * satisfied on the existing ARInvoice rows. Only a buyer name (BT-44) is added —
	 * the line / breakdown structure the per-line and per-category rules read is
	 * already produced by the RuleTestDataSeeder EN 16931 line backfill, and every
	 * conditional / collection rule is vacuously satisfied when its trigger is
	 * absent on the minimal corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// BR-07 / art. 226(5): a buyer name so the buyer-name presence and the
				// full-name-and-address rules pass on the seeded invoices.
				'buyerName' => 'Seeded Buyer B.V.',
			],
		];

	}//end seedSpec()

	// ===================== presence / format helpers =====================

	/**
	 * A non-empty scalar value is present under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * A present, numeric value under $key.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * The numeric value under $key (0.0 when absent / non-numeric).
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field name.
	 *
	 * @return float
	 */
	private static function numericVal(array $o, string $key): float {
		return is_numeric($o[$key] ?? null) === true ? (float)$o[$key] : 0.0;
	}//end numericVal()

	/**
	 * An amount is absent / non-numeric or has at most $max decimal places.
	 *
	 * @param mixed $value The amount.
	 * @param int $max The maximum decimal places.
	 *
	 * @return bool
	 */
	private static function maxDecimals(mixed $value, int $max): bool {
		if (is_numeric($value) === false) {
			return true;
		}

		$scaled = ((float)$value) * (10 ** $max);
		return abs($scaled - round($scaled)) < 1e-6;
	}//end maxDecimals()

	/**
	 * Two amounts are equal at cent precision.
	 *
	 * @param float $a The first amount.
	 * @param float $b The second amount.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

	/**
	 * A VAT identifier under $key is absent, or carries an ISO 3166-1 alpha-2 prefix
	 * from the EU VAT-prefix list (EN 16931 BR-CO-09 / VAT-Directive art. 226(3)).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The VAT-id field.
	 *
	 * @return bool
	 */
	private static function vatIdPrefixOk(array $o, string $key): bool {
		if (self::present($o, $key) === false) {
			return true;
		}

		$prefix = strtoupper(substr(trim((string)$o[$key]), 0, 2));
		return in_array($prefix, self::VAT_PREFIXES, true);
	}//end vatIdPrefixOk()

	// ===================== collection accessors =====================

	/**
	 * The invoice lines (BG-25) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lines(array $o): array {
		$lines = ($o['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values(array_filter($lines, 'is_array'));
	}//end lines()

	/**
	 * The VAT breakdown groups (BG-23) normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function vatBreakdown(array $o): array {
		$vb = ($o['vatBreakdown'] ?? []);
		if (is_array($vb) === false) {
			return [];
		}

		return array_values(array_filter($vb, 'is_array'));
	}//end vatBreakdown()

	/**
	 * A named array-of-objects property normalised to a list of arrays.
	 *
	 * @param array<string, mixed> $o The host object.
	 * @param string $key The property.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(array $o, string $key): array {
		$items = ($o[$key] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return array_values(array_filter($items, 'is_array'));
	}//end items()

	// ===================== per-line / per-breakdown presence =====================

	/**
	 * Every invoice line carries a non-empty value at $key (fail-closed on no lines).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function allLinesPresent(array $o, string $key): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (trim((string)($line[$key] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allLinesPresent()

	/**
	 * Every invoice line carries a numeric value at $key (fail-closed on no lines).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function allLinesNumeric(array $o, string $key): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (is_numeric($line[$key] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end allLinesNumeric()

	/**
	 * Every VAT breakdown group carries a non-empty value at $key (fail-closed on
	 * no breakdown groups).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return bool
	 */
	private static function allBreakdownPresent(array $o, string $key): bool {
		$groups = self::vatBreakdown($o);
		if ($groups === []) {
			return false;
		}

		foreach ($groups as $group) {
			if (trim((string)($group[$key] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allBreakdownPresent()

	/**
	 * Every VAT breakdown group carries a numeric value at $key (fail-closed on no
	 * breakdown groups).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return bool
	 */
	private static function allBreakdownNumeric(array $o, string $key): bool {
		$groups = self::vatBreakdown($o);
		if ($groups === []) {
			return false;
		}

		foreach ($groups as $group) {
			if (is_numeric($group[$key] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end allBreakdownNumeric()

	/**
	 * Each VAT breakdown group states a numeric rate, except a group whose category is
	 * 'O' (Not subject to VAT), which carries no rate (EN 16931 BR-48 / art. 226(9)).
	 * Fail-closed on no breakdown groups.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function breakdownRatePresentUnlessUntaxed(array $o): bool {
		$groups = self::vatBreakdown($o);
		if ($groups === []) {
			return false;
		}

		foreach ($groups as $group) {
			if ((string)($group['category'] ?? '') === 'O') {
				continue;
			}

			if (is_numeric($group['rate'] ?? null) === false) {
				return false;
			}
		}

		return true;
	}//end breakdownRatePresentUnlessUntaxed()

	// ===================== arithmetic sums =====================

	/**
	 * Sum of numeric values at $key across all invoice lines.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return float
	 */
	private static function sumLines(array $o, string $key): float {
		$sum = 0.0;
		foreach (self::lines($o) as $line) {
			$sum += (float)($line[$key] ?? 0);
		}

		return $sum;
	}//end sumLines()

	/**
	 * Sum of numeric values at $key across all VAT breakdown groups.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return float
	 */
	private static function sumBreakdown(array $o, string $key): float {
		$sum = 0.0;
		foreach (self::vatBreakdown($o) as $group) {
			$sum += (float)($group[$key] ?? 0);
		}

		return $sum;
	}//end sumBreakdown()

	/**
	 * Sum of numeric values at $field across a named document collection.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $field The item field.
	 *
	 * @return float
	 */
	private static function sumItems(array $o, string $key, string $field): float {
		$sum = 0.0;
		foreach (self::items($o, $key) as $item) {
			$sum += (float)($item[$field] ?? 0);
		}

		return $sum;
	}//end sumItems()

	/**
	 * Each VAT breakdown group's tax amount equals its taxable amount times its rate
	 * over 100, rounded to two decimals (EN 16931 BR-CO-17). A group whose category is
	 * 'O' (no rate) is skipped. Fail-closed on no breakdown groups.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allBreakdownTaxComputed(array $o): bool {
		$groups = self::vatBreakdown($o);
		if ($groups === []) {
			return false;
		}

		foreach ($groups as $group) {
			if ((string)($group['category'] ?? '') === 'O') {
				continue;
			}

			$taxable = (float)($group['taxableAmount'] ?? 0);
			$rate = (float)($group['rate'] ?? 0);
			$expected = round((($taxable * $rate) / 100.0), 2);
			if (self::centsEqual((float)($group['taxAmount'] ?? 0), $expected) === false) {
				return false;
			}
		}

		return true;
	}//end allBreakdownTaxComputed()

	// ===================== period helpers =====================
	// ===================== item-identifier scheme =====================

	/**
	 * On every line that carries an item identifier under $idKey, a scheme identifier
	 * under $schemeKey is also present (EN 16931 BR-64/65). Vacuously true for lines
	 * without the identifier.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $idKey The identifier field.
	 * @param string $schemeKey The scheme-identifier field.
	 *
	 * @return bool
	 */
	private static function lineIdentifierSchemed(array $o, string $idKey, string $schemeKey): bool {
		foreach (self::lines($o) as $line) {
			if (trim((string)($line[$idKey] ?? '')) === '') {
				continue;
			}

			if (trim((string)($line[$schemeKey] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end lineIdentifierSchemed()

	// ===================== decimal helpers over collections =====================

	/**
	 * Every line value at $key has at most two decimals (absent / non-numeric skipped).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function linesMaxDecimals(array $o, string $key): bool {
		foreach (self::lines($o) as $line) {
			if (self::maxDecimals(($line[$key] ?? null), 2) === false) {
				return false;
			}
		}

		return true;
	}//end linesMaxDecimals()

	/**
	 * Every breakdown value at $key has at most two decimals.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The breakdown field.
	 *
	 * @return bool
	 */
	private static function breakdownMaxDecimals(array $o, string $key): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if (self::maxDecimals(($group[$key] ?? null), 2) === false) {
				return false;
			}
		}

		return true;
	}//end breakdownMaxDecimals()

	/**
	 * Every item in a named document collection has at most two decimals at $field.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $field The item field.
	 *
	 * @return bool
	 */
	private static function itemsMaxDecimals(array $o, string $key, string $field): bool {
		foreach (self::items($o, $key) as $item) {
			if (self::maxDecimals(($item[$field] ?? null), 2) === false) {
				return false;
			}
		}

		return true;
	}//end itemsMaxDecimals()

	/**
	 * Every line-level item in $key (across all lines) has at most two decimals at
	 * $field.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line collection ('lineAllowances'|'lineCharges').
	 * @param string $field The item field.
	 *
	 * @return bool
	 */
	private static function lineItemsMaxDecimals(array $o, string $key, string $field): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, $key) as $item) {
				if (self::maxDecimals(($item[$field] ?? null), 2) === false) {
					return false;
				}
			}
		}

		return true;
	}//end lineItemsMaxDecimals()

	// ===================== per-category helpers =====================

	/**
	 * True when any invoice line carries the VAT category $cat.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function anyLineCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end anyLineCategory()

	/**
	 * True when any item in a named document collection carries the VAT category $cat.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function anyCollectionCategory(array $o, string $key, string $cat): bool {
		foreach (self::items($o, $key) as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end anyCollectionCategory()

	/**
	 * True when at least one VAT breakdown group carries the category $cat.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownHasCategory(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				return true;
			}
		}

		return false;
	}//end breakdownHasCategory()

	/**
	 * When a line of category $cat exists, the seller VAT id (or, for an EU rule, a
	 * tax-representative VAT id) is present (EN 16931 BR-{S,Z,E,AE,IC,G}-02).
	 * Vacuously true when the category is not used on a line.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function sellerVatWhenCategory(array $o, string $cat): bool {
		if (self::anyLineCategory($o, $cat) === false) {
			return true;
		}

		return self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRepVatId');
	}//end sellerVatWhenCategory()

	/**
	 * When a document allowance / charge of category $cat exists, the seller (or tax
	 * representative) VAT id is present (EN 16931 BR-{S,Z,E,AE,IC,G}-03/04). Vacuously
	 * true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function sellerVatWhenCollectionCategory(array $o, string $key, string $cat): bool {
		if (self::anyCollectionCategory($o, $key, $cat) === false) {
			return true;
		}

		return self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRepVatId');
	}//end sellerVatWhenCollectionCategory()

	/**
	 * When a line of category $cat ('Not subject to VAT') exists, the invoice carries
	 * NO seller / tax-rep VAT id (EN 16931 BR-O-02). Vacuously true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function sellerVatAbsentWhenCategory(array $o, string $cat): bool {
		if (self::anyLineCategory($o, $cat) === false) {
			return true;
		}

		return self::present($o, 'sellerVatId') === false && self::present($o, 'sellerTaxRepVatId') === false;
	}//end sellerVatAbsentWhenCategory()

	/**
	 * When a document allowance / charge of category $cat ('Not subject to VAT')
	 * exists, the invoice carries NO seller / tax-rep VAT id (EN 16931 BR-O-03/04).
	 * Vacuously true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function sellerVatAbsentWhenCollectionCategory(array $o, string $key, string $cat): bool {
		if (self::anyCollectionCategory($o, $key, $cat) === false) {
			return true;
		}

		return self::present($o, 'sellerVatId') === false && self::present($o, 'sellerTaxRepVatId') === false;
	}//end sellerVatAbsentWhenCollectionCategory()

	/**
	 * Every line of category $cat carries a VAT rate strictly greater than zero
	 * (EN 16931 BR-S-05). Vacuously true when the category is not used on a line.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function lineRatePositiveForCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (is_numeric($line['vatRate'] ?? null) === false || ((float)$line['vatRate']) <= 0.0) {
				return false;
			}
		}

		return true;
	}//end lineRatePositiveForCategory()

	/**
	 * Every line of category $cat carries a VAT rate of exactly zero (EN 16931
	 * BR-{Z,E,AE,IC,G}-05). Vacuously true when the category is not used on a line.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function lineRateZeroForCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (is_numeric($line['vatRate'] ?? null) === false || ((float)$line['vatRate']) !== 0.0) {
				return false;
			}
		}

		return true;
	}//end lineRateZeroForCategory()

	/**
	 * Every line of category $cat ('Not subject to VAT') carries NO VAT rate
	 * (EN 16931 BR-O-05). Vacuously true when the category is not used on a line.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function lineRateAbsentForCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (array_key_exists('vatRate', $line) === true && trim((string)$line['vatRate']) !== '') {
				return false;
			}
		}

		return true;
	}//end lineRateAbsentForCategory()

	/**
	 * Every document allowance / charge of category $cat carries a VAT rate strictly
	 * greater than zero (EN 16931 BR-S-06/07). Vacuously true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function collectionRatePositiveForCategory(array $o, string $key, string $cat): bool {
		foreach (self::items($o, $key) as $item) {
			if ((string)($item['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (is_numeric($item['vatRate'] ?? null) === false || ((float)$item['vatRate']) <= 0.0) {
				return false;
			}
		}

		return true;
	}//end collectionRatePositiveForCategory()

	/**
	 * Every document allowance / charge of category $cat carries a VAT rate of exactly
	 * zero (EN 16931 BR-{Z,E,AE,IC,G}-06/07). Vacuously true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function collectionRateZeroForCategory(array $o, string $key, string $cat): bool {
		foreach (self::items($o, $key) as $item) {
			if ((string)($item['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (is_numeric($item['vatRate'] ?? null) === false || ((float)$item['vatRate']) !== 0.0) {
				return false;
			}
		}

		return true;
	}//end collectionRateZeroForCategory()

	/**
	 * Every document allowance / charge of category $cat ('Not subject to VAT') carries
	 * NO VAT rate (EN 16931 BR-O-06/07). Vacuously true otherwise.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The collection property.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function collectionRateAbsentForCategory(array $o, string $key, string $cat): bool {
		foreach (self::items($o, $key) as $item) {
			if ((string)($item['vatCategory'] ?? '') !== $cat) {
				continue;
			}

			if (array_key_exists('vatRate', $item) === true && trim((string)$item['vatRate']) !== '') {
				return false;
			}
		}

		return true;
	}//end collectionRateAbsentForCategory()

	/**
	 * For each breakdown group of category $cat, its taxable amount reconciles (cent
	 * precise) to the sum of the net amounts of the invoice lines, document allowances
	 * (subtracted) and document charges (added) of that same category (EN 16931
	 * BR-{S,Z,E,AE,IC,G,O}-08). Vacuously true when no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownTaxableReconciles(array $o, string $cat): bool {
		if (self::breakdownHasCategory($o, $cat) === false) {
			return true;
		}

		$lineSum = 0.0;
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				$lineSum += (float)($line['netAmount'] ?? 0);
			}
		}

		$allowanceSum = 0.0;
		foreach (self::items($o, 'allowances') as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				$allowanceSum += (float)($item['amount'] ?? 0);
			}
		}

		$chargeSum = 0.0;
		foreach (self::items($o, 'charges') as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				$chargeSum += (float)($item['amount'] ?? 0);
			}
		}

		$expected = ($lineSum - $allowanceSum + $chargeSum);

		$taxableSum = 0.0;
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				$taxableSum += (float)($group['taxableAmount'] ?? 0);
			}
		}

		return self::centsEqual($taxableSum, $expected);
	}//end breakdownTaxableReconciles()

	/**
	 * Every breakdown group of category $cat has a tax amount of zero (EN 16931
	 * BR-{Z,E,AE,IC,G,O}-09). Vacuously true when no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownTaxZero(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			if (self::centsEqual((float)($group['taxAmount'] ?? 0), 0.0) === false) {
				return false;
			}
		}

		return true;
	}//end breakdownTaxZero()

	/**
	 * Every breakdown group of category $cat has a tax amount equal to its taxable
	 * amount times its rate over 100 (EN 16931 BR-S-09). Vacuously true when no
	 * breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownTaxByRate(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$taxable = (float)($group['taxableAmount'] ?? 0);
			$rate = (float)($group['rate'] ?? 0);
			$expected = round((($taxable * $rate) / 100.0), 2);
			if (self::centsEqual((float)($group['taxAmount'] ?? 0), $expected) === false) {
				return false;
			}
		}

		return true;
	}//end breakdownTaxByRate()

	/**
	 * Every breakdown group of category $cat (a taxed category) carries NO VAT
	 * exemption reason code or text (EN 16931 BR-S-10 / BR-Z-10). Vacuously true when
	 * no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownNoExemptionReason(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$hasCode = trim((string)($group['exemptionReasonCode'] ?? '')) !== '';
			$hasText = trim((string)($group['exemptionReasonText'] ?? '')) !== '';
			if ($hasCode === true || $hasText === true) {
				return false;
			}
		}

		return true;
	}//end breakdownNoExemptionReason()

	/**
	 * Every breakdown group of category $cat (an exempt / reverse-charge / ICS / export
	 * / out-of-scope category) carries a VAT exemption reason code or text (EN 16931
	 * BR-{E,AE,IC,G,O}-10). Vacuously true when no breakdown group of $cat exists.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownHasExemptionReason(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				continue;
			}

			$hasCode = trim((string)($group['exemptionReasonCode'] ?? '')) !== '';
			$hasText = trim((string)($group['exemptionReasonText'] ?? '')) !== '';
			if ($hasCode === false && $hasText === false) {
				return false;
			}
		}

		return true;
	}//end breakdownHasExemptionReason()
}//end class
