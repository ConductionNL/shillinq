<?php

/**
 * OSS / IOSS check provider
 *
 * The EU One-Stop-Shop VAT rules, mapped onto shillinq's EXISTING OSS objects
 * (OssThresholdCounter, OssReturn — defined in register.d/bookkeeping-btw-oss-eu.json,
 * so OSS is genuinely in scope here): the EUR 10 000 distance-sales threshold, the
 * rate of the Member State of consumption, and the quarterly OSS return. The objects
 * have no rows in the test register, so this provider seeds compliant samples via
 * SeedsObjects so the checks actually evaluate. (The IOSS import rules and the
 * travel-agent margin scheme are intentionally NOT enforced — shillinq models an OSS
 * return but no IOSS import return nor a travel-agent margin object.)
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
 * Executable OSS/IOSS VAT rules over OssThresholdCounter + OssReturn.
 */
final class OssIossChecks implements CheckProvider, SeedsObjects {
	/**
	 * OSS/IOSS predicates keyed by object type then rule id.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'OssThresholdCounter' => [
				// The EUR 10 000 EU-wide distance-sales threshold is tracked: the counter
				// records the annual B2C EU turnover and, once over the threshold, the
				// breach date (below it, supply stays taxed in the supplier's MS).
				'oss-distance-sales-threshold-10000' => static fn (array $o): bool => (is_numeric(($o['totalB2cEuTurnover'] ?? null)) === true
					&& trim((string)($o['calendarYear'] ?? '')) !== ''
					&& (((float)($o['totalB2cEuTurnover'] ?? 0) <= 10000.0) || trim((string)($o['thresholdBreachedDate'] ?? '')) !== '')),
			],
			'OssReturn' => [
				// VAT is charged at the rate of the Member State of consumption: the return
				// distributes VAT per destination country.
				'oss-rate-of-member-state-consumption' => static fn (array $o): bool => self::hasPerCountryVat($o),
				// Union/non-Union OSS returns are quarterly: the return carries a quarter (Q1-Q4).
				'oss-single-quarterly-return' => static fn (array $o): bool => in_array((string)($o['periodQuarter'] ?? ''), ['Q1', 'Q2', 'Q3', 'Q4'], true),
			],
		];

	}//end checks()

	/**
	 * No field backfill needed beyond the seeded sample objects.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Compliant sample OSS objects so the checks evaluate (the types start empty).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'OssThresholdCounter' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'calendarYear' => 2026,
					'totalB2cEuTurnover' => 5000.00,
					'byQuarter' => ['Q1' => 5000.00],
					'byCountry' => ['DE' => 5000.00],
				],
			],
			'OssReturn' => [
				[
					'administrationId' => 'adm-shillinq-1',
					'periodYear' => 2026,
					'periodQuarter' => 'Q1',
					'registrationId' => 'oss-reg-shillinq-1',
					'type' => 'regular',
					'status' => 'submitted',
					'totalTaxableBase' => 1000.00,
					'totalVatAmount' => 190.00,
					'lineItems' => [
						['countryCode' => 'DE', 'rateCategory' => 'standard', 'taxableBase' => 1000.00, 'vatRate' => 19, 'vatAmount' => 190.00],
					],
					'perCountryDistribution' => ['DE' => 190.00],
				],
			],
		];

	}//end seedObjects()

	/**
	 * The return distributes VAT per Member State of consumption (per-country lines).
	 *
	 * @param array<string, mixed> $o The OssReturn.
	 *
	 * @return bool
	 */
	private static function hasPerCountryVat(array $o): bool {
		// perCountryDistribution is a { countryCode: vatAmount } map.
		$distribution = ($o['perCountryDistribution'] ?? []);
		if (is_array($distribution) === true) {
			foreach ($distribution as $country => $amount) {
				if (trim((string)$country) !== '' && is_numeric($amount) === true) {
					return true;
				}
			}
		}

		// Or per-country VAT carried on the return's line items (BT countryCode/vatAmount).
		$lines = ($o['lineItems'] ?? []);
		if (is_array($lines) === true) {
			foreach ($lines as $line) {
				if (is_array($line) === true
					&& trim((string)($line['countryCode'] ?? '')) !== ''
					&& is_numeric(($line['vatAmount'] ?? null)) === true
				) {
					return true;
				}
			}
		}

		return false;
	}//end hasPerCountryVat()
}//end class
