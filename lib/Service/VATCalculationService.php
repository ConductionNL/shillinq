<?php

/**
 * VAT Calculation Service
 *
 * Dutch BTW (VAT) totalling for BillableInvoice line items (Task 14, issue #111).
 * All arithmetic is performed in integer cents; per-rate breakdown is reported
 * in both cents and 2-decimal euros for invoice/PDF rendering.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure VAT totaller — no external dependencies, fully unit-testable.
 */
class VATCalculationService {
	/**
	 * Dutch statutory VAT rates per Wet OB 1968 (REQ-ITE-009).
	 *
	 * @var array<int,float>
	 */
	private const VALID_RATES = [0.0, 6.0, 9.0, 21.0];

	/**
	 * Calculate totals + per-rate breakdown for an array of line items.
	 *
	 * Each line item is expected to expose:
	 *   - costAmountCents (int)   — net amount in cents
	 *   - vatRate         (float) — percentage (e.g. 21.0)
	 *
	 * @param array<int,array<string,mixed>> $lineItems Lines to total.
	 *
	 * @return array{netCents:int,vatCents:int,grossCents:int,breakdown:array<int,array{rate:float,netCents:int,vatCents:int,grossCents:int}>}
	 */
	public function calculateVAT(array $lineItems): array {
		$byRate = [];

		foreach ($lineItems as $line) {
			$net = (int)($line['costAmountCents'] ?? 0);
			$rate = (float)($line['vatRate'] ?? 21.0);

			$rateKey = (string)$rate;
			if (isset($byRate[$rateKey]) === false) {
				$byRate[$rateKey] = ['rate' => $rate, 'netCents' => 0];
			}

			$byRate[$rateKey]['netCents'] += $net;
		}//end foreach

		$netTotal = 0;
		$vatTotal = 0;
		$breakdown = [];

		foreach ($byRate as $group) {
			$vat = $this->vatOnNet(netCents: $group['netCents'], rate: $group['rate']);
			$breakdown[] = [
				'rate' => $group['rate'],
				'netCents' => $group['netCents'],
				'vatCents' => $vat,
				'grossCents' => ($group['netCents'] + $vat),
			];
			$netTotal += $group['netCents'];
			$vatTotal += $vat;
		}

		return [
			'netCents' => $netTotal,
			'vatCents' => $vatTotal,
			'grossCents' => ($netTotal + $vatTotal),
			'breakdown' => $breakdown,
		];

	}//end calculateVAT()

	/**
	 * Compute VAT on a net cents figure using bankers' rounding (REQ-ITE-009).
	 *
	 * @param int $netCents Net amount in cents.
	 * @param float $rate VAT percentage (e.g. 21.0).
	 *
	 * @return int VAT in cents.
	 */
	public function vatOnNet(int $netCents, float $rate): int {
		$value = (($netCents * $rate) / 100.0);
		// PHP_ROUND_HALF_EVEN — bankers' rounding, customary for Dutch invoicing.
		return (int)round($value, 0, PHP_ROUND_HALF_EVEN);
	}//end vatOnNet()

	/**
	 * Whether a percentage matches one of the four statutory Dutch rates.
	 *
	 * Used by callers that want to clamp client-supplied per-line VAT
	 * to the statutory set (21 / 9 / 6 / 0) before passing the line to
	 * calculateVAT(); also referenced from the unit test.
	 *
	 * @param float $rate VAT percentage.
	 *
	 * @return bool
	 */
	public function isValidRate(float $rate): bool {
		return in_array($rate, self::VALID_RATES, true);
	}//end isValidRate()
}//end class
