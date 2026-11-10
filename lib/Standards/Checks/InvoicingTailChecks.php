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
	 * The data seedSpec() returns; see there.
	 *
	 * @var array<string, mixed>
	 */
	private const SEED_SPEC = [
		'ARInvoice' => [
			// BR-07 / art. 226(5): a buyer name so the buyer-name presence and the
			// full-name-and-address rules pass on the seeded invoices.
			'buyerName' => 'Seeded Buyer B.V.',
		],
	];


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
				...self::documentLevelChecks(),
				...self::vatCategoryChecks(),
				...self::vatDirectiveContentChecks(),
			],
		];

	}//end checks()

	/**
	 * The presence, cross-total, amount-format and coding predicates, keyed by
	 * rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function documentLevelChecks(): array {
		return [
			...self::headerIdentityPresenceChecks(),
			...self::headerAddressAndTotalPresenceChecks(),
			...self::linePresenceChecks(),
			...self::breakdownPresenceChecks(),
			...self::crossTotalChecks(),
			...self::crossTotalStructureChecks(),
			...self::documentAmountFormatChecks(),
			...self::chargeBreakdownAndLineAmountFormatChecks(),
			...self::cardItemAndCurrencyCodingChecks(),
		];

	}//end documentLevelChecks()

	/**
	 * The per-VAT-category line, allowance, charge and breakdown predicates, keyed
	 * by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function vatCategoryChecks(): array {
		return [
			...self::standardRatedChecks(),
			...self::zeroRatedChecks(),
			...self::exemptChecks(),
			...self::reverseChargeChecks(),
			...self::intraCommunitySupplyChecks(),
			...self::exportChecks(),
			...self::notSubjectToVatChecks(),
		];

	}//end vatCategoryChecks()

	/**
	 * The header identity presence predicates (BR-01..07), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function headerIdentityPresenceChecks(): array {
		return [
			// ===== Header BT-level presence (BR-01..10/12..14/16). =====
			'en16931-br-01' => self::en16931Br01(...),
			'en16931-br-02' => self::en16931Br02(...),
			'en16931-br-03' => self::en16931Br03(...),
			'en16931-br-04' => self::en16931Br04(...),
			'en16931-br-05' => self::en16931Br05(...),
			'en16931-br-06' => self::en16931Br06(...),
			'en16931-br-07' => self::en16931Br07(...),
		];

	}//end headerIdentityPresenceChecks()

	/**
	 * The header address, total and line-count presence predicates (BR-08..16), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function headerAddressAndTotalPresenceChecks(): array {
		return [
			'en16931-br-08' => self::en16931Br08(...),
			'en16931-br-09' => self::en16931Br09(...),
			'en16931-br-10' => self::en16931Br10(...),
			'en16931-br-12' => self::en16931Br12(...),
			'en16931-br-13' => self::en16931Br13(...),
			'en16931-br-14' => self::en16931Br14(...),
			'en16931-br-16' => self::en16931Br16(...),
		];

	}//end headerAddressAndTotalPresenceChecks()

	/**
	 * The invoice-line presence predicates (BR-21..26), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function linePresenceChecks(): array {
		return [
			// ===== Invoice-line BT-level presence (BR-21..26). =====
			'en16931-br-21' => self::en16931Br21(...),
			'en16931-br-22' => self::en16931Br22(...),
			'en16931-br-23' => self::en16931Br23(...),
			'en16931-br-24' => self::en16931Br24(...),
			'en16931-br-25' => self::en16931Br25(...),
			'en16931-br-26' => self::en16931Br26(...),
		];

	}//end linePresenceChecks()

	/**
	 * The VAT-breakdown presence predicates (BR-45..48), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function breakdownPresenceChecks(): array {
		return [
			// ===== VAT breakdown BT-level presence (BR-45..48). =====
			'en16931-br-45' => self::en16931Br45(...),
			'en16931-br-46' => self::en16931Br46(...),
			'en16931-br-47' => self::en16931Br47(...),
			'en16931-br-48' => self::en16931Br48(...),
		];

	}//end breakdownPresenceChecks()

	/**
	 * The cross-total arithmetic predicates (BR-CO-03..12), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function crossTotalChecks(): array {
		return [
			// ===== Cross-total arithmetic / structural (BR-CO-03/04/09..14/17/18). =====
			'en16931-br-co-03' => self::en16931BrCo03(...),
			'en16931-br-co-04' => self::en16931BrCo04(...),
			'en16931-br-co-09' => self::en16931BrCo09(...),
			'en16931-br-co-10' => self::en16931BrCo10(...),
			'en16931-br-co-11' => self::en16931BrCo11(...),
			'en16931-br-co-12' => self::en16931BrCo12(...),
		];

	}//end crossTotalChecks()

	/**
	 * The remaining cross-total and structural predicates (BR-CO-13..26), keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function crossTotalStructureChecks(): array {
		return [
			'en16931-br-co-13' => self::en16931BrCo13(...),
			'en16931-br-co-14' => self::en16931BrCo14(...),
			'en16931-br-co-17' => self::en16931BrCo17(...),
			'en16931-br-co-18' => self::en16931BrCo18(...),
			'en16931-br-co-25' => self::en16931BrCo25(...),
			'en16931-br-co-26' => self::en16931BrCo26(...),
		];

	}//end crossTotalStructureChecks()

	/**
	 * The two-decimal format predicates for document totals, allowances and charges, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function documentAmountFormatChecks(): array {
		return [
			// ===== Two-decimal money format (BR-DEC-*). =====
			'en16931-br-dec-09' => self::en16931BrDec09(...),
			'en16931-br-dec-10' => self::en16931BrDec10(...),
			'en16931-br-dec-11' => self::en16931BrDec11(...),
			'en16931-br-dec-12' => self::en16931BrDec12(...),
			'en16931-br-dec-13' => self::en16931BrDec13(...),
			'en16931-br-dec-14' => self::en16931BrDec14(...),
			'en16931-br-dec-01' => self::en16931BrDec01(...),
			'en16931-br-dec-02' => self::en16931BrDec02(...),
			'en16931-br-dec-05' => self::en16931BrDec05(...),
		];

	}//end documentAmountFormatChecks()

	/**
	 * The two-decimal format predicates for charges, breakdown groups and lines, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function chargeBreakdownAndLineAmountFormatChecks(): array {
		return [
			'en16931-br-dec-06' => self::en16931BrDec06(...),
			'en16931-br-dec-19' => self::en16931BrDec19(...),
			'en16931-br-dec-20' => self::en16931BrDec20(...),
			'en16931-br-dec-23' => self::en16931BrDec23(...),
			'en16931-br-dec-24' => self::en16931BrDec24(...),
			'en16931-br-dec-25' => self::en16931BrDec25(...),
			'en16931-br-dec-27' => self::en16931BrDec27(...),
			'en16931-br-dec-28' => self::en16931BrDec28(...),
		];

	}//end chargeBreakdownAndLineAmountFormatChecks()

	/**
	 * The card-security, item-identifier scheme and currency-coding predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function cardItemAndCurrencyCodingChecks(): array {
		return [
			// ===== Card security / item identifier scheme / currency coding. =====
			'en16931-br-51' => self::en16931Br51(...),
			'en16931-br-64' => self::en16931Br64(...),
			'en16931-br-65' => self::en16931Br65(...),
			'en16931-br-cl-03' => self::en16931BrCl03(...),
		];

	}//end cardItemAndCurrencyCodingChecks()

	/**
	 * The standard-rated (S) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function standardRatedChecks(): array {
		return [
			// ===== Per-VAT-category line / allowance / charge / breakdown rules. =====
			'en16931-br-s-02' => self::en16931BrS02(...),
			'en16931-br-s-03' => self::en16931BrS03(...),
			'en16931-br-s-04' => self::en16931BrS04(...),
			'en16931-br-s-05' => self::en16931BrS05(...),
			'en16931-br-s-06' => self::en16931BrS06(...),
			'en16931-br-s-07' => self::en16931BrS07(...),
			'en16931-br-s-08' => self::en16931BrS08(...),
			'en16931-br-s-09' => self::en16931BrS09(...),
			'en16931-br-s-10' => self::en16931BrS10(...),
		];

	}//end standardRatedChecks()

	/**
	 * The zero-rated (Z) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function zeroRatedChecks(): array {
		return [
			'en16931-br-z-02' => self::en16931BrZ02(...),
			'en16931-br-z-03' => self::en16931BrZ03(...),
			'en16931-br-z-04' => self::en16931BrZ04(...),
			'en16931-br-z-05' => self::en16931BrZ05(...),
			'en16931-br-z-06' => self::en16931BrZ06(...),
			'en16931-br-z-07' => self::en16931BrZ07(...),
			'en16931-br-z-08' => self::en16931BrZ08(...),
			'en16931-br-z-09' => self::en16931BrZ09(...),
			'en16931-br-z-10' => self::en16931BrZ10(...),
		];

	}//end zeroRatedChecks()

	/**
	 * The exempt (E) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function exemptChecks(): array {
		return [
			'en16931-br-e-02' => self::en16931BrE02(...),
			'en16931-br-e-03' => self::en16931BrE03(...),
			'en16931-br-e-04' => self::en16931BrE04(...),
			'en16931-br-e-05' => self::en16931BrE05(...),
			'en16931-br-e-06' => self::en16931BrE06(...),
			'en16931-br-e-07' => self::en16931BrE07(...),
			'en16931-br-e-08' => self::en16931BrE08(...),
			'en16931-br-e-09' => self::en16931BrE09(...),
			'en16931-br-e-10' => self::en16931BrE10(...),
		];

	}//end exemptChecks()

	/**
	 * The reverse-charge (AE) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function reverseChargeChecks(): array {
		return [
			'en16931-br-ae-02' => self::en16931BrAe02(...),
			'en16931-br-ae-03' => self::en16931BrAe03(...),
			'en16931-br-ae-04' => self::en16931BrAe04(...),
			'en16931-br-ae-05' => self::en16931BrAe05(...),
			'en16931-br-ae-06' => self::en16931BrAe06(...),
			'en16931-br-ae-07' => self::en16931BrAe07(...),
			'en16931-br-ae-08' => self::en16931BrAe08(...),
			'en16931-br-ae-09' => self::en16931BrAe09(...),
			'en16931-br-ae-10' => self::en16931BrAe10(...),
		];

	}//end reverseChargeChecks()

	/**
	 * The intra-community supply (K) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function intraCommunitySupplyChecks(): array {
		return [
			'en16931-br-ic-02' => self::en16931BrIc02(...),
			'en16931-br-ic-03' => self::en16931BrIc03(...),
			'en16931-br-ic-04' => self::en16931BrIc04(...),
			'en16931-br-ic-05' => self::en16931BrIc05(...),
			'en16931-br-ic-06' => self::en16931BrIc06(...),
			'en16931-br-ic-07' => self::en16931BrIc07(...),
			'en16931-br-ic-08' => self::en16931BrIc08(...),
			'en16931-br-ic-09' => self::en16931BrIc09(...),
			'en16931-br-ic-10' => self::en16931BrIc10(...),
		];

	}//end intraCommunitySupplyChecks()

	/**
	 * The export outside the EU (G) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function exportChecks(): array {
		return [
			'en16931-br-g-02' => self::en16931BrG02(...),
			'en16931-br-g-03' => self::en16931BrG03(...),
			'en16931-br-g-04' => self::en16931BrG04(...),
			'en16931-br-g-05' => self::en16931BrG05(...),
			'en16931-br-g-06' => self::en16931BrG06(...),
			'en16931-br-g-07' => self::en16931BrG07(...),
			'en16931-br-g-08' => self::en16931BrG08(...),
			'en16931-br-g-09' => self::en16931BrG09(...),
			'en16931-br-g-10' => self::en16931BrG10(...),
		];

	}//end exportChecks()

	/**
	 * The not-subject-to-VAT (O) category predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function notSubjectToVatChecks(): array {
		return [
			'en16931-br-o-02' => self::en16931BrO02(...),
			'en16931-br-o-03' => self::en16931BrO03(...),
			'en16931-br-o-04' => self::en16931BrO04(...),
			'en16931-br-o-05' => self::en16931BrO05(...),
			'en16931-br-o-06' => self::en16931BrO06(...),
			'en16931-br-o-07' => self::en16931BrO07(...),
			'en16931-br-o-08' => self::en16931BrO08(...),
			'en16931-br-o-09' => self::en16931BrO09(...),
			'en16931-br-o-10' => self::en16931BrO10(...),
		];

	}//end notSubjectToVatChecks()

	/**
	 * The VAT Directive art. 226 invoice-content predicates, keyed by rule id, in registry order.
	 *
	 * @return array<string, callable>
	 */
	private static function vatDirectiveContentChecks(): array {
		return [
			// ===== VAT Directive art. 226 content (presence). =====
			'vatdir-art226-1' => self::vatdirArt2261(...),
			'vatdir-art226-2' => self::vatdirArt2262(...),
			'vatdir-art226-3' => self::vatdirArt2263(...),
			'vatdir-art226-5' => self::vatdirArt2265(...),
			'vatdir-art226-8' => self::vatdirArt2268(...),
			'vatdir-art226-9' => self::vatdirArt2269(...),
			'vatdir-art226-10' => self::vatdirArt22610(...),
		];

	}//end vatDirectiveContentChecks()

	/**
	 * BR-01: Specification identifier (BT-24).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br01(array $o): bool {
		return self::present($o, 'specificationId');

	}//end en16931Br01()

	/**
	 * BR-02: Invoice number (BT-1).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br02(array $o): bool {
		return self::present($o, 'invoiceNumber');

	}//end en16931Br02()

	/**
	 * BR-03: Invoice issue date (BT-2).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br03(array $o): bool {
		return self::present($o, 'invoiceDate');

	}//end en16931Br03()

	/**
	 * BR-04: Invoice type code (BT-3).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br04(array $o): bool {
		return self::present($o, 'invoiceTypeCode');

	}//end en16931Br04()

	/**
	 * BR-05: Invoice currency code (BT-5).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br05(array $o): bool {
		return self::present($o, 'currency');

	}//end en16931Br05()

	/**
	 * BR-06: Seller name (BT-27).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br06(array $o): bool {
		return self::present($o, 'sellerName');

	}//end en16931Br06()

	/**
	 * BR-07: Buyer name (BT-44).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br07(array $o): bool {
		return self::present($o, 'buyerName');

	}//end en16931Br07()

	/**
	 * BR-08: Seller postal address (BG-5).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br08(array $o): bool {
		return self::present($o, 'sellerAddress');

	}//end en16931Br08()

	/**
	 * BR-09: Seller country code (BT-40) within the seller address.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br09(array $o): bool {
		return self::present($o, 'sellerCountryCode');

	}//end en16931Br09()

	/**
	 * BR-10: Buyer postal address (BG-8).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br10(array $o): bool {
		return self::present($o, 'buyerAddress');

	}//end en16931Br10()

	/**
	 * BR-12: Sum of Invoice line net amount (BT-106).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br12(array $o): bool {
		return self::numericPresent($o, 'lineNetTotal');

	}//end en16931Br12()

	/**
	 * BR-13: Invoice total amount without VAT (BT-109).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br13(array $o): bool {
		return self::numericPresent($o, 'netAmount');

	}//end en16931Br13()

	/**
	 * BR-14: Invoice total amount with VAT (BT-112).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br14(array $o): bool {
		return self::numericPresent($o, 'grossAmount');

	}//end en16931Br14()

	/**
	 * BR-16: at least one Invoice line (BG-25).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br16(array $o): bool {
		return self::lines($o) !== [];

	}//end en16931Br16()

	/**
	 * BR-21: Invoice line identifier (BT-126).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br21(array $o): bool {
		return self::allLinesPresent($o, 'lineId');

	}//end en16931Br21()

	/**
	 * BR-22: Invoiced quantity (BT-129).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br22(array $o): bool {
		return self::allLinesNumeric($o, 'quantity');

	}//end en16931Br22()

	/**
	 * BR-23: Invoiced quantity unit of measure code (BT-130).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br23(array $o): bool {
		return self::allLinesPresent($o, 'unitCode');

	}//end en16931Br23()

	/**
	 * BR-24: Invoice line net amount (BT-131).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br24(array $o): bool {
		return self::allLinesNumeric($o, 'netAmount');

	}//end en16931Br24()

	/**
	 * BR-25: Item name (BT-153).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br25(array $o): bool {
		return self::allLinesPresent($o, 'itemName');

	}//end en16931Br25()

	/**
	 * BR-26: Item net price (BT-146).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br26(array $o): bool {
		return self::allLinesNumeric($o, 'netPrice');

	}//end en16931Br26()

	/**
	 * BR-45: VAT category taxable amount (BT-116).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br45(array $o): bool {
		return self::allBreakdownNumeric($o, 'taxableAmount');

	}//end en16931Br45()

	/**
	 * BR-46: VAT category tax amount (BT-117).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br46(array $o): bool {
		return self::allBreakdownNumeric($o, 'taxAmount');

	}//end en16931Br46()

	/**
	 * BR-47: VAT category code (BT-118) on each breakdown group.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br47(array $o): bool {
		return self::allBreakdownPresent($o, 'category');

	}//end en16931Br47()

	/**
	 * BR-48: VAT category rate (BT-119), except where not subject to VAT ('O').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br48(array $o): bool {
		return self::breakdownRatePresentUnlessUntaxed($o);

	}//end en16931Br48()

	/**
	 * BR-CO-03: tax point date (BT-7) and tax point date code (BT-8) mutually exclusive.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo03(array $o): bool {
		return (self::present($o, 'taxPointDate') === false
			|| self::present($o, 'taxPointDateCode') === false);

	}//end en16931BrCo03()

	/**
	 * BR-CO-04: every line categorised with a VAT category code (BT-151).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo04(array $o): bool {
		return self::allLinesPresent($o, 'vatCategory');

	}//end en16931BrCo04()

	/**
	 * BR-CO-09: seller / tax-rep / buyer VAT id (when present) carry an ISO-3166 prefix.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo09(array $o): bool {
		return self::vatIdPrefixOk($o, 'sellerVatId')
			&& self::vatIdPrefixOk($o, 'sellerTaxRepVatId')
			&& self::vatIdPrefixOk($o, 'buyerVatId');

	}//end en16931BrCo09()

	/**
	 * BR-CO-10: Σ line net amount = sum of invoice line net amounts.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo10(array $o): bool {
		return self::centsEqual(
			self::numericVal($o, 'lineNetTotal'),
			self::sumLines($o, 'netAmount')
			);

	}//end en16931BrCo10()

	/**
	 * BR-CO-11: sum of document-level allowances = Σ allowance amounts.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo11(array $o): bool {
		return self::centsEqual(
			self::numericVal($o, 'allowancesTotal'),
			self::sumItems($o, 'allowances', 'amount')
			);

	}//end en16931BrCo11()

	/**
	 * BR-CO-12: sum of document-level charges = Σ charge amounts.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo12(array $o): bool {
		return self::centsEqual(
			self::numericVal($o, 'chargesTotal'),
			self::sumItems($o, 'charges', 'amount')
			);

	}//end en16931BrCo12()

	/**
	 * BR-CO-13: total without VAT = Σ lines - allowances + charges.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo13(array $o): bool {
		return self::centsEqual(
			self::numericVal($o, 'netAmount'),
			(self::numericVal($o, 'lineNetTotal') - self::numericVal($o, 'allowancesTotal') + self::numericVal($o, 'chargesTotal'))
			);

	}//end en16931BrCo13()

	/**
	 * BR-CO-14: total VAT amount = Σ breakdown tax amounts.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo14(array $o): bool {
		return self::centsEqual(
			self::numericVal($o, 'vatAmount'),
			self::sumBreakdown($o, 'taxAmount')
			);

	}//end en16931BrCo14()

	/**
	 * BR-CO-17: each breakdown tax amount = taxable amount x rate / 100 (2 dp).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo17(array $o): bool {
		return self::allBreakdownTaxComputed($o);

	}//end en16931BrCo17()

	/**
	 * BR-CO-18: at least one VAT breakdown group (BG-23).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo18(array $o): bool {
		return self::vatBreakdown($o) !== [];

	}//end en16931BrCo18()

	/**
	 * (BR-CO-19 / BR-CO-20 dropped: their predicates reduced to tautologies
	 * — !A || A and an unconditional return true — flagged structurally
	 * vacuous by the workflow verify stage.)
	 * BR-CO-25: amount due positive -> payment due date or payment terms present.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo25(array $o): bool {
		return (self::numericVal($o, 'amountDue') <= 0.0
			|| self::present($o, 'dueDate') || self::present($o, 'paymentTerms'));

	}//end en16931BrCo25()

	/**
	 * BR-CO-26: seller identifiable -> seller id, legal reg id and/or VAT id present.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCo26(array $o): bool {
		return (self::present($o, 'sellerIdentifier')
			|| self::present($o, 'sellerTaxRegId') || self::present($o, 'sellerVatId'));

	}//end en16931BrCo26()

	/**
	 * Rule en16931-br-dec-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec09(array $o): bool {
		return self::maxDecimals($o['lineNetTotal'] ?? null, 2);

	}//end en16931BrDec09()

	/**
	 * Rule en16931-br-dec-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec10(array $o): bool {
		return self::maxDecimals($o['allowancesTotal'] ?? null, 2);

	}//end en16931BrDec10()

	/**
	 * Rule en16931-br-dec-11 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec11(array $o): bool {
		return self::maxDecimals($o['chargesTotal'] ?? null, 2);

	}//end en16931BrDec11()

	/**
	 * Rule en16931-br-dec-12 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec12(array $o): bool {
		return self::maxDecimals($o['netAmount'] ?? null, 2);

	}//end en16931BrDec12()

	/**
	 * Rule en16931-br-dec-13 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec13(array $o): bool {
		return self::maxDecimals($o['vatAmount'] ?? null, 2);

	}//end en16931BrDec13()

	/**
	 * Rule en16931-br-dec-14 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec14(array $o): bool {
		return self::maxDecimals($o['grossAmount'] ?? null, 2);

	}//end en16931BrDec14()

	/**
	 * Rule en16931-br-dec-01 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec01(array $o): bool {
		return self::itemsMaxDecimals($o, 'allowances', 'amount');

	}//end en16931BrDec01()

	/**
	 * Rule en16931-br-dec-02 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec02(array $o): bool {
		return self::itemsMaxDecimals($o, 'allowances', 'baseAmount');

	}//end en16931BrDec02()

	/**
	 * Rule en16931-br-dec-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec05(array $o): bool {
		return self::itemsMaxDecimals($o, 'charges', 'amount');

	}//end en16931BrDec05()

	/**
	 * Rule en16931-br-dec-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec06(array $o): bool {
		return self::itemsMaxDecimals($o, 'charges', 'baseAmount');

	}//end en16931BrDec06()

	/**
	 * Rule en16931-br-dec-19 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec19(array $o): bool {
		return self::breakdownMaxDecimals($o, 'taxableAmount');

	}//end en16931BrDec19()

	/**
	 * Rule en16931-br-dec-20 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec20(array $o): bool {
		return self::breakdownMaxDecimals($o, 'taxAmount');

	}//end en16931BrDec20()

	/**
	 * Rule en16931-br-dec-23 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec23(array $o): bool {
		return self::linesMaxDecimals($o, 'netAmount');

	}//end en16931BrDec23()

	/**
	 * Rule en16931-br-dec-24 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec24(array $o): bool {
		return self::lineItemsMaxDecimals($o, 'lineAllowances', 'amount');

	}//end en16931BrDec24()

	/**
	 * Rule en16931-br-dec-25 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec25(array $o): bool {
		return self::lineItemsMaxDecimals($o, 'lineAllowances', 'baseAmount');

	}//end en16931BrDec25()

	/**
	 * Rule en16931-br-dec-27 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec27(array $o): bool {
		return self::lineItemsMaxDecimals($o, 'lineCharges', 'amount');

	}//end en16931BrDec27()

	/**
	 * Rule en16931-br-dec-28 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrDec28(array $o): bool {
		return self::lineItemsMaxDecimals($o, 'lineCharges', 'baseAmount');

	}//end en16931BrDec28()

	/**
	 * BR-51: never include a full card primary account number (BT-87).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br51(array $o): bool {
		return self::present($o, 'cardPan') === false;

	}//end en16931Br51()

	/**
	 * BR-64: item standard identifier (BT-157) carries a scheme on every line.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br64(array $o): bool {
		return self::lineIdentifierSchemed($o, 'itemStandardId', 'itemStandardIdScheme');

	}//end en16931Br64()

	/**
	 * BR-65: item classification identifier (BT-158) carries a scheme on every line.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931Br65(array $o): bool {
		return self::lineIdentifierSchemed($o, 'itemClassificationId', 'itemClassificationIdScheme');

	}//end en16931Br65()

	/**
	 * BR-CL-03: invoice currency code (BT-5) drawn from ISO 4217 alpha-3.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrCl03(array $o): bool {
		return in_array(
			strtoupper(trim((string)($o['currency'] ?? ''))),
			self::ISO_CURRENCIES,
			true
			);

	}//end en16931BrCl03()

	/**
	 * Standard rated ('S').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS02(array $o): bool {
		return self::sellerVatWhenCategory($o, 'S');

	}//end en16931BrS02()

	/**
	 * Rule en16931-br-s-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'S');

	}//end en16931BrS03()

	/**
	 * Rule en16931-br-s-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'S');

	}//end en16931BrS04()

	/**
	 * Rule en16931-br-s-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS05(array $o): bool {
		return self::lineRatePositiveForCategory($o, 'S');

	}//end en16931BrS05()

	/**
	 * Rule en16931-br-s-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS06(array $o): bool {
		return self::collectionRatePositiveForCategory($o, 'allowances', 'S');

	}//end en16931BrS06()

	/**
	 * Rule en16931-br-s-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS07(array $o): bool {
		return self::collectionRatePositiveForCategory($o, 'charges', 'S');

	}//end en16931BrS07()

	/**
	 * Rule en16931-br-s-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'S');

	}//end en16931BrS08()

	/**
	 * Rule en16931-br-s-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS09(array $o): bool {
		return self::breakdownTaxByRate($o, 'S');

	}//end en16931BrS09()

	/**
	 * Rule en16931-br-s-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrS10(array $o): bool {
		return self::breakdownNoExemptionReason($o, 'S');

	}//end en16931BrS10()

	/**
	 * Zero rated ('Z').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ02(array $o): bool {
		return self::sellerVatWhenCategory($o, 'Z');

	}//end en16931BrZ02()

	/**
	 * Rule en16931-br-z-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'Z');

	}//end en16931BrZ03()

	/**
	 * Rule en16931-br-z-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'Z');

	}//end en16931BrZ04()

	/**
	 * Rule en16931-br-z-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ05(array $o): bool {
		return self::lineRateZeroForCategory($o, 'Z');

	}//end en16931BrZ05()

	/**
	 * Rule en16931-br-z-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ06(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'allowances', 'Z');

	}//end en16931BrZ06()

	/**
	 * Rule en16931-br-z-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ07(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'charges', 'Z');

	}//end en16931BrZ07()

	/**
	 * Rule en16931-br-z-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'Z');

	}//end en16931BrZ08()

	/**
	 * Rule en16931-br-z-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ09(array $o): bool {
		return self::breakdownTaxZero($o, 'Z');

	}//end en16931BrZ09()

	/**
	 * Rule en16931-br-z-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrZ10(array $o): bool {
		return self::breakdownNoExemptionReason($o, 'Z');

	}//end en16931BrZ10()

	/**
	 * Exempt from VAT ('E').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE02(array $o): bool {
		return self::sellerVatWhenCategory($o, 'E');

	}//end en16931BrE02()

	/**
	 * Rule en16931-br-e-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'E');

	}//end en16931BrE03()

	/**
	 * Rule en16931-br-e-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'E');

	}//end en16931BrE04()

	/**
	 * Rule en16931-br-e-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE05(array $o): bool {
		return self::lineRateZeroForCategory($o, 'E');

	}//end en16931BrE05()

	/**
	 * Rule en16931-br-e-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE06(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'allowances', 'E');

	}//end en16931BrE06()

	/**
	 * Rule en16931-br-e-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE07(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'charges', 'E');

	}//end en16931BrE07()

	/**
	 * Rule en16931-br-e-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'E');

	}//end en16931BrE08()

	/**
	 * Rule en16931-br-e-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE09(array $o): bool {
		return self::breakdownTaxZero($o, 'E');

	}//end en16931BrE09()

	/**
	 * Rule en16931-br-e-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrE10(array $o): bool {
		return self::breakdownHasExemptionReason($o, 'E');

	}//end en16931BrE10()

	/**
	 * Reverse charge ('AE').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe02(array $o): bool {
		return self::reverseChargePartiesIdentified($o);

	}//end en16931BrAe02()

	/**
	 * Rule en16931-br-ae-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'AE');

	}//end en16931BrAe03()

	/**
	 * Rule en16931-br-ae-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'AE');

	}//end en16931BrAe04()

	/**
	 * Rule en16931-br-ae-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe05(array $o): bool {
		return self::lineRateZeroForCategory($o, 'AE');

	}//end en16931BrAe05()

	/**
	 * Rule en16931-br-ae-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe06(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'allowances', 'AE');

	}//end en16931BrAe06()

	/**
	 * Rule en16931-br-ae-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe07(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'charges', 'AE');

	}//end en16931BrAe07()

	/**
	 * Rule en16931-br-ae-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'AE');

	}//end en16931BrAe08()

	/**
	 * Rule en16931-br-ae-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe09(array $o): bool {
		return self::breakdownTaxZero($o, 'AE');

	}//end en16931BrAe09()

	/**
	 * Rule en16931-br-ae-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrAe10(array $o): bool {
		return self::breakdownHasExemptionReason($o, 'AE');

	}//end en16931BrAe10()

	/**
	 * Intra-community supply ('K').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc02(array $o): bool {
		return self::intraCommunityPartiesIdentified($o);

	}//end en16931BrIc02()

	/**
	 * Rule en16931-br-ic-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'K');

	}//end en16931BrIc03()

	/**
	 * Rule en16931-br-ic-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'K');

	}//end en16931BrIc04()

	/**
	 * Rule en16931-br-ic-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc05(array $o): bool {
		return self::lineRateZeroForCategory($o, 'K');

	}//end en16931BrIc05()

	/**
	 * Rule en16931-br-ic-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc06(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'allowances', 'K');

	}//end en16931BrIc06()

	/**
	 * Rule en16931-br-ic-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc07(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'charges', 'K');

	}//end en16931BrIc07()

	/**
	 * Rule en16931-br-ic-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'K');

	}//end en16931BrIc08()

	/**
	 * Rule en16931-br-ic-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc09(array $o): bool {
		return self::breakdownTaxZero($o, 'K');

	}//end en16931BrIc09()

	/**
	 * Rule en16931-br-ic-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrIc10(array $o): bool {
		return self::breakdownHasExemptionReason($o, 'K');

	}//end en16931BrIc10()

	/**
	 * Export outside the EU ('G').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG02(array $o): bool {
		return self::sellerVatWhenCategory($o, 'G');

	}//end en16931BrG02()

	/**
	 * Rule en16931-br-g-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG03(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'allowances', 'G');

	}//end en16931BrG03()

	/**
	 * Rule en16931-br-g-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG04(array $o): bool {
		return self::sellerVatWhenCollectionCategory($o, 'charges', 'G');

	}//end en16931BrG04()

	/**
	 * Rule en16931-br-g-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG05(array $o): bool {
		return self::lineRateZeroForCategory($o, 'G');

	}//end en16931BrG05()

	/**
	 * Rule en16931-br-g-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG06(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'allowances', 'G');

	}//end en16931BrG06()

	/**
	 * Rule en16931-br-g-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG07(array $o): bool {
		return self::collectionRateZeroForCategory($o, 'charges', 'G');

	}//end en16931BrG07()

	/**
	 * Rule en16931-br-g-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'G');

	}//end en16931BrG08()

	/**
	 * Rule en16931-br-g-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG09(array $o): bool {
		return self::breakdownTaxZero($o, 'G');

	}//end en16931BrG09()

	/**
	 * Rule en16931-br-g-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrG10(array $o): bool {
		return self::breakdownHasExemptionReason($o, 'G');

	}//end en16931BrG10()

	/**
	 * Not subject to VAT ('O').
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO02(array $o): bool {
		return self::sellerVatAbsentWhenCategory($o, 'O');

	}//end en16931BrO02()

	/**
	 * Rule en16931-br-o-03 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO03(array $o): bool {
		return self::sellerVatAbsentWhenCollectionCategory($o, 'allowances', 'O');

	}//end en16931BrO03()

	/**
	 * Rule en16931-br-o-04 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO04(array $o): bool {
		return self::sellerVatAbsentWhenCollectionCategory($o, 'charges', 'O');

	}//end en16931BrO04()

	/**
	 * Rule en16931-br-o-05 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO05(array $o): bool {
		return self::lineRateAbsentForCategory($o, 'O');

	}//end en16931BrO05()

	/**
	 * Rule en16931-br-o-06 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO06(array $o): bool {
		return self::collectionRateAbsentForCategory($o, 'allowances', 'O');

	}//end en16931BrO06()

	/**
	 * Rule en16931-br-o-07 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO07(array $o): bool {
		return self::collectionRateAbsentForCategory($o, 'charges', 'O');

	}//end en16931BrO07()

	/**
	 * Rule en16931-br-o-08 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO08(array $o): bool {
		return self::breakdownTaxableReconciles($o, 'O');

	}//end en16931BrO08()

	/**
	 * Rule en16931-br-o-09 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO09(array $o): bool {
		return self::breakdownTaxZero($o, 'O');

	}//end en16931BrO09()

	/**
	 * Rule en16931-br-o-10 holds.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function en16931BrO10(array $o): bool {
		return self::breakdownHasExemptionReason($o, 'O');

	}//end en16931BrO10()

	/**
	 * 226-1: date of issue.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2261(array $o): bool {
		return self::present($o, 'invoiceDate');

	}//end vatdirArt2261()

	/**
	 * 226-2: a sequential invoice number.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2262(array $o): bool {
		return self::present($o, 'invoiceNumber');

	}//end vatdirArt2262()

	/**
	 * 226-3: the supplier's VAT identification number.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2263(array $o): bool {
		return self::present($o, 'sellerVatId');

	}//end vatdirArt2263()

	/**
	 * 226-5: full name and address of supplier and customer.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2265(array $o): bool {
		return self::present($o, 'sellerName')
			&& self::present($o, 'sellerAddress') && self::present($o, 'buyerName')
			&& self::present($o, 'buyerAddress');

	}//end vatdirArt2265()

	/**
	 * 226-8: taxable amount per rate (breakdown) and unit price exclusive of VAT (line).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2268(array $o): bool {
		return self::allBreakdownNumeric($o, 'taxableAmount')
			&& self::allLinesNumeric($o, 'netPrice');

	}//end vatdirArt2268()

	/**
	 * 226-9: the VAT rate applied (each breakdown group states its rate, unless untaxed).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt2269(array $o): bool {
		return self::breakdownRatePresentUnlessUntaxed($o);

	}//end vatdirArt2269()

	/**
	 * 226-10: the VAT amount payable.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 */
	private static function vatdirArt22610(array $o): bool {
		return self::numericPresent($o, 'vatAmount');

	}//end vatdirArt22610()

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
		return self::SEED_SPEC;

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
	 * When a line is an intra-community supply ('K'), the seller VAT id (or tax
	 * representative VAT id) and the buyer VAT id are present (EN 16931
	 * BR-IC-02). Vacuously true when no line is an intra-community supply.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function intraCommunityPartiesIdentified(array $o): bool {
		return self::sellerVatWhenCategory($o, 'K')
			&& (self::anyLineCategory($o, 'K') === false || self::present($o, 'buyerVatId'));
	}//end intraCommunityPartiesIdentified()

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
	 * When a line is reverse charged ('AE'), the seller is identified by its VAT
	 * id, tax registration id or tax representative VAT id, and the buyer by its
	 * VAT id or legal registration id (EN 16931 BR-AE-02). Vacuously true when no
	 * line is reverse charged.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function reverseChargePartiesIdentified(array $o): bool {
		if (self::anyLineCategory($o, 'AE') === false) {
			return true;
		}

		$sellerIdentified = (self::present($o, 'sellerVatId') || self::present($o, 'sellerTaxRegId')
			|| self::present($o, 'sellerTaxRepVatId'));

		return $sellerIdentified && (self::present($o, 'buyerVatId') || self::present($o, 'buyerLegalRegId'));
	}//end reverseChargePartiesIdentified()

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

		$lineSum = self::lineNetOfCategory(self::lines($o), $cat);
		$allowanceSum = self::itemAmountOfCategory(self::items($o, 'allowances'), $cat);
		$chargeSum = self::itemAmountOfCategory(self::items($o, 'charges'), $cat);

		$expected = ($lineSum - $allowanceSum + $chargeSum);

		$taxableSum = self::taxableOfCategory(self::vatBreakdown($o), $cat);

		return self::centsEqual($taxableSum, $expected);
	}//end breakdownTaxableReconciles()

	/**
	 * The sum of the net amounts of the invoice lines of VAT category $cat.
	 *
	 * @param array<int, array<string, mixed>> $lines The invoice lines.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return float
	 */
	private static function lineNetOfCategory(array $lines, string $cat): float {
		$sum = 0.0;
		foreach ($lines as $line) {
			if ((string)($line['vatCategory'] ?? '') === $cat) {
				$sum += (float)($line['netAmount'] ?? 0);
			}
		}

		return $sum;
	}//end lineNetOfCategory()

	/**
	 * The sum of the amounts of the document allowances or charges of VAT
	 * category $cat.
	 *
	 * @param array<int, array<string, mixed>> $items The allowances or charges.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return float
	 */
	private static function itemAmountOfCategory(array $items, string $cat): float {
		$sum = 0.0;
		foreach ($items as $item) {
			if ((string)($item['vatCategory'] ?? '') === $cat) {
				$sum += (float)($item['amount'] ?? 0);
			}
		}

		return $sum;
	}//end itemAmountOfCategory()

	/**
	 * The sum of the taxable amounts of the VAT breakdown groups of category $cat.
	 *
	 * @param array<int, array<string, mixed>> $groups The VAT breakdown groups.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return float
	 */
	private static function taxableOfCategory(array $groups, string $cat): float {
		$sum = 0.0;
		foreach ($groups as $group) {
			if ((string)($group['category'] ?? '') === $cat) {
				$sum += (float)($group['taxableAmount'] ?? 0);
			}
		}

		return $sum;
	}//end taxableOfCategory()

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
