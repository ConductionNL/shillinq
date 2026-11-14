<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use InvalidArgumentException;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Terms that cannot make a schedule are refused with the reason shown to the user.
 */
#[CoversClass(PaymentPlanSchedule::class)]
final class PaymentPlanScheduleRefusalsTest extends TestCase {

	/**
	 * @return array<string, array{float, string, string, ?int, ?float, string}>
	 */
	public static function impossibleTerms(): array {
		return [
			'a zero total'            => [0.0, 'monthly', '2026-11-01', 6, null, 'The plan total must be above zero.'],
			'a total below one cent'  => [0.004, 'monthly', '2026-11-01', 6, null, 'The plan total must be above zero.'],
			'an unreadable date'      => [100.0, 'monthly', '01-11-2026', 6, null, 'Choose a first due date and a weekly or monthly frequency.'],
			'a yearly frequency'      => [100.0, 'yearly', '2026-11-01', 6, null, 'Choose a first due date and a weekly or monthly frequency.'],
			'neither count nor amount' => [100.0, 'monthly', '2026-11-01', null, null, 'Choose a number of instalments or an instalment amount.'],
			'a zero count and amount' => [100.0, 'monthly', '2026-11-01', 0, 0.0, 'Choose a number of instalments or an instalment amount.'],
			'too many instalments'    => [1000.0, 'weekly', '2026-11-01', 121, null, 'A plan can have at most 120 instalments.'],
			'an amount too small'     => [1000.0, 'weekly', '2026-11-01', null, 5.0, 'A plan can have at most 120 instalments.'],
		];
	}

	#[DataProvider('impossibleTerms')]
	public function testImpossibleTermsAreRefused(float $total, string $frequency, string $first, ?int $count, ?float $amount, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new PaymentPlanSchedule())->build($total, $frequency, $first, $count, $amount);
	}

	public function testTheMaximumNumberOfInstalmentsIsAllowed(): void {
		$rows = (new PaymentPlanSchedule())->build(120.0, 'weekly', '2026-11-02', PaymentPlanSchedule::MAX_INSTALMENTS, null);

		$this->assertCount(120, $rows);
		$this->assertSame('2026-11-09', $rows[1]['dueDate']);
		$this->assertTrue((new PaymentPlanSchedule())->balances(120.0, array_column($rows, 'amount')));
	}
}
