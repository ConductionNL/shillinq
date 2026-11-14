<?php

/**
 * Golden tests for the urencriterium seed record and the toezichtregime verdict.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use OCA\Shillinq\Service\SluitendCalculator;
use OCA\Shillinq\Service\UrenNormDeterminationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The seed record and the regime keep their shapes and verdicts.
 */
final class NormAndRegimeGoldenTest extends TestCase {

	/**
	 * The full seed record and its log line.
	 *
	 * @return void
	 */
	public function testSeedRecordGolden(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$service = new UrenNormDeterminationService(guard: new UrencriteriumYearGuard(logger: $this->createMock(LoggerInterface::class)), logger: $logger);

		$seed = $service->bouwSeedRecord(
			profiel: [
				'administrationId'    => 'adm-1',
				'enterpriseId'        => 'ond-1',
				'calendarYear'        => '2025',
				'arbeidsongeschikt'   => true,
				'ondernemingsUrenJTD' => 900,
				'loondienstUrenJTD'   => 400,
			]
		);
		$plain = $service->bouwSeedRecord(profiel: ['calendarYear' => 2024]);

		$this->assertSame(['administrationId', 'enterpriseId', 'calendarYear', 'purposeNorm', 'normBasis', 'currentHours', 'thresholdStatus', 'largelyCriterium'], array_keys($seed));
		$this->assertSame(['adm-1', 'ond-1', 2025, 0.0, 'ON_RATE'], [$seed['administrationId'], $seed['enterpriseId'], $seed['calendarYear'], $seed['currentHours'], $seed['thresholdStatus']]);
		$this->assertSame(['', '', 2024], [$plain['administrationId'], $plain['enterpriseId'], $plain['calendarYear']]);
		$this->assertSame(
			[
				'UrenNormDeterminationService: built seed for new urencriterium-jaar',
				['enterpriseId' => 'ond-1', 'calendarYear' => 2025, 'purposeNorm' => $seed['purposeNorm'], 'grotendeels' => $seed['largelyCriterium']],
			],
			$logged[0]
		);
		$this->assertCount(2, $logged);
		$this->assertSame('649cd4bf1e6c94152fdaf95faf566c22', md5(serialize([$seed, $plain])));
		$this->assertSame((int)gmdate('Y'), $service->bouwSeedRecord(profiel: [])['calendarYear']);

	}//end testSeedRecordGolden()

	/**
	 * Every regime branch.
	 *
	 * @return void
	 */
	public function testRegimeGolden(): void {
		$calc  = new SluitendCalculator();
		$cases = [
			[true, true, [], 1.0, 'repressief'],
			[true, true, [-1.0, -2.0, -3.0], 1.0, 'repressief'],
			[true, true, [-1.0, -2.0, -3.0, -4.0], 1.0, 'preventief'],
			[true, true, [-1.0, -2.0, 0.0, -4.0], 1.0, 'repressief'],
			[true, true, [-1.0, -2.0, -3.0, -4.0, 5.0], 1.0, 'repressief'],
			[true, true, [], 0.99, 'preventief'],
			[true, false, [], 2.0, 'preventief'],
			[false, true, [], 2.0, 'preventief'],
			[true, true, [], -0.01, 'artikel-12'],
			[false, false, [-1.0, -1.0, -1.0, -1.0], -1.0, 'artikel-12'],
			[false, false, [], 0.0, 'preventief'],
		];

		$verdicts = array_map(
			static fn (array $c): string => $calc->determineToezichtRegime(structurallyBalanced: $c[0], sluitendReeel: $c[1], historyResultaten: $c[2], weerstandsverhouding: $c[3]),
			$cases
		);

		$this->assertSame(array_column($cases, 4), $verdicts);
		$this->assertSame('repressief', $calc->determineToezichtRegime(structurallyBalanced: true, sluitendReeel: true));

	}//end testRegimeGolden()
}//end class
