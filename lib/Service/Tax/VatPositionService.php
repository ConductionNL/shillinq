<?php

/**
 * VAT position service.
 *
 * The running VAT position of a period: per return box, the base and VAT of
 * the lines posted so far and the amount payable or refundable, read through
 * the same derivation that prepares a VAT return (REQ-VPS-001).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\VATReturnService;

/**
 * Reads the VAT position of a period from the posted lines.
 *
 * A period is named the way a return names it: `2026-Q4` for a quarter,
 * `2026-11` for a month, `2026` for a year.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
 */
class VatPositionService {

	/**
	 * Constructor.
	 *
	 * @param VATReturnService $vatReturnService The service whose derivation prepares a return.
	 */
	public function __construct(
		private readonly VATReturnService $vatReturnService,
	) {
	}//end __construct()

	/**
	 * The VAT position of an administration for a period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           `YYYY-Qn`, `YYYY-MM` or `YYYY`.
	 *
	 * @return array<string,mixed> The period, its bounds, the boxes with their lines, the totals,
	 *                             the amount payable (negative: refundable) and which of the two it is.
	 *
	 * @throws InvalidArgumentException When the period is not one of the three forms.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
	 */
	public function positionFor(string $administrationId, string $period): array {
		[$startDate, $endDate] = $this->periodBounds(period: $period);

		$position = $this->vatReturnService->positionByBox(
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate
		);

		return [
			'administrationId' => $administrationId,
			'period' => $period,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'boxes' => $position['boxes'],
			'totalVATCollected' => $position['totalVATCollected'],
			'totalVATPaid' => $position['totalVATPaid'],
			'totalTaxableAmount' => $position['totalTaxableAmount'],
			'payable' => $position['payable'],
			'direction' => $this->direction(payable: $position['payable']),
		];
	}//end positionFor()

	/**
	 * The first and last day of a period.
	 *
	 * @param string $period `YYYY-Qn`, `YYYY-MM` or `YYYY`.
	 *
	 * @return array{0:string,1:string} Start and end, Y-m-d, both inclusive.
	 *
	 * @throws InvalidArgumentException When the period is not one of the three forms.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.1
	 */
	public function periodBounds(string $period): array {
		$period = trim($period);

		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $match) === 1) {
			$firstMonth = ((((int)$match[2]) - 1) * 3 + 1);
			return $this->monthSpan(year: (int)$match[1], firstMonth: $firstMonth, months: 3);
		}

		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $match) === 1) {
			return $this->monthSpan(year: (int)$match[1], firstMonth: (int)$match[2], months: 1);
		}

		if (preg_match('/^(\d{4})$/', $period, $match) === 1) {
			return [$match[1] . '-01-01', $match[1] . '-12-31'];
		}

		throw new InvalidArgumentException('The period must read YYYY-Qn, YYYY-MM or YYYY; got "' . $period . '".');
	}//end periodBounds()

	/**
	 * The bounds of a run of whole months.
	 *
	 * @param int $year       The year.
	 * @param int $firstMonth The first month (1-12).
	 * @param int $months     How many months.
	 *
	 * @return array{0:string,1:string} Start and end, Y-m-d.
	 */
	private function monthSpan(int $year, int $firstMonth, int $months): array {
		$start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $firstMonth));
		$end = $start->modify('+' . $months . ' months')->modify('-1 day');

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}//end monthSpan()

	/**
	 * Whether the amount is to pay, to get back, or nothing.
	 *
	 * @param float $payable Collected minus paid VAT.
	 *
	 * @return string `payable`, `refundable` or `nil`.
	 */
	private function direction(float $payable): string {
		if ($payable > 0.0) {
			return 'payable';
		}

		if ($payable < 0.0) {
			return 'refundable';
		}

		return 'nil';
	}//end direction()
}//end class
