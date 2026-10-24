<?php

/**
 * Fee Calculation
 *
 * How a fee schedule's amount is worked out for one object. A legesverordening
 * names more than flat amounts: a percentage of the building costs with a
 * minimum, a price per tree, a share refunded when an application is withdrawn.
 * A schedule says which (`calculation`) and carries the numbers that kind
 * needs; this class refuses a schedule that lacks them and works the amount
 * out once the object is known.
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
 * @spec openspec/changes/fee-rule-calculation/specs/object-payment-requests/spec.md#requirement-a-fee-rule-has-a-name-and-a-calculation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Validates a schedule's calculation and applies it to an object.
 *
 * @spec openspec/changes/fee-rule-calculation/specs/object-payment-requests/spec.md#requirement-a-fee-rule-has-a-name-and-a-calculation
 */
final class FeeCalculation {

	/**
	 * The calculation kinds and the fields each one needs.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const REQUIRED = [
		'fixed'             => [],
		'percentage'        => ['percentage', 'baseProperty'],
		'per_unit'          => ['unitPrice', 'baseProperty'],
		'refund_percentage' => ['percentage'],
	];

	/**
	 * Refuse a schedule whose calculation is unknown or lacks a field it needs.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the calculation cannot be worked out.
	 *
	 * @spec openspec/changes/fee-rule-calculation/specs/object-payment-requests/spec.md#requirement-a-fee-rule-has-a-name-and-a-calculation
	 */
	public function assertValid(array $schedule): void {
		$kind = $this->kindOf(schedule: $schedule);
		if (isset(self::REQUIRED[$kind]) === false) {
			throw new InvalidArgumentException(
				sprintf('The calculation "%s" is not one of %s.', $kind, implode(', ', array_keys(self::REQUIRED)))
			);
		}

		$missing = [];
		foreach (self::REQUIRED[$kind] as $field) {
			if (($schedule[$field] ?? '') === '' || ($schedule[$field] ?? null) === null) {
				$missing[] = $field;
			}
		}

		if ($missing !== []) {
			throw new InvalidArgumentException(sprintf('A %s fee needs %s.', $kind, implode(' and ', $missing)));
		}
	}//end assertValid()

	/**
	 * The schedule with its amount worked out for the object.
	 *
	 * A fixed fee keeps its amount. A percentage is taken of the object's base
	 * value and raised to the minimum; a per-unit price is multiplied by the
	 * object's count. When the object does not carry that value yet the amount
	 * is left off and `amountSource` says so, rather than charging zero. A
	 * refund is not charged at intake, so it carries no amount.
	 *
	 * @param array<string, mixed> $schedule The resolved schedule.
	 * @param array<string, mixed> $object   The object the fee is for.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/fee-rule-calculation/specs/object-payment-requests/spec.md#requirement-a-fee-rule-has-a-name-and-a-calculation
	 */
	public function applyTo(array $schedule, array $object): array {
		$kind = $this->kindOf(schedule: $schedule);
		if ($kind === 'fixed') {
			return $schedule;
		}

		unset($schedule['amount']);
		if ($kind === 'refund_percentage') {
			$schedule['amountSource'] = 'refund';
			return $schedule;
		}

		$base = ($object[(string) ($schedule['baseProperty'] ?? '')] ?? null);
		if (is_numeric($base) === false) {
			$schedule['amountSource'] = 'needs-base';
			return $schedule;
		}

		$amount = ((float) ($schedule['unitPrice'] ?? 0) * (float) $base);
		if ($kind === 'percentage') {
			$amount = max((float) ($schedule['minimumAmount'] ?? 0), ((float) $schedule['percentage'] / 100 * (float) $base));
		}

		$schedule['amount']       = round($amount, 2);
		$schedule['amountSource'] = 'calculation';

		return $schedule;
	}//end applyTo()

	/**
	 * The schedule's calculation kind, fixed when it names none.
	 *
	 * @param array<string, mixed> $schedule The schedule.
	 *
	 * @return string
	 */
	private function kindOf(array $schedule): string {
		$kind = (string) ($schedule['calculation'] ?? '');
		if ($kind === '') {
			return 'fixed';
		}

		return $kind;
	}//end kindOf()
}//end class
