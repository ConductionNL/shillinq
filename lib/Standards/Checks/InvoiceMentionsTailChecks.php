<?php

/**
 * Invoice-mentions tail check provider
 *
 * The last cleanly-mappable in-scope invoicing/VAT-Directive rules: the conditional
 * invoice "mention" obligations (cash accounting, self-billing, exemption reference,
 * reverse charge, the travel-agent and second-hand margin schemes, tax representative,
 * the simplified invoice and the national-currency VAT amount) plus the two scheme-
 * identifier code-list rules. Each is a genuine conditional predicate of the form
 * "this special case does NOT apply OR the required mention/field is present" — it can
 * return false (a cash-accounting invoice with no 'Cash accounting' mention fails), so
 * it is not a tautology; the minimal S/Z test seed simply never triggers the special
 * case, so these pass vacuously today while remaining real checks for real data.
 *
 * Maps onto ARInvoice optional flags/fields (cashAccounting, selfBilled, marginScheme*,
 * taxRepLiable, simplifiedInvoice, vatAmountNationalCurrency, sellerIdentifierScheme,
 * endpointScheme) — read as absent/falsy on the seed, so no schema fragment or seedSpec
 * is required.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.Commenting.InlineComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Conditional invoice-mention + scheme-identifier code-list checks on ARInvoice.
 */
final class InvoiceMentionsTailChecks implements CheckProvider {

	/**
	 * ISO 6523 ICD scheme identifiers accepted for party identifier schemes (subset).
	 */
	private const ID_SCHEMES = ['0002', '0007', '0009', '0037', '0060', '0088', '0096', '0106', '0190', '0198', '0204', '9944', '9946', '9957'];

	/**
	 * CEF EAS electronic-address scheme identifiers (subset).
	 */
	private const EAS_SCHEMES = ['0002', '0007', '0009', '0037', '0060', '0088', '0106', '0190', '0192', '0198', '0204', '9944', '9956', '9957'];

	/**
	 * Conditional invoice-mention + code-list predicates keyed by rule id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// VAT Directive art. 226(7a): cash-accounting supply -> 'Kasstelsel' mention.
				'vatdir-art226-7a' => static fn (array $o): bool => (self::truthy($o, 'cashAccounting') === false || self::present($o, 'cashAccountingMention')),
				// art. 226(10a): customer-issued (self-billed) invoice -> 'Factuur uitgereikt door afnemer'.
				'vatdir-art226-10a' => static fn (array $o): bool => (self::truthy($o, 'selfBilled') === false || self::present($o, 'selfBillingMention')),
				// art. 226(11): exemption -> reference to the applicable provision (reuse the VAT
				// breakdown exemption reason for the exempt/zero/reverse/export/IC/not-subject families).
				'vatdir-art226-11' => static fn (array $o): bool => self::exemptBreakdownsReferenced($o),
				// art. 226(11a): customer liable for VAT -> 'Btw verlegd' mention (when a line/breakdown is reverse-charge).
				'vatdir-art226-11a' => static fn (array $o): bool => (self::hasCategory($o, 'AE') === false || self::present($o, 'reverseChargeMention')),
				// art. 226(13): travel-agent margin scheme -> 'Bijzondere regeling reisbureaus'.
				'vatdir-art226-13' => static fn (array $o): bool => (self::truthy($o, 'marginSchemeTravel') === false || self::present($o, 'marginSchemeMention')),
				// art. 226(14): second-hand/art/antiques margin scheme -> the corresponding mention.
				'vatdir-art226-14' => static fn (array $o): bool => (self::truthy($o, 'marginSchemeSecondHand') === false || self::present($o, 'marginSchemeMention')),
				// art. 226(15): tax representative liable -> the representative's VAT id/details stated.
				'vatdir-art226-15' => static fn (array $o): bool => (self::truthy($o, 'taxRepLiable') === false || self::present($o, 'sellerTaxRepVatId')),
				// art. 226b: a simplified invoice must still carry date + supplier id + the VAT amount (or the data to derive it).
				'vatdir-art226b-simplified' => static fn (array $o): bool => (self::truthy($o, 'simplifiedInvoice') === false
					|| (self::present($o, 'invoiceDate') && self::present($o, 'sellerVatId') && self::numeric($o, 'vatAmount'))),
				// art. 230: amounts may be in any currency, but the VAT amount must ALSO be in the national
				// currency — satisfied when the invoice currency is the national one or a national VAT amount is given.
				'vatdir-art230-currency' => static fn (array $o): bool => (in_array((string)($o['currency'] ?? 'EUR'), ['EUR'], true)
					|| self::numeric($o, 'vatAmountNationalCurrency')),

				// EN 16931 BR-CL-10: a party identifier scheme, when present, is from the ISO 6523 ICD list.
				'en16931-br-cl-10' => static fn (array $o): bool => (self::present($o, 'sellerIdentifierScheme') === false
					|| in_array((string)($o['sellerIdentifierScheme'] ?? ''), self::ID_SCHEMES, true)),
				// EN 16931 BR-CL-25: an electronic-address (endpoint) scheme, when present, is from the CEF EAS list.
				'en16931-br-cl-25' => static fn (array $o): bool => (self::present($o, 'endpointScheme') === false
					|| in_array((string)($o['endpointScheme'] ?? ''), self::EAS_SCHEMES, true)),
			],
		];

	}//end checks()

	/**
	 * No test-data seeding required — every predicate is conditional on an optional
	 * field absent from the minimal seed, so the seed satisfies them vacuously.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Each VAT breakdown in an exemption family carries an exemption reason reference.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function exemptBreakdownsReferenced(array $o): bool {
		$exemptCategories = ['E', 'AE', 'K', 'G', 'O'];
		$vb = ($o['vatBreakdown'] ?? []);
		if (is_array($vb) === false) {
			return true;
		}

		foreach ($vb as $group) {
			if (is_array($group) === false) {
				continue;
			}

			if (in_array((string)($group['category'] ?? ''), $exemptCategories, true) === false) {
				continue;
			}

			$hasReason = (trim((string)($group['exemptionReasonCode'] ?? '')) !== ''
				|| trim((string)($group['exemptionReasonText'] ?? '')) !== '');
			if ($hasReason === false) {
				return false;
			}
		}

		return true;
	}//end exemptBreakdownsReferenced()

	/**
	 * True when any invoice line carries the given VAT category code.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 * @param string $cat The UNTDID 5305 category code.
	 *
	 * @return bool
	 */
	private static function hasCategory(array $o, string $cat): bool {
		$lines = ($o['invoiceLines'] ?? []);
		if (is_array($lines) === true) {
			foreach ($lines as $line) {
				if (is_array($line) === true && (string)($line['vatCategory'] ?? '') === $cat) {
					return true;
				}
			}
		}

		$vb = ($o['vatBreakdown'] ?? []);
		if (is_array($vb) === true) {
			foreach ($vb as $group) {
				if (is_array($group) === true && (string)($group['category'] ?? '') === $cat) {
					return true;
				}
			}
		}

		return false;
	}//end hasCategory()

	/**
	 * A field holds a non-empty value.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field.
	 *
	 * @return bool
	 */
	private static function present(array $o, string $key): bool {
		return (isset($o[$key]) === true && trim((string)$o[$key]) !== '');
	}//end present()

	/**
	 * A field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field.
	 *
	 * @return bool
	 */
	private static function numeric(array $o, string $key): bool {
		return (isset($o[$key]) === true && is_numeric($o[$key]) === true);
	}//end numeric()

	/**
	 * A field holds a truthy flag.
	 *
	 * @param array<string, mixed> $o The object.
	 * @param string $key The field.
	 *
	 * @return bool
	 */
	private static function truthy(array $o, string $key): bool {
		return (($o[$key] ?? false) === true || ($o[$key] ?? '') === 'true' || ($o[$key] ?? 0) === 1);
	}//end truthy()
}//end class
