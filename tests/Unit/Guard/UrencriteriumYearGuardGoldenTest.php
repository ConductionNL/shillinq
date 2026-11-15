<?php

/**
 * Golden test for UrencriteriumYearGuard::validateOnSave.
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

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every save decision, the check order and every log line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class UrencriteriumYearGuardGoldenTest extends TestCase {

	/**
	 * Recorded log lines in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $lines = [];

	/**
	 * Validate one year.
	 *
	 * @param array<string,mixed> $year      The year record.
	 * @param bool                $infoFails Whether an info line throws.
	 *
	 * @return bool
	 */
	private function validate(array $year, bool $infoFails=false): bool {
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

		return (new UrencriteriumYearGuard($logger))->validateOnSave(year: $year);
	}//end validate()

	/**
	 * The checks in order: norm, norm basis, largely criterion, threshold status; each refusal logs once.
	 *
	 * @return void
	 */
	public function testDecisions(): void {
		$lid1  = 'art. 3.6 lid 1 Wet IB 2001';
		$lid5  = 'art. 3.6 lid 5 Wet IB 2001';
		$cases = [
			[['purposeNorm' => 0, 'normBasis' => $lid5, 'largelyCriterium' => 'x'], false],
			[['purposeNorm' => '800', 'normBasis' => $lid1], false],
			[['purposeNorm' => 800, 'normBasis' => $lid5], true],
			[['purposeNorm' => 1225, 'normBasis' => $lid5], false],
			[['purposeNorm' => 525, 'normBasis' => $lid1, 'largelyCriterium' => 'MOSTLY'], false],
			[['purposeNorm' => 1225, 'largelyCriterium' => 'LARGELY_ENTERPRISE', 'forecastYearEnd' => null], true],
			[['purposeNorm' => 1225, 'currentHours' => 100, 'forecastYearEnd' => 1000, 'thresholdStatus' => 'ON_RATE'], false],
			[['purposeNorm' => 1225, 'currentHours' => 100, 'forecastYearEnd' => '1000', 'thresholdStatus' => 'RISK'], true],
			[['purposeNorm' => 800, 'normBasis' => $lid5, 'currentHours' => 900, 'forecastYearEnd' => 0], false],
		];
		$seen  = [];
		foreach ($cases as [$year, $expected]) {
			$seen[] = [$this->validate(year: $year), $expected];
		}

		self::assertSame(expected: array_column($cases, 1), actual: array_column($seen, 0));
		$expected = [
			['info', 'UrencriteriumYearGuard: doelNorm not a recognised value — denying save', ['purposeNorm' => 0]],
			['info', 'UrencriteriumYearGuard: 800-uren norm must cite art. 3.6 lid 5 — denying save', ['normBasis' => $lid1]],
			['info', 'UrencriteriumYearGuard: only the 800-uren norm may cite art. 3.6 lid 5 — denying save', ['purposeNorm' => 1225, 'normBasis' => $lid5]],
			['info', 'UrencriteriumYearGuard: grotendeelsCriterium has an unrecognised value — denying save', ['largelyCriterium' => 'MOSTLY']],
			['info', 'UrencriteriumYearGuard: drempelStatus does not match prognose/norm — denying save', ['actual' => 'ON_RATE', 'expected' => 'RISK']],
			['info', 'UrencriteriumYearGuard: drempelStatus does not match prognose/norm — denying save', ['actual' => '', 'expected' => 'ACHIEVED']],
		];
		self::assertSame(expected: $expected, actual: $this->lines);
	}//end testDecisions()

	/**
	 * A failure inside the checks denies the save with one error line.
	 *
	 * @return void
	 */
	public function testFailClosed(): void {
		self::assertSame(expected: false, actual: $this->validate(year: ['enterpriseId' => 'e1'], infoFails: true));
		self::assertSame(expected: false, actual: $this->validate(year: [], infoFails: true));
		$error = 'UrencriteriumYearGuard: validateOnSave failed — denying save (fail-closed)';
		self::assertSame(expected: ['error', $error, ['enterpriseId' => 'e1', 'exception' => 'log down']], actual: $this->lines[1]);
		self::assertSame(expected: ['error', $error, ['enterpriseId' => 'unknown', 'exception' => 'log down']], actual: $this->lines[3]);
		self::assertCount(4, $this->lines);
	}//end testFailClosed()
}//end class
