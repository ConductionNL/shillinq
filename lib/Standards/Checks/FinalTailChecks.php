<?php

/**
 * Final-tail check provider
 *
 * Closes the last in-scope rules by enforcing them PROPERLY rather than dropping
 * them. Three were previously left as tautologies because the data to check them
 * non-vacuously was not modelled; they are now enforced against a real, independent
 * marker:
 *  - BR-CO-19 / BR-CO-20: an invoicing period (document- or line-level) is "used"
 *    via an explicit `invoicingPeriodPresent` flag — independent of the dates — so
 *    "period present but neither start nor end date" genuinely fails (not !A || A).
 *  - pcg-class-8-special: a French class-8 (comptes spéciaux) account must carry an
 *    off-balance/commitment account type, so a class-8 account typed as a normal
 *    P&L/balance account fails (FR-jurisdiction, dormant for a NL tenant).
 * The remaining three are modelled on new objects shillinq did not have:
 *  - IOSS import consignment (<= EUR 150 intrinsic value; every line VAT-charged
 *    since the <=EUR 22 exemption is abolished) on a new IossImport object.
 *  - The travel-agent margin scheme (taxable amount = margin = selling - cost, with
 *    no input-VAT deduction) on a new TravelAgentMargin object.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Enforces the final in-scope tail (invoicing periods, PCG class 8, IOSS, margin).
 */
final class FinalTailChecks implements CheckProvider, SeedsObjects {
	/**
	 * The final-tail predicates keyed by object type then rule id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'ARInvoice' => [
				// BR-CO-19: if the document-level invoicing period is used, a start or
				// end date is present (the flag is independent of the dates).
				'en16931-br-co-19' => static fn (array $o): bool => (self::truthy($o, 'invoicingPeriodPresent') === false
					|| self::present($o, 'invoicingPeriodStart') || self::present($o, 'invoicingPeriodEnd')),
				// BR-CO-20: each invoice line whose period is used has a start or end date.
				'en16931-br-co-20' => static fn (array $o): bool => self::allLinePeriodsFilled($o),
			],
			'Account' => [
				// pcg-class-8-special (FR): a class-8 'comptes spéciaux' account must be an
				// off-balance/commitment/special type (not a normal P&L/balance account).
				'pcg-class-8-special' => static fn (array $o): bool => (self::accountClass($o) !== '8'
					|| in_array((string)($o['accountType'] ?? ''), ['off-balance', 'commitment', 'memo', 'special', 'order'], true)),
			],
			'IossImport' => [
				// IOSS covers import consignments of intrinsic value up to EUR 150.
				'ioss-import-scheme-150-eur' => static fn (array $o): bool => (is_numeric(($o['consignmentValue'] ?? null)) === true
					&& (float)($o['consignmentValue'] ?? 0) <= 150.0),
				// The <=EUR 22 negligible-value exemption is abolished: every import line carries VAT.
				'ioss-low-value-import-exemption-abolished' => static fn (array $o): bool => (is_numeric(($o['vatAmount'] ?? null)) === true
					&& (float)($o['vatAmount'] ?? 0) > 0.0),
			],
			'TravelAgentMargin' => [
				// Art. 306: the taxable amount under the travel-agent margin scheme is the
				// margin (selling - cost), with no deduction of input VAT on the supplies.
				'vatdir-art306-margin-travel-agents' => static fn (array $o): bool => (self::centsEqual(
					(float)($o['margin'] ?? 0),
					((float)($o['sellingPrice'] ?? 0) - (float)($o['cost'] ?? 0))
				) && ($o['inputVatDeducted'] ?? true) === false),
			],
		];

	}//end checks()

	/**
	 * Backfill the document-level invoicing-period marker onto the seeded ARInvoices
	 * so BR-CO-19 is exercised (a period that is present WITH a start date).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [
			'ARInvoice' => [
				'invoicingPeriodPresent' => true,
				'invoicingPeriodStart' => '2026-01-01',
				'invoicingPeriodEnd' => '2026-03-31',
			],
		];

	}//end seedSpec()

	/**
	 * Compliant sample IossImport + TravelAgentMargin objects (the types start empty).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'IossImport' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'consignmentValue' => 120.00,
					'currency' => 'EUR',
					'vatAmount' => 25.20,
					'vatRate' => 21,
					'destinationCountry' => 'NL',
				],
			],
			'TravelAgentMargin' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'sellingPrice' => 1000.00,
					'cost' => 800.00,
					'margin' => 200.00,
					'inputVatDeducted' => false,
					'currency' => 'EUR',
				],
			],
		];

	}//end seedObjects()

	/**
	 * Every invoice line whose period is used carries a start or end date.
	 *
	 * @param array<string, mixed> $o The ARInvoice.
	 *
	 * @return bool
	 */
	private static function allLinePeriodsFilled(array $o): bool {
		$lines = ($o['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return true;
		}

		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			if (self::truthy($line, 'periodPresent') === true
				&& self::present($line, 'periodStart') === false
				&& self::present($line, 'periodEnd') === false
			) {
				return false;
			}
		}

		return true;
	}//end allLinePeriodsFilled()

	/**
	 * The leading-digit account class of an account number.
	 *
	 * @param array<string, mixed> $o The Account.
	 *
	 * @return string
	 */
	private static function accountClass(array $o): string {
		$number = trim((string)($o['accountNumber'] ?? ''));
		return ($number === '' ? '' : $number[0]);
	}//end accountClass()

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

	/**
	 * Compare two amounts at cent precision.
	 *
	 * @param float $a Left amount.
	 * @param float $b Right amount.
	 *
	 * @return bool
	 */
	private static function centsEqual(float $a, float $b): bool {
		return ((int)round($a * 100) === (int)round($b * 100));
	}//end centsEqual()
}//end class
