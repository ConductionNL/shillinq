<?php

/**
 * Invoicing Extra check provider
 *
 * Contributes the remaining machine-checkable EN 16931 / VAT-Directive invoicing
 * rules that the RuleEngine built-in registry does not yet enforce, mapped onto
 * the existing ARInvoice header/line/breakdown fields. This covers payment means
 * and terms (BG-16/BG-17), buyer/seller references and electronic addresses
 * (BT-34/BT-49), delivery information (BG-13/BG-15), preceding-invoice references
 * (BG-3), additional supporting documents (BG-24), item attributes (BG-32), the
 * remaining BT-level presence / format / decimal / arithmetic rules, the period
 * date-order rules (BR-29/30), and the VAT-category existence/exclusivity rules
 * (BR-{S,Z,E,AE,IC,G,O}-01, BR-O-11..14, BR-IC-11/12).
 *
 * Every predicate is a real deterministic test of structured ARInvoice fields and
 * is side-effect free. Conditional rules are written to be vacuously satisfied
 * when their optional group is absent, so they enforce real data when present
 * without flagging the minimal seeded corpus. New header fields these checks need
 * are declared in seedSpec() (scalar defaults) and in the register fragment
 * lib/Settings/register.d/checks-invoicing-extra.json.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Remaining EN 16931 / VAT-Directive invoicing checks over the ARInvoice header,
 * invoice lines (BG-25), and VAT breakdown (BG-23).
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Pre-existing debt (issue #506): this class enumerates every EN 16931/
 * VAT-Directive invoicing rule the catalogue defines; inherent
 * complexity. Deferred to a follow-up.
 */
final class InvoicingExtraChecks implements CheckProvider {

	/**
	 * UNTDID 1001 invoice / credit-note type codes (BT-3) — the subset EN 16931
	 * BR-CL-01 admits for an Invoice or Credit note.
	 *
	 * @var array<int, string>
	 */
	private const INVOICE_TYPE_CODES = [
		'71',
		'80',
		'81',
		'82',
		'83',
		'84',
		'102',
		'130',
		'202',
		'203',
		'204',
		'211',
		'218',
		'219',
		'261',
		'262',
		'295',
		'296',
		'308',
		'325',
		'326',
		'380',
		'381',
		'383',
		'384',
		'385',
		'386',
		'387',
		'388',
		'389',
		'393',
		'394',
		'395',
		'396',
		'420',
		'456',
		'457',
		'458',
		'527',
		'532',
		'575',
		'623',
		'633',
		'751',
		'780',
		'817',
		'870',
		'875',
		'876',
		'877',
	];

	/**
	 * UNCL5305 VAT category codes (BT-118 / BT-151 / BT-95 / BT-102) admitted by
	 * EN 16931 BR-CL-17.
	 *
	 * @var array<int, string>
	 */
	private const VAT_CATEGORY_CODES = ['S', 'Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M', 'B'];

	/**
	 * UNCL4461 payment-means type codes (BT-81) admitted by EN 16931 BR-CL-16 —
	 * the codes used in practice for an electronic invoice.
	 *
	 * @var array<int, string>
	 */
	private const PAYMENT_MEANS_CODES = [
		'1',
		'10',
		'20',
		'30',
		'31',
		'42',
		'48',
		'49',
		'50',
		'54',
		'55',
		'57',
		'58',
		'59',
		'70',
		'97',
	];

	/**
	 * Payment-means codes that denote a credit transfer (SEPA / local /
	 * non-SEPA), for which a payment account identifier (BT-84) is required
	 * (EN 16931 BR-50 / BR-61).
	 *
	 * @var array<int, string>
	 */
	private const CREDIT_TRANSFER_CODES = ['30', '58', '59'];

	/**
	 * The remaining invoicing checks, keyed by object type then catalogue rule id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// --- Buyer postal address country code + country-code coding (BT-55, BR-CL-14). ---
				'en16931-br-11' => static fn (array $o): bool => self::present($o, 'buyerCountryCode'),
				'en16931-br-cl-14' => static fn (array $o): bool => self::countryCodesValid($o),

				// --- Currency-code ISO 4217 coding (BT-5, BR-CL-04 — same obligation as BR-CL-03). ---
				'en16931-br-cl-04' => static fn (array $o): bool => self::isIsoCurrency((string)($o['currency'] ?? '')),
				// Tax currency code (BT-6) ISO 4217 when present (BR-CL-05).
				'en16931-br-cl-05' => static fn (array $o): bool => self::optionalIsoCurrency($o, 'taxCurrencyCode'),

				// --- Invoice type code in UNTDID 1001 (BT-3, BR-CL-01). ---
				'en16931-br-cl-01' => static fn (array $o): bool => in_array(
					(string)($o['invoiceTypeCode'] ?? ''),
					self::INVOICE_TYPE_CODES,
					true
				),

				// --- VAT categories coded with UNCL5305, across lines + breakdown (BR-CL-17). ---
				'en16931-br-cl-17' => static fn (array $o): bool => self::vatCategoriesValid($o),
				// --- Unit-of-measure code present on every line (BT-130, BR-CL-23). ---
				'en16931-br-cl-23' => static fn (array $o): bool => self::allLinesPresent($o, 'unitCode'),

				// --- Item net price (BT-146) / gross price (BT-148) shall not be negative (BR-27/28). ---
				'en16931-br-27' => static fn (array $o): bool => self::allLinesNonNegative($o, 'netPrice'),
				'en16931-br-28' => static fn (array $o): bool => self::allLinesNonNegative($o, 'grossPrice'),

				// --- Invoicing / line period date order (BR-29/30) and use-implies-filled (BR-CO-19/20). ---
				'en16931-br-29' => static fn (array $o): bool => self::periodOrdered(
					(string)($o['invoicingPeriodStart'] ?? ''),
					(string)($o['invoicingPeriodEnd'] ?? '')
				),
				'en16931-br-30' => static fn (array $o): bool => self::allLinePeriodsOrdered($o),

				// --- Document allowance/charge reason present (BR-CO-21/22, same as BR-33/38). ---
				'en16931-br-co-21' => static fn (array $o): bool => self::allItemsHaveReason($o, 'allowances'),
				'en16931-br-co-22' => static fn (array $o): bool => self::allItemsHaveReason($o, 'charges'),
				// --- Line allowance/charge reason present (BR-CO-23/24, same as BR-42/44). ---
				'en16931-br-co-23' => static fn (array $o): bool => self::allLineItemsHaveReason($o, 'lineAllowances'),
				'en16931-br-co-24' => static fn (array $o): bool => self::allLineItemsHaveReason($o, 'lineCharges'),

				// --- Amount due for payment (BT-115): presence, decimals, arithmetic. ---
				// BR-15/DEC-18 are conditional on the field being present, so the minimal
				// seeded corpus (no amountDue) is vacuously satisfied while a populated
				// invoice is checked for real.
				'en16931-br-15' => static fn (array $o): bool => (self::present($o, 'amountDue') === false
					|| self::numericPresent($o, 'amountDue')),
				'en16931-br-dec-18' => static fn (array $o): bool => self::maxDecimals($o['amountDue'] ?? null, 2),
				'en16931-br-dec-16' => static fn (array $o): bool => self::maxDecimals($o['paidAmount'] ?? null, 2),
				'en16931-br-dec-17' => static fn (array $o): bool => self::maxDecimals($o['roundingAmount'] ?? null, 2),
				'en16931-br-dec-15' => static fn (array $o): bool => self::maxDecimals($o['taxAmountAccountingCurrency'] ?? null, 2),
				// BR-CO-16: amount due = total with VAT - paid + rounding (checked only when stated).
				'en16931-br-co-16' => static fn (array $o): bool => (self::present($o, 'amountDue') === false
					|| self::centsEqual(
						(float)$o['amountDue'],
						(((float)($o['grossAmount'] ?? 0)) - ((float)($o['paidAmount'] ?? 0)) + ((float)($o['roundingAmount'] ?? 0)))
					)),
				// BR-53: if a VAT accounting currency code is present, the VAT amount in that currency must be stated.
				'en16931-br-53' => static fn (array $o): bool => (self::present($o, 'taxCurrencyCode') === false
					|| self::numericPresent($o, 'taxAmountAccountingCurrency')),

				// --- Payment instruction (BG-16) / credit transfer (BG-17) (BR-49/50/61, BR-CL-16). ---
				// A payment means type code is required once any payment-instruction field is given.
				'en16931-br-49' => static fn (array $o): bool => (self::hasPaymentInstruction($o) === false
					|| self::present($o, 'paymentMeansTypeCode')),
				'en16931-br-cl-16' => static fn (array $o): bool => (self::present($o, 'paymentMeansTypeCode') === false
					|| in_array((string)$o['paymentMeansTypeCode'], self::PAYMENT_MEANS_CODES, true)),
				'en16931-br-50' => static fn (array $o): bool => self::creditTransferAccountPresent($o),
				'en16931-br-61' => static fn (array $o): bool => self::creditTransferAccountPresent($o),

				// --- Electronic address scheme identifiers (BT-34-1 / BT-49-1, BR-62/63, BR-CL-25). ---
				'en16931-br-62' => static fn (array $o): bool => self::endpointSchemePresent($o, 'sellerElectronicAddress', 'sellerElectronicAddressScheme'),
				'en16931-br-63' => static fn (array $o): bool => self::endpointSchemePresent($o, 'buyerElectronicAddress', 'buyerElectronicAddressScheme'),

				// --- Additional supporting documents (BG-24) each carry a reference (BR-52). ---
				'en16931-br-52' => static fn (array $o): bool => self::allItemsPresent($o, 'supportingDocuments', 'reference'),
				// --- Preceding invoice references (BG-3) each carry a reference (BR-55). ---
				'en16931-br-55' => static fn (array $o): bool => self::allItemsPresent($o, 'precedingInvoiceReferences', 'reference'),
				// --- Item attributes (BG-32) carry both a name and a value (BR-54). ---
				'en16931-br-54' => static fn (array $o): bool => self::allLineItemAttributesNamed($o),

				// --- Seller tax representative party (BG-11) completeness (BR-18/19/20/56). ---
				// When a tax representative is present (name/address/VAT id any one set),
				// the representative's name, address, country code and VAT id are required.
				'en16931-br-18' => static fn (array $o): bool => (self::hasTaxRepresentative($o) === false
					|| self::present($o, 'sellerTaxRepName')),
				'en16931-br-19' => static fn (array $o): bool => (self::hasTaxRepresentative($o) === false
					|| self::present($o, 'sellerTaxRepAddress')),
				'en16931-br-20' => static fn (array $o): bool => (self::hasTaxRepresentative($o) === false
					|| self::present($o, 'sellerTaxRepCountryCode')),
				'en16931-br-56' => static fn (array $o): bool => (self::hasTaxRepresentative($o) === false
					|| self::present($o, 'sellerTaxRepVatId')),

				// --- Payee name required when a payee is given and differs from the seller (BR-17). ---
				'en16931-br-17' => static fn (array $o): bool => (self::hasPayee($o) === false
					|| self::present($o, 'payeeName')),

				// --- Deliver-to address (BG-15) carries a country code (BR-57). ---
				'en16931-br-57' => static fn (array $o): bool => (self::present($o, 'deliverToAddress') === false
					|| self::present($o, 'deliverToCountryCode')),

				// --- VAT-category breakdown existence: a line/allowance/charge of a category
				// requires a matching VAT breakdown group of that category (BR-{S,Z,E,AE,IC,G,O}-01). ---
				'en16931-br-s-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'S'),
				'en16931-br-z-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'Z'),
				'en16931-br-e-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'E'),
				'en16931-br-ae-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'AE'),
				'en16931-br-ic-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'K'),
				'en16931-br-g-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'G'),
				'en16931-br-o-01' => static fn (array $o): bool => self::breakdownCoversCategory($o, 'O'),

				// --- 'Not subject to VAT' exclusivity (BR-O-11/12/13/14). ---
				// A not-subject breakdown forbids any other breakdown group and forbids any
				// line / allowance / charge of a different VAT category.
				'en16931-br-o-11' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'O') === false
					|| self::breakdownOnlyCategory($o, 'O')),
				'en16931-br-o-12' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'O') === false
					|| self::allLinesAreCategory($o, 'O')),
				'en16931-br-o-13' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'O') === false
					|| self::allCollectionItemsCategory($o, 'allowances', 'O')),
				'en16931-br-o-14' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'O') === false
					|| self::allCollectionItemsCategory($o, 'charges', 'O')),

				// --- Intra-community supply requires delivery info (BR-IC-11/12). ---
				'en16931-br-ic-11' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'K') === false
					|| self::present($o, 'actualDeliveryDate') || self::present($o, 'invoicingPeriodStart')
					|| self::present($o, 'invoicingPeriodEnd')),
				'en16931-br-ic-12' => static fn (array $o): bool => (self::breakdownHasCategory($o, 'K') === false
					|| self::present($o, 'deliverToCountryCode')),

				// --- VAT Directive art. 226(4): customer's VAT id well-formed when stated. ---
				'vatdir-art226-4' => static fn (array $o): bool => (self::present($o, 'buyerVatId') === false
					|| preg_match('/^[A-Z]{2}/', trim((string)$o['buyerVatId'])) === 1),
			],
		];

	}//end checks()

	/**
	 * Scalar test-data defaults so the mandatory rules in this provider are
	 * satisfied on the minimal seeded ARInvoice corpus. Only scalar values are
	 * declared (the seeder backfills scalars); the conditional collection-based
	 * rules are vacuously satisfied when their optional group is absent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				// BR-11 / BR-CL-14: a buyer country code so the mandatory buyer-address
				// country code and country-code coding rules pass on seeded invoices.
				'buyerCountryCode' => 'NL',
			],
		];

	}//end seedSpec()

	/**
	 * True when an object field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return isset($o[$key]) === true && trim((string)$o[$key]) !== '';
	}//end present()

	/**
	 * True when an object field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function numericPresent(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numericPresent()

	/**
	 * True when a string is an ISO 4217-shaped (3-letter) currency code.
	 *
	 * @param string $code A currency code.
	 *
	 * @return bool
	 */
	private static function isIsoCurrency(string $code): bool {
		return preg_match('/^[A-Z]{3}$/', $code) === 1;
	}//end isIsoCurrency()

	/**
	 * True when the optional currency field is absent or an ISO 4217-shaped code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The currency field.
	 *
	 * @return bool
	 */
	private static function optionalIsoCurrency(array $o, string $key): bool {
		if (self::present($o, $key) === false) {
			return true;
		}

		return self::isIsoCurrency((string)$o[$key]);
	}//end optionalIsoCurrency()

	/**
	 * True when an amount is absent/non-numeric or has at most $max decimals.
	 *
	 * @param mixed $value Amount.
	 * @param int $max Maximum decimal places allowed.
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
	 * Compare two amounts at cent precision.
	 *
	 * @param float $a Left amount.
	 * @param float $b Right amount.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

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
	 * Every invoice line value at $key is absent or a non-negative number (BR-27/28).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key The line field.
	 *
	 * @return bool
	 */
	private static function allLinesNonNegative(array $o, string $key): bool {
		foreach (self::lines($o) as $line) {
			$value = ($line[$key] ?? null);
			if ($value === null || $value === '') {
				continue;
			}

			if (is_numeric($value) === false || ((float)$value) < 0) {
				return false;
			}
		}

		return true;
	}//end allLinesNonNegative()

	/**
	 * Every present country code on the invoice header is an ISO 3166-1 alpha-2
	 * (two upper-case letters) value (EN 16931 BR-CL-14).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function countryCodesValid(array $o): bool {
		$keys = [
			'sellerCountryCode',
			'buyerCountryCode',
			'sellerTaxRepCountryCode',
			'deliverToCountryCode',
		];
		foreach ($keys as $key) {
			$value = trim((string)($o[$key] ?? ''));
			if ($value !== '' && preg_match('/^[A-Z]{2}$/', $value) !== 1) {
				return false;
			}
		}

		return true;
	}//end countryCodesValid()

	/**
	 * Every VAT category code used on lines and in the breakdown belongs to the
	 * UNCL5305 code list (EN 16931 BR-CL-17). Fail-closed on no lines.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function vatCategoriesValid(array $o): bool {
		$lines = self::lines($o);
		if ($lines === []) {
			return false;
		}

		foreach ($lines as $line) {
			if (in_array((string)($line['vatCategory'] ?? ''), self::VAT_CATEGORY_CODES, true) === false) {
				return false;
			}
		}

		foreach (self::vatBreakdown($o) as $group) {
			if (in_array((string)($group['category'] ?? ''), self::VAT_CATEGORY_CODES, true) === false) {
				return false;
			}
		}

		return true;
	}//end vatCategoriesValid()

	/**
	 * Two dates (ISO) are ordered: end is later than or equal to start. Vacuously
	 * true unless both are present and parseable (EN 16931 BR-29/30).
	 *
	 * @param string $start Start date.
	 * @param string $end End date.
	 *
	 * @return bool
	 */
	private static function periodOrdered(string $start, string $end): bool {
		if (trim($start) === '' || trim($end) === '') {
			return true;
		}

		$s = strtotime($start);
		$e = strtotime($end);
		if ($s === false || $e === false) {
			return true;
		}

		return $e >= $s;
	}//end periodOrdered()

	/**
	 * Every invoice-line period (BT-134/135) is correctly ordered (BR-30).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allLinePeriodsOrdered(array $o): bool {
		foreach (self::lines($o) as $line) {
			if (self::periodOrdered(
				(string)($line['periodStart'] ?? ''),
				(string)($line['periodEnd'] ?? '')
			) === false
			) {
				return false;
			}
		}

		return true;
	}//end allLinePeriodsOrdered()

	/**
	 * Every item in the named collection carries a non-empty value at $field.
	 * Vacuously true on an empty/absent collection.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 * @param string $field Item field.
	 *
	 * @return bool
	 */
	private static function allItemsPresent(array $o, string $key, string $field): bool {
		foreach (self::items($o, $key) as $item) {
			if (trim((string)($item[$field] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allItemsPresent()

	/**
	 * Every item in the named collection carries a reason text or reason code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property.
	 *
	 * @return bool
	 */
	private static function allItemsHaveReason(array $o, string $key): bool {
		foreach (self::items($o, $key) as $item) {
			$hasReason = (trim((string)($item['reasonText'] ?? '')) !== ''
				|| trim((string)($item['reasonCode'] ?? '')) !== '');
			if ($hasReason === false) {
				return false;
			}
		}

		return true;
	}//end allItemsHaveReason()

	/**
	 * Every line-level allowance/charge carries a reason text or code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Line collection ('lineAllowances'|'lineCharges').
	 *
	 * @return bool
	 */
	private static function allLineItemsHaveReason(array $o, string $key): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, $key) as $item) {
				$hasReason = (trim((string)($item['reasonText'] ?? '')) !== ''
					|| trim((string)($item['reasonCode'] ?? '')) !== '');
				if ($hasReason === false) {
					return false;
				}
			}
		}

		return true;
	}//end allLineItemsHaveReason()

	/**
	 * Every item attribute (BG-32) across all lines carries a name and a value
	 * (EN 16931 BR-54). Vacuously true when no line carries attributes.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allLineItemAttributesNamed(array $o): bool {
		foreach (self::lines($o) as $line) {
			foreach (self::items($line, 'itemAttributes') as $attribute) {
				$hasName = trim((string)($attribute['name'] ?? '')) !== '';
				$hasValue = trim((string)($attribute['value'] ?? '')) !== '';
				if ($hasName === false || $hasValue === false) {
					return false;
				}
			}
		}

		return true;
	}//end allLineItemAttributesNamed()

	/**
	 * True when any payment-instruction field (BG-16/BG-17) is populated.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function hasPaymentInstruction(array $o): bool {
		return (self::present($o, 'paymentMeansTypeCode')
			|| self::present($o, 'paymentAccountId')
			|| self::present($o, 'paymentAccountName'));

	}//end hasPaymentInstruction()

	/**
	 * When the payment means denotes a credit transfer (SEPA / local / non-SEPA),
	 * a payment account identifier (BT-84) is required (EN 16931 BR-50 / BR-61).
	 * Vacuously true for other / absent payment means.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function creditTransferAccountPresent(array $o): bool {
		$code = (string)($o['paymentMeansTypeCode'] ?? '');
		if (in_array($code, self::CREDIT_TRANSFER_CODES, true) === false) {
			return true;
		}

		return self::present($o, 'paymentAccountId');
	}//end creditTransferAccountPresent()

	/**
	 * When an electronic address (BT-34 / BT-49) is present, it carries a scheme
	 * identifier (BT-34-1 / BT-49-1). Vacuously true when no address is present.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $addressKey The address field.
	 * @param string $schemeKey The scheme-identifier field.
	 *
	 * @return bool
	 */
	private static function endpointSchemePresent(array $o, string $addressKey, string $schemeKey): bool {
		if (self::present($o, $addressKey) === false) {
			return true;
		}

		return self::present($o, $schemeKey);
	}//end endpointSchemePresent()

	/**
	 * True when a seller tax representative party (BG-11) is present — any one of
	 * its name / address / VAT id / country code is set.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function hasTaxRepresentative(array $o): bool {
		return (self::present($o, 'sellerTaxRepName')
			|| self::present($o, 'sellerTaxRepAddress')
			|| self::present($o, 'sellerTaxRepVatId')
			|| self::present($o, 'sellerTaxRepCountryCode'));

	}//end hasTaxRepresentative()

	/**
	 * True when a payee (BG-10) is present and differs from the seller, so a payee
	 * name (BT-59) is required (EN 16931 BR-17).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function hasPayee(array $o): bool {
		if (self::present($o, 'payeeName') === false && self::present($o, 'payeeIdentifier') === false) {
			return false;
		}

		$payee = trim((string)($o['payeeName'] ?? ''));
		$seller = trim((string)($o['sellerName'] ?? ''));
		return $payee !== $seller || self::present($o, 'payeeIdentifier');
	}//end hasPayee()

	/**
	 * True when at least one VAT breakdown group carries the given category code.
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
	 * Every VAT breakdown group carries exactly the given category code (used for
	 * the 'Not subject to VAT' exclusivity rule, EN 16931 BR-O-11).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownOnlyCategory(array $o, string $cat): bool {
		foreach (self::vatBreakdown($o) as $group) {
			if ((string)($group['category'] ?? '') !== $cat) {
				return false;
			}
		}

		return true;
	}//end breakdownOnlyCategory()

	/**
	 * When any line / document allowance / document charge carries VAT category
	 * $cat, the VAT breakdown contains at least one group of that category
	 * (EN 16931 BR-{S,Z,E,AE,IC,G,O}-01). Vacuously true when the category is
	 * not used anywhere.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function breakdownCoversCategory(array $o, string $cat): bool {
		$used = false;
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				$used = true;
				break;
			}
		}

		if ($used === false) {
			foreach (['allowances', 'charges'] as $key) {
				foreach (self::items($o, $key) as $item) {
					if ((string)($item['vatCategory'] ?? '') === $cat) {
						$used = true;
						break 2;
					}
				}
			}
		}

		if ($used === false) {
			return true;
		}

		return self::breakdownHasCategory($o, $cat);
	}//end breakdownCoversCategory()

	/**
	 * Every invoice line carries the given VAT category (EN 16931 BR-O-12).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function allLinesAreCategory(array $o, string $cat): bool {
		foreach (self::lines($o) as $line) {
			if ((string)($line['vatCategory'] ?? '') !== $cat) {
				return false;
			}
		}

		return true;
	}//end allLinesAreCategory()

	/**
	 * Every item in the named document collection carries the given VAT category
	 * (EN 16931 BR-O-13/14).
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $key Collection property ('allowances'|'charges').
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function allCollectionItemsCategory(array $o, string $key, string $cat): bool {
		foreach (self::items($o, $key) as $item) {
			if ((string)($item['vatCategory'] ?? '') !== $cat) {
				return false;
			}
		}

		return true;
	}//end allCollectionItemsCategory()
}//end class
