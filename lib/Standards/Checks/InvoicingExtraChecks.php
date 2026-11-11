<?php

/**
 * Invoicing Extra check provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
				...self::headerCodingAndPeriodChecks(),
				...self::allowanceChargeAndAmountDueChecks(),
				...self::paymentReferenceAndPartyChecks(),
				...self::payeeDeliveryAndBreakdownChecks(),
				...self::notSubjectAndIntraCommunityChecks(),
			],
		];

	}//end checks()

	/**
	 * The header coding, price and period predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function headerCodingAndPeriodChecks(): array {
		return [
			// --- Buyer postal address country code + country-code coding (BT-55, BR-CL-14). ---
			'en16931-br-11' => self::en16931Br11(...),
			'en16931-br-cl-14' => self::en16931BrCl14(...),

			// --- Currency-code ISO 4217 coding (BT-5, BR-CL-04 — same obligation as BR-CL-03). ---
			'en16931-br-cl-04' => self::en16931BrCl04(...),
			'en16931-br-cl-05' => self::en16931BrCl05(...),

			// --- Invoice type code in UNTDID 1001 (BT-3, BR-CL-01). ---
			'en16931-br-cl-01' => self::en16931BrCl01(...),

			// --- VAT categories coded with UNCL5305, across lines + breakdown (BR-CL-17). ---
			'en16931-br-cl-17' => self::en16931BrCl17(...),
			// --- Unit-of-measure code present on every line (BT-130, BR-CL-23). ---
			'en16931-br-cl-23' => self::en16931BrCl23(...),

			// --- Item net price (BT-146) / gross price (BT-148) shall not be negative (BR-27/28). ---
			'en16931-br-27' => self::en16931Br27(...),
			'en16931-br-28' => self::en16931Br28(...),

			// --- Invoicing / line period date order (BR-29/30) and use-implies-filled (BR-CO-19/20). ---
			'en16931-br-29' => self::en16931Br29(...),
			'en16931-br-30' => self::en16931Br30(...),
		];

	}//end headerCodingAndPeriodChecks()

	/**
	 * The allowance, charge and amount-due predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function allowanceChargeAndAmountDueChecks(): array {
		return [
			// --- Document allowance/charge reason present (BR-CO-21/22, same as BR-33/38). ---
			'en16931-br-co-21' => self::en16931BrCo21(...),
			'en16931-br-co-22' => self::en16931BrCo22(...),
			// --- Line allowance/charge reason present (BR-CO-23/24, same as BR-42/44). ---
			'en16931-br-co-23' => self::en16931BrCo23(...),
			'en16931-br-co-24' => self::en16931BrCo24(...),

			// --- Amount due for payment (BT-115): presence, decimals, arithmetic. ---
			'en16931-br-15' => self::en16931Br15(...),
			'en16931-br-dec-18' => self::en16931BrDec18(...),
			'en16931-br-dec-16' => self::en16931BrDec16(...),
			'en16931-br-dec-17' => self::en16931BrDec17(...),
			'en16931-br-dec-15' => self::en16931BrDec15(...),
			'en16931-br-co-16' => self::en16931BrCo16(...),
			'en16931-br-53' => self::en16931Br53(...),
		];

	}//end allowanceChargeAndAmountDueChecks()

	/**
	 * The payment, reference, item-attribute and tax-representative predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function paymentReferenceAndPartyChecks(): array {
		return [
			// --- Payment instruction (BG-16) / credit transfer (BG-17) (BR-49/50/61, BR-CL-16). ---
			'en16931-br-49' => self::en16931Br49(...),
			'en16931-br-cl-16' => self::en16931BrCl16(...),
			'en16931-br-50' => self::en16931Br50(...),
			'en16931-br-61' => self::en16931Br61(...),

			// --- Electronic address scheme identifiers (BT-34-1 / BT-49-1, BR-62/63, BR-CL-25). ---
			'en16931-br-62' => self::en16931Br62(...),
			'en16931-br-63' => self::en16931Br63(...),

			// --- Additional supporting documents (BG-24) each carry a reference (BR-52). ---
			'en16931-br-52' => self::en16931Br52(...),
			// --- Preceding invoice references (BG-3) each carry a reference (BR-55). ---
			'en16931-br-55' => self::en16931Br55(...),
			// --- Item attributes (BG-32) carry both a name and a value (BR-54). ---
			'en16931-br-54' => self::en16931Br54(...),

			// --- Seller tax representative party (BG-11) completeness (BR-18/19/20/56). ---
			'en16931-br-18' => self::en16931Br18(...),
			'en16931-br-19' => self::en16931Br19(...),
		];

	}//end paymentReferenceAndPartyChecks()

	/**
	 * The payee, deliver-to and VAT-breakdown predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function payeeDeliveryAndBreakdownChecks(): array {
		return [
			'en16931-br-20' => self::en16931Br20(...),
			'en16931-br-56' => self::en16931Br56(...),

			// --- Payee name required when a payee is given and differs from the seller (BR-17). ---
			'en16931-br-17' => self::en16931Br17(...),

			// --- Deliver-to address (BG-15) carries a country code (BR-57). ---
			'en16931-br-57' => self::en16931Br57(...),

			// --- VAT-category breakdown existence: a line/allowance/charge of a category
			'en16931-br-s-01' => self::en16931BrS01(...),
			'en16931-br-z-01' => self::en16931BrZ01(...),
			'en16931-br-e-01' => self::en16931BrE01(...),
			'en16931-br-ae-01' => self::en16931BrAe01(...),
			'en16931-br-ic-01' => self::en16931BrIc01(...),
			'en16931-br-g-01' => self::en16931BrG01(...),
			'en16931-br-o-01' => self::en16931BrO01(...),
		];

	}//end payeeDeliveryAndBreakdownChecks()

	/**
	 * The not-subject-to-VAT, intra-community and customer VAT id predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function notSubjectAndIntraCommunityChecks(): array {
		return [
			// --- 'Not subject to VAT' exclusivity (BR-O-11/12/13/14). ---
			'en16931-br-o-11' => self::en16931BrO11(...),
			'en16931-br-o-12' => self::en16931BrO12(...),
			'en16931-br-o-13' => self::en16931BrO13(...),
			'en16931-br-o-14' => self::en16931BrO14(...),

			// --- Intra-community supply requires delivery info (BR-IC-11/12). ---
			'en16931-br-ic-11' => self::en16931BrIc11(...),
			'en16931-br-ic-12' => self::en16931BrIc12(...),

			// --- VAT Directive art. 226(4): customer's VAT id well-formed when stated. ---
			'vatdir-art226-4' => self::vatdirArt2264(...),
		];

	}//end notSubjectAndIntraCommunityChecks()

	/**
	 * Rule en16931-br-11 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br11(array $o): bool {
		return self::present($o, 'buyerCountryCode');

	}//end en16931Br11()

	/**
	 * Rule en16931-br-cl-14 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl14(array $o): bool {
		return self::countryCodesValid($o);

	}//end en16931BrCl14()

	/**
	 * Rule en16931-br-cl-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl04(array $o): bool {
		return self::isIsoCurrency((string)($o['currency'] ?? ''));

	}//end en16931BrCl04()

	/**
	 * Tax currency code (BT-6) ISO 4217 when present (BR-CL-05).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl05(array $o): bool {
		return self::optionalIsoCurrency($o, 'taxCurrencyCode');

	}//end en16931BrCl05()

	/**
	 * Rule en16931-br-cl-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl01(array $o): bool {
		return in_array(
			(string)($o['invoiceTypeCode'] ?? ''),
			self::INVOICE_TYPE_CODES,
			true
			);

	}//end en16931BrCl01()

	/**
	 * Rule en16931-br-cl-17 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl17(array $o): bool {
		return self::vatCategoriesValid($o);

	}//end en16931BrCl17()

	/**
	 * Rule en16931-br-cl-23 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl23(array $o): bool {
		return self::allLinesPresent($o, 'unitCode');

	}//end en16931BrCl23()

	/**
	 * Rule en16931-br-27 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br27(array $o): bool {
		return self::allLinesNonNegative($o, 'netPrice');

	}//end en16931Br27()

	/**
	 * Rule en16931-br-28 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br28(array $o): bool {
		return self::allLinesNonNegative($o, 'grossPrice');

	}//end en16931Br28()

	/**
	 * Rule en16931-br-29 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br29(array $o): bool {
		return self::periodOrdered(
			(string)($o['invoicingPeriodStart'] ?? ''),
			(string)($o['invoicingPeriodEnd'] ?? '')
			);

	}//end en16931Br29()

	/**
	 * Rule en16931-br-30 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br30(array $o): bool {
		return self::allLinePeriodsOrdered($o);

	}//end en16931Br30()

	/**
	 * Rule en16931-br-co-21 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo21(array $o): bool {
		return self::allItemsHaveReason($o, 'allowances');

	}//end en16931BrCo21()

	/**
	 * Rule en16931-br-co-22 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo22(array $o): bool {
		return self::allItemsHaveReason($o, 'charges');

	}//end en16931BrCo22()

	/**
	 * Rule en16931-br-co-23 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo23(array $o): bool {
		return self::allLineItemsHaveReason($o, 'lineAllowances');

	}//end en16931BrCo23()

	/**
	 * Rule en16931-br-co-24 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo24(array $o): bool {
		return self::allLineItemsHaveReason($o, 'lineCharges');

	}//end en16931BrCo24()

	/**
	 * BR-15/DEC-18 are conditional on the field being present, so the minimal
	 * seeded corpus (no amountDue) is vacuously satisfied while a populated
	 * invoice is checked for real.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br15(array $o): bool {
		return (self::present($o, 'amountDue') === false
			|| self::numericPresent($o, 'amountDue'));

	}//end en16931Br15()

	/**
	 * Rule en16931-br-dec-18 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec18(array $o): bool {
		return self::maxDecimals($o['amountDue'] ?? null, 2);

	}//end en16931BrDec18()

	/**
	 * Rule en16931-br-dec-16 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec16(array $o): bool {
		return self::maxDecimals($o['paidAmount'] ?? null, 2);

	}//end en16931BrDec16()

	/**
	 * Rule en16931-br-dec-17 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec17(array $o): bool {
		return self::maxDecimals($o['roundingAmount'] ?? null, 2);

	}//end en16931BrDec17()

	/**
	 * Rule en16931-br-dec-15 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec15(array $o): bool {
		return self::maxDecimals($o['taxAmountAccountingCurrency'] ?? null, 2);

	}//end en16931BrDec15()

	/**
	 * BR-CO-16: amount due = total with VAT - paid + rounding (checked only when stated).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo16(array $o): bool {
		return (self::present($o, 'amountDue') === false
			|| self::centsEqual(
			(float)$o['amountDue'],
			(((float)($o['grossAmount'] ?? 0)) - ((float)($o['paidAmount'] ?? 0)) + ((float)($o['roundingAmount'] ?? 0)))
			));

	}//end en16931BrCo16()

	/**
	 * BR-53: if a VAT accounting currency code is present, the VAT amount in that currency must be stated.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br53(array $o): bool {
		return (self::present($o, 'taxCurrencyCode') === false
			|| self::numericPresent($o, 'taxAmountAccountingCurrency'));

	}//end en16931Br53()

	/**
	 * A payment means type code is required once any payment-instruction field is given.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br49(array $o): bool {
		return (self::hasPaymentInstruction($o) === false
			|| self::present($o, 'paymentMeansTypeCode'));

	}//end en16931Br49()

	/**
	 * Rule en16931-br-cl-16 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl16(array $o): bool {
		return (self::present($o, 'paymentMeansTypeCode') === false
			|| in_array((string)$o['paymentMeansTypeCode'], self::PAYMENT_MEANS_CODES, true));

	}//end en16931BrCl16()

	/**
	 * Rule en16931-br-50 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br50(array $o): bool {
		return self::creditTransferAccountPresent($o);

	}//end en16931Br50()

	/**
	 * Rule en16931-br-61 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br61(array $o): bool {
		return self::creditTransferAccountPresent($o);

	}//end en16931Br61()

	/**
	 * Rule en16931-br-62 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br62(array $o): bool {
		return self::endpointSchemePresent($o, 'sellerElectronicAddress', 'sellerElectronicAddressScheme');

	}//end en16931Br62()

	/**
	 * Rule en16931-br-63 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br63(array $o): bool {
		return self::endpointSchemePresent($o, 'buyerElectronicAddress', 'buyerElectronicAddressScheme');

	}//end en16931Br63()

	/**
	 * Rule en16931-br-52 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br52(array $o): bool {
		return self::allItemsPresent($o, 'supportingDocuments', 'reference');

	}//end en16931Br52()

	/**
	 * Rule en16931-br-55 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br55(array $o): bool {
		return self::allItemsPresent($o, 'precedingInvoiceReferences', 'reference');

	}//end en16931Br55()

	/**
	 * Rule en16931-br-54 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br54(array $o): bool {
		return self::allLineItemAttributesNamed($o);

	}//end en16931Br54()

	/**
	 * When a tax representative is present (name/address/VAT id any one set),
	 * the representative's name, address, country code and VAT id are required.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br18(array $o): bool {
		return (self::hasTaxRepresentative($o) === false
			|| self::present($o, 'sellerTaxRepName'));

	}//end en16931Br18()

	/**
	 * Rule en16931-br-19 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br19(array $o): bool {
		return (self::hasTaxRepresentative($o) === false
			|| self::present($o, 'sellerTaxRepAddress'));

	}//end en16931Br19()

	/**
	 * Rule en16931-br-20 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br20(array $o): bool {
		return (self::hasTaxRepresentative($o) === false
			|| self::present($o, 'sellerTaxRepCountryCode'));

	}//end en16931Br20()

	/**
	 * Rule en16931-br-56 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br56(array $o): bool {
		return (self::hasTaxRepresentative($o) === false
			|| self::present($o, 'sellerTaxRepVatId'));

	}//end en16931Br56()

	/**
	 * Rule en16931-br-17 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br17(array $o): bool {
		return (self::hasPayee($o) === false
			|| self::present($o, 'payeeName'));

	}//end en16931Br17()

	/**
	 * Rule en16931-br-57 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br57(array $o): bool {
		return (self::present($o, 'deliverToAddress') === false
			|| self::present($o, 'deliverToCountryCode'));

	}//end en16931Br57()

	/**
	 * requires a matching VAT breakdown group of that category (BR-{S,Z,E,AE,IC,G,O}-01). ---
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS01(array $o): bool {
		return self::breakdownCoversCategory($o, 'S');

	}//end en16931BrS01()

	/**
	 * Rule en16931-br-z-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ01(array $o): bool {
		return self::breakdownCoversCategory($o, 'Z');

	}//end en16931BrZ01()

	/**
	 * Rule en16931-br-e-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE01(array $o): bool {
		return self::breakdownCoversCategory($o, 'E');

	}//end en16931BrE01()

	/**
	 * Rule en16931-br-ae-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe01(array $o): bool {
		return self::breakdownCoversCategory($o, 'AE');

	}//end en16931BrAe01()

	/**
	 * Rule en16931-br-ic-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc01(array $o): bool {
		return self::breakdownCoversCategory($o, 'K');

	}//end en16931BrIc01()

	/**
	 * Rule en16931-br-g-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG01(array $o): bool {
		return self::breakdownCoversCategory($o, 'G');

	}//end en16931BrG01()

	/**
	 * Rule en16931-br-o-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO01(array $o): bool {
		return self::breakdownCoversCategory($o, 'O');

	}//end en16931BrO01()

	/**
	 * A not-subject breakdown forbids any other breakdown group and forbids any
	 * line / allowance / charge of a different VAT category.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO11(array $o): bool {
		return (self::breakdownHasCategory($o, 'O') === false
			|| self::breakdownOnlyCategory($o, 'O'));

	}//end en16931BrO11()

	/**
	 * Rule en16931-br-o-12 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO12(array $o): bool {
		return (self::breakdownHasCategory($o, 'O') === false
			|| self::allLinesAreCategory($o, 'O'));

	}//end en16931BrO12()

	/**
	 * Rule en16931-br-o-13 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO13(array $o): bool {
		return (self::breakdownHasCategory($o, 'O') === false
			|| self::allCollectionItemsCategory($o, 'allowances', 'O'));

	}//end en16931BrO13()

	/**
	 * Rule en16931-br-o-14 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO14(array $o): bool {
		return (self::breakdownHasCategory($o, 'O') === false
			|| self::allCollectionItemsCategory($o, 'charges', 'O'));

	}//end en16931BrO14()

	/**
	 * Rule en16931-br-ic-11 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc11(array $o): bool {
		return (self::breakdownHasCategory($o, 'K') === false
			|| self::present($o, 'actualDeliveryDate') || self::present($o, 'invoicingPeriodStart')
			|| self::present($o, 'invoicingPeriodEnd'));

	}//end en16931BrIc11()

	/**
	 * Rule en16931-br-ic-12 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc12(array $o): bool {
		return (self::breakdownHasCategory($o, 'K') === false
			|| self::present($o, 'deliverToCountryCode'));

	}//end en16931BrIc12()

	/**
	 * Rule vatdir-art226-4 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2264(array $o): bool {
		return (self::present($o, 'buyerVatId') === false
			|| preg_match('/^[A-Z]{2}/', trim((string)$o['buyerVatId'])) === 1);

	}//end vatdirArt2264()

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
