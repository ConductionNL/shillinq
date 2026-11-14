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

use OCA\Shillinq\Service\Tax\VatTariffRefusedException;
use OCA\Shillinq\Service\Tax\VatTariffResolver;

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
	 * Constructor.
	 *
	 * @param VatTariffResolver|null $tariffs Resolves a line's `vatTariff` code on its document date;
	 *                                        without it a line naming a tariff is refused.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly ?VatTariffResolver $tariffs = null,
	) {

	}//end __construct()

	/**
	 * Calculate totals + per-rate breakdown for an array of line items.
	 *
	 * Each line item is expected to expose:
	 *   - costAmountCents (int)   — net amount in cents
	 *   - vatTariff       (string) with documentDate (Y-m-d) — the VatTariff code, resolved on that date; or
	 *   - vatRate         (float) — percentage (e.g. 21.0), for lines that name no tariff yet
	 *
	 * @param array<int,array<string,mixed>> $lineItems Lines to total.
	 *
	 * @return array{netCents:int,vatCents:int,grossCents:int,breakdown:array<int,array{rate:float,netCents:int,vatCents:int,grossCents:int}>}
	 *
	 * @throws VatTariffRefusedException When a line names a tariff that cannot be resolved.
	 */
	public function calculateVAT(array $lineItems): array {
		$breakdown = [];
		foreach ($this->netPerRate(lineItems: $lineItems) as $group) {
			$breakdown[] = $this->rateLine(group: $group);
		}

		$netTotal = array_sum(array_column($breakdown, 'netCents'));
		$vatTotal = array_sum(array_column($breakdown, 'vatCents'));

		return [
			'netCents' => $netTotal,
			'vatCents' => $vatTotal,
			'grossCents' => ($netTotal + $vatTotal),
			'breakdown' => $breakdown,
		];

	}//end calculateVAT()

	/**
	 * The net cents per VAT rate, in the order the rates first appear (21 % by default).
	 *
	 * @param array<int,array<string,mixed>> $lineItems Line items with costAmountCents and vatRate.
	 *
	 * @return array<string,array{rate:float,netCents:int}>
	 */
	private function netPerRate(array $lineItems): array {
		$byRate = [];
		foreach ($lineItems as $line) {
			$rate = $this->lineRate(line: $line);
			$rateKey = (string)$rate;
			if (isset($byRate[$rateKey]) === false) {
				$byRate[$rateKey] = ['rate' => $rate, 'netCents' => 0];
			}

			$byRate[$rateKey]['netCents'] += (int)($line['costAmountCents'] ?? 0);
		}

		return $byRate;

	}//end netPerRate()

	/**
	 * A line's VAT percentage: the tariff its `vatTariff` code names on its
	 * `documentDate`, else its bare `vatRate` (21 % when it has none).
	 *
	 * A line naming a tariff never falls back to a rate: an unknown or expired
	 * code, a missing date, or no resolver refuses the line (REQ-TVRD-002).
	 *
	 * @param array<string,mixed> $line One line item.
	 *
	 * @return float The percentage.
	 *
	 * @throws VatTariffRefusedException When the line names a tariff that cannot be resolved.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function lineRate(array $line): float {
		$code = trim((string)($line['vatTariff'] ?? ''));
		if ($code === '') {
			return (float)($line['vatRate'] ?? 21.0);
		}

		$date = trim((string)($line['documentDate'] ?? ''));
		if ($this->tariffs === null || $date === '') {
			throw new VatTariffRefusedException(tariffCode: $code, date: $date, why: 'unknown');
		}

		return $this->tariffs->rateFor(code: $code, date: $date);
	}//end lineRate()

	/**
	 * One breakdown line: a rate's net, its VAT and its gross.
	 *
	 * @param array{rate:float,netCents:int} $group The net cents of one rate.
	 *
	 * @return array{rate:float,netCents:int,vatCents:int,grossCents:int}
	 */
	private function rateLine(array $group): array {
		$vat = $this->vatOnNet(netCents: $group['netCents'], rate: $group['rate']);

		return [
			'rate' => $group['rate'],
			'netCents' => $group['netCents'],
			'vatCents' => $vat,
			'grossCents' => ($group['netCents'] + $vat),
		];

	}//end rateLine()

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
