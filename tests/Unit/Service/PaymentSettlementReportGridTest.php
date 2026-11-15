<?php

/**
 * Characterisation tests for the PaymentSettlementService report grid and
 * settlement checks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\PaymentSettlementService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full report for every combination of amount, settlements and
 * provider state, and every build refusal in order.
 */
final class PaymentSettlementReportGridTest extends TestCase {

	/**
	 * The report of every row of the grid, value for value.
	 *
	 * @return void
	 */
	public function testReportGrid(): void {
		$service = new PaymentSettlementService();
		$rows    = [
			'open'             => ['amount' => 10],
			'zero open'        => ['amount' => 0],
			'zero dead'        => ['amount' => '0', 'state' => 'voided'],
			'dead'             => ['amount' => 10, 'state' => 'expired'],
			'dead part'        => ['amount' => 10, 'state' => 'failed', 'settlements' => [['amount' => 4]]],
			'part'             => ['amount' => 10, 'settlements' => [['amount' => 4], ['amount' => '1.5']]],
			'paid'             => ['amount' => 10, 'settlements' => [['amount' => 10]]],
			'over'             => ['amount' => 10, 'settlements' => [['amount' => 12.345]]],
			'captured'         => ['amount' => 10, 'state' => 'captured'],
			'captured over'    => ['amount' => 10, 'state' => 'captured_unapplied', 'settlements' => [['amount' => 1]]],
			'zero captured'    => ['amount' => 0, 'state' => 'captured'],
			'no amount'        => [],
			'bool amount'      => ['amount' => true],
			'negative amount'  => ['amount' => -1],
			'huge amount'      => ['amount' => 1e14],
			'bad settlements'  => ['amount' => 10, 'settlements' => 'x'],
			'bad settlement'   => ['amount' => 10, 'settlements' => ['x']],
			'unknown dead'     => ['state' => 'failed'],
			'unknown dead paid' => ['state' => 'failed', 'settlements' => [['amount' => 2]]],
			'unknown bad dead' => ['state' => 'failed', 'settlements' => [['amount' => 'n/a']]],
		];

		$reports = [];
		foreach ($rows as $name => $request) {
			$reports[$name] = $service->report($request);
		}

		self::assertSame(
			[
				'open'             => ['state' => 'open', 'settled' => 0.0, 'due' => 10.0, 'over' => 0.0],
				'zero open'        => ['state' => 'open', 'settled' => 0.0, 'due' => 0.0, 'over' => 0.0],
				'zero dead'        => ['state' => 'unpayable', 'settled' => 0.0, 'due' => 0.0, 'over' => 0.0],
				'dead'             => ['state' => 'unpayable', 'settled' => 0.0, 'due' => 10.0, 'over' => 0.0],
				'dead part'        => ['state' => 'partly-paid', 'settled' => 4.0, 'due' => 10.0, 'over' => 0.0],
				'part'             => ['state' => 'partly-paid', 'settled' => 5.5, 'due' => 10.0, 'over' => 0.0],
				'paid'             => ['state' => 'paid', 'settled' => 10.0, 'due' => 10.0, 'over' => 0.0],
				'over'             => ['state' => 'overpaid', 'settled' => 12.35, 'due' => 10.0, 'over' => 2.35],
				'captured'         => ['state' => 'paid', 'settled' => 10.0, 'due' => 10.0, 'over' => 0.0],
				'captured over'    => ['state' => 'overpaid', 'settled' => 11.0, 'due' => 10.0, 'over' => 1.0],
				'zero captured'    => ['state' => 'open', 'settled' => 0.0, 'due' => 0.0, 'over' => 0.0],
				'no amount'        => ['state' => 'indeterminate', 'settled' => 0.0, 'due' => null, 'over' => null],
				'bool amount'      => ['state' => 'indeterminate', 'settled' => 0.0, 'due' => null, 'over' => null],
				'negative amount'  => ['state' => 'indeterminate', 'settled' => 0.0, 'due' => null, 'over' => null],
				'huge amount'      => ['state' => 'indeterminate', 'settled' => 0.0, 'due' => null, 'over' => null],
				'bad settlements'  => ['state' => 'indeterminate', 'settled' => null, 'due' => null, 'over' => null],
				'bad settlement'   => ['state' => 'indeterminate', 'settled' => null, 'due' => null, 'over' => null],
				'unknown dead'     => ['state' => 'unpayable', 'settled' => 0.0, 'due' => null, 'over' => null],
				'unknown dead paid' => ['state' => 'indeterminate', 'settled' => 2.0, 'due' => null, 'over' => null],
				'unknown bad dead' => ['state' => 'indeterminate', 'settled' => null, 'due' => null, 'over' => null],
			],
			$reports
		);
	}//end testReportGrid()

	/**
	 * Every build refusal, in the order the checks run, and the record of
	 * an accepted waiver.
	 *
	 * @return void
	 */
	public function testBuildChecksInOrder(): void {
		$service  = new PaymentSettlementService();
		$messages = [];
		foreach ([
			[['method' => 'cheque', 'amount' => 'x'], ''],
			[['method' => 'pin', 'amount' => 'x'], ''],
			[['method' => 'pin', 'amount' => -0.01], 'clerk'],
			[['method' => 'pin', 'amount' => 1], ''],
			[['method' => 'pin', 'amount' => 1, 'reference' => '  ', 'reason' => 'r'], 'clerk'],
			[['method' => 'waived', 'amount' => 0, 'reference' => 'R', 'reason' => ' '], 'clerk'],
		] as [$input, $actor]) {
			try {
				$service->build($input, $actor, '2026-01-01T00:00:00Z');
				$messages[] = 'accepted';
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'Unknown settlement method "cheque"; expected one of ' . implode(', ', PaymentSettlementService::METHODS) . '.',
				'A settlement needs the amount that arrived.',
				'A settlement needs the amount that arrived.',
				'A settlement nobody is named for cannot be questioned later; the actor is required.',
				'A settlement needs the reference a later bank reconciliation will match on.',
				'A waived fee needs the reason it was waived.',
			],
			$messages
		);

		self::assertSame(
			['method' => 'waived', 'amount' => 0.0, 'reference' => '', 'actor' => 'clerk', 'settledAt' => '2026-01-01T00:00:00Z', 'reason' => 'hardship'],
			$service->build(['method' => 'waived', 'amount' => '0', 'reason' => ' hardship '], 'clerk', '2026-01-01T00:00:00Z')
		);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $service->build(['method' => 'pin', 'amount' => 1, 'reference' => 'P'], 'clerk')['settledAt']);
	}//end testBuildChecksInOrder()
}//end class
