<?php

/**
 * Golden test for UrenDagregistratieGuard save rules and backfill label.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every save decision with its log line, and the backfill day count behind the label.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class UrenDagregistratieGuardGoldenTest extends TestCase {

	/**
	 * Recorded log lines in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $lines = [];

	/**
	 * The guard, with a logger that records and can throw on info.
	 *
	 * @param bool $infoFails Whether an info line throws.
	 *
	 * @return UrenDagregistratieGuard
	 */
	private function guard(bool $infoFails=false): UrenDagregistratieGuard {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level, $infoFails): void {
					$this->lines[] = [$level, $message, $context];
					if ($level === 'info' && $infoFails === true) {
						throw new RuntimeException('log down');
					}
				}
			);
		}

		return new UrenDagregistratieGuard($logger);
	}//end guard()

	/**
	 * Evidence categories need evidence; a backfill older than seven days needs a reason and evidence.
	 *
	 * @return void
	 */
	public function testSaveDecisions(): void {
		$cases = [
			[['category' => 'TRAINING'], false],
			[['category' => 'FICTION_ZEZ', 'backfillEvidence' => 'doc', 'date' => '2026-01-01', 'registrationMoment' => '2026-01-20'], false],
			[['category' => 'OTHER', 'date' => '2026-01-01', 'registrationMoment' => '2026-01-08T23:59:00Z'], true],
			[['date' => '2026-01-01T23:00:00Z', 'registrationMoment' => '2026-01-09T00:30:00Z', 'backfillReason' => 'late'], false],
			[['date' => '2026-01-01', 'registrationMoment' => '2026-02-01', 'backfillReason' => 'r', 'backfillEvidence' => 'e'], true],
			[['date' => 'not a date', 'registrationMoment' => '2026-02-01'], true],
			[['date' => '2026-02-01', 'registrationMoment' => '2026-01-01'], true],
			[['registrationMoment' => '2026-02-01'], true],
		];
		$guard = $this->guard();
		$seen  = [];
		foreach ($cases as [$entry]) {
			$seen[] = $guard->validateOnSave(entry: $entry);
		}

		self::assertSame(expected: array_column($cases, 1), actual: $seen);
		$backfill = 'UrenDagregistratieGuard: backfill older than 7 days requires reden + bewijs — denying save';
		$expected = [
			['info', 'UrenDagregistratieGuard: category requires evidence but none supplied — denying save', ['category' => 'TRAINING']],
			['info', $backfill, ['days' => 19, 'hasReden' => false, 'hasBewijs' => true]],
			['info', $backfill, ['days' => 8, 'hasReden' => true, 'hasBewijs' => false]],
		];
		self::assertSame(expected: $expected, actual: $this->lines);
	}//end testSaveDecisions()

	/**
	 * A failure inside the rules denies the save with one error line.
	 *
	 * @return void
	 */
	public function testFailClosed(): void {
		$guard = $this->guard(infoFails: true);
		self::assertSame(expected: false, actual: $guard->validateOnSave(entry: ['category' => 'TRAINING', 'enterpriseId' => 'e1']));
		self::assertSame(expected: false, actual: $guard->validateOnSave(entry: ['category' => 'TRAINING']));
		$error = 'UrenDagregistratieGuard: validateOnSave failed — denying save (fail-closed)';
		self::assertSame(expected: ['error', $error, ['enterpriseId' => 'e1', 'exception' => 'log down']], actual: $this->lines[1]);
		self::assertSame(expected: ['error', $error, ['enterpriseId' => 'unknown', 'exception' => 'log down']], actual: $this->lines[3]);
	}//end testFailClosed()

	/**
	 * The label counts UTC calendar days; same day, earlier, blank or unparsable dates give none.
	 *
	 * @return void
	 */
	public function testBackfillLabel(): void {
		$guard = $this->guard();
		$cases = [
			['2026-01-01', '2026-01-02T00:00:01Z', 'Backfill T+1 dagen'],
			['2026-01-01T23:59:59Z', '2026-01-02T00:00:00Z', 'Backfill T+1 dagen'],
			['2026-01-01T00:00:00+02:00', '2026-01-01T12:00:00Z', 'Backfill T+1 dagen'],
			['2026-01-01', '2026-01-01T23:59:59Z', null],
			['2026-01-05', '2026-01-01', null],
			['', '2026-01-01', null],
			['2026-01-01', '', null],
			['garbage', '2026-01-01', null],
			['2024-02-28', '2024-03-01', 'Backfill T+2 dagen'],
		];
		foreach ($cases as [$date, $moment, $label]) {
			$actual = $guard->bepaalBackfillLabel(date: $date, registrationMoment: $moment);
			self::assertSame(expected: $label, actual: $actual, message: $date . ' / ' . $moment);
		}
	}//end testBackfillLabel()
}//end class
