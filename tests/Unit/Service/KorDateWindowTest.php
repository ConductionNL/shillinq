<?php

/**
 * Characterisation tests for the KorThresholdCalculator date windows,
 * member-state aggregate and branche advisory.
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

use OCA\Shillinq\Service\KorThresholdCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every branch of lockInWindow, plusThreeYears, perLidstaatAggregate
 * and brancheCompatibility, value for value.
 */
final class KorDateWindowTest extends TestCase {

	/**
	 * Lock-in end and earliest termination for every month edge.
	 *
	 * @return void
	 */
	public function testLockInWindowEdges(): void {
		$calc    = new KorThresholdCalculator();
		$windows = [];
		foreach ([
			'2026-01-01',
			'2026-01-02',
			'2026-01-31',
			'2026-02-01',
			'2026-03-01',
			'2025-03-01',
			'2026-02-29',
			'2026-04-31',
			'2026-07-15',
			'2026-12-01',
			'2026-00-10',
			'2026-13-01',
			'2026-01-00',
			'2026-01-32',
			'26-01-01',
			'2026-1-01',
		] as $date) {
			$windows[$date] = $calc->lockInWindow($date);
		}

		self::assertSame(
			[
				'2026-01-01' => ['lockInEndDate' => '2028-12-31', 'earliestTerminationDate' => '2028-10-01'],
				'2026-01-02' => ['lockInEndDate' => '2029-01-01', 'earliestTerminationDate' => '2028-11-01'],
				'2026-01-31' => ['lockInEndDate' => '2029-01-30', 'earliestTerminationDate' => '2028-11-01'],
				'2026-02-01' => ['lockInEndDate' => '2029-01-31', 'earliestTerminationDate' => '2028-11-01'],
				'2026-03-01' => ['lockInEndDate' => '2029-02-28', 'earliestTerminationDate' => '2028-12-01'],
				'2025-03-01' => ['lockInEndDate' => '2028-02-29', 'earliestTerminationDate' => '2027-12-01'],
				'2026-02-29' => ['lockInEndDate' => '2029-02-28', 'earliestTerminationDate' => '2028-12-01'],
				'2026-04-31' => ['lockInEndDate' => '2029-04-30', 'earliestTerminationDate' => '2029-02-01'],
				'2026-07-15' => ['lockInEndDate' => '2029-07-14', 'earliestTerminationDate' => '2029-05-01'],
				'2026-12-01' => ['lockInEndDate' => '2029-11-30', 'earliestTerminationDate' => '2029-09-01'],
				'2026-00-10' => null,
				'2026-13-01' => null,
				'2026-01-00' => null,
				'2026-01-32' => null,
				'26-01-01'   => null,
				'2026-1-01'  => null,
			],
			$windows
		);

	}//end testLockInWindowEdges()

	/**
	 * Plus three years, with the 29 February clamp and invalid input.
	 *
	 * @return void
	 */
	public function testPlusThreeYearsEdges(): void {
		$calc  = new KorThresholdCalculator();
		$dates = [];
		foreach (['2024-02-29', '2025-02-29', '2026-02-28', '2026-12-31', '2026-00-01', '2026-12-32', 'x'] as $date) {
			$dates[$date] = $calc->plusThreeYears($date);
		}

		self::assertSame(
			[
				'2024-02-29' => '2027-02-28',
				'2025-02-29' => '2028-02-29',
				'2026-02-28' => '2029-02-28',
				'2026-12-31' => '2029-12-31',
				'2026-00-01' => '',
				'2026-12-32' => '',
				'x'          => '',
			],
			$dates
		);

	}//end testPlusThreeYearsEdges()

	/**
	 * The aggregate skips other regimes, missing member states and other
	 * years, upper-cases the code, sorts by it and falls back to the default
	 * ceiling; a zero threshold gives zero use.
	 *
	 * @return void
	 */
	public function testPerLidstaatAggregateRows(): void {
		$invoices = [
			['amount' => 50.0, 'vrijstellingsGrondslag' => 'OTHER', 'lidstaat' => 'BE', 'leveringsDatum' => '2026-01-01'],
			['amount' => 10.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'de', 'leveringsDatum' => '2026-02-01'],
			['amount' => 5.5, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'DE', 'leveringsDatum' => '2026-03-01'],
			['amount' => 7.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'AT', 'leveringsDatum' => '2026-03-01'],
			['amount' => 9.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'AT', 'leveringsDatum' => '2025-03-01'],
			['amount' => 3.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'lidstaat' => 'BE', 'leveringsDatum' => '2026-03-01'],
			['amount' => 4.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-03-01'],
		];

		$aggregate = (new KorThresholdCalculator())->perLidstaatAggregate($invoices, ['DE' => 100, 'BE' => 0], 2026);

		self::assertSame(['AT', 'BE', 'DE'], array_keys($aggregate));
		self::assertSame(['revenue' => 7.0, 'threshold' => 100000.0, 'benutting' => 0.0001], $aggregate['AT']);
		self::assertSame(['revenue' => 3.0, 'threshold' => 0.0, 'benutting' => 0.0], $aggregate['BE']);
		self::assertSame(['revenue' => 15.5, 'threshold' => 100.0, 'benutting' => 0.155], $aggregate['DE']);
		self::assertSame([], (new KorThresholdCalculator())->perLidstaatAggregate([], [], 2026));

	}//end testPerLidstaatAggregateRows()

	/**
	 * Every advisory with its reason, the first matching flag winning.
	 *
	 * @return void
	 */
	public function testBrancheCompatibilityReasons(): void {
		$calc     = new KorThresholdCalculator();
		$verdicts = [];
		foreach ([
			['fiscaleEenheid' => true, 'art11Vrijstelling' => true],
			['art11Vrijstelling' => 1, 'vrijgesteldEnBelast' => true],
			['vrijgesteldEnBelast' => 'yes', 'intracommunautair' => true],
			['intracommunautair' => true],
			['fiscaleEenheid' => false, 'intracommunautair' => 0],
		] as $branche) {
			$verdicts[] = $calc->brancheCompatibility($branche);
		}

		self::assertSame(
			[
				[
					'verdict' => 'BLOCK',
					'reason' => 'KOR aanmelden door een fiscale unit is niet mogelijk; de unit zelf moet aanmelden, niet een individuele deelnemer.',
				],
				[
					'verdict' => 'BLOCK',
					'reason' => 'Onderneming valt volledig onder art. 11 OB; KOR levert geen voordeel en blokkeert voorbelasting-aftrek.',
				],
				[
					'verdict' => 'WARN',
					'reason' => 'Mixed-use vrijgesteld + belast: effective KOR-threshold wordt calculated over alleen het belaste deel.',
				],
				[
					'verdict' => 'WARN',
					'reason' => 'Bedrijf doet structureel intracommunautaire leveringen; overweeg OSS-regime als alternatief.',
				],
				['verdict' => 'OK', 'reason' => 'Geen branche-specifieke contra-indicaties; KOR is geschikt.'],
			],
			$verdicts
		);

	}//end testBrancheCompatibilityReasons()
}//end class
