<?php

/**
 * Golden test for BcfCompensationCalculator::computeCompensation.
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

use OCA\Shillinq\Service\BcfCompensationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full compensation, float shapes included.
 *
 * Regenerate with BCF_COMPENSATION_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfCompensationCalculatorGoldenTest extends TestCase {

	/**
	 * Golden fixture path.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/fixtures/bcf-compensation-calculator.golden.json';

	/**
	 * Every computation matches the golden output.
	 *
	 * @return void
	 */
	public function testCompensationsMatchGolden(): void {
		$calculator = new BcfCompensationCalculator();
		$amounts = [
			'4100' => 40.005,
			'3610' => '100',
			'3650' => 50,
			'3700' => -10,
			'3800' => 0.01,
			'3900' => 25,
			3000 => 10,
		];
		$mappings = [
			'3610' => ['bcfCompensable' => true, 'compensablePercentage' => 100],
			'4100' => ['bcfCompensable' => true, 'compensablePercentage' => '50'],
			'3650' => ['bcfCompensable' => false, 'compensablePercentage' => 100],
			'3700' => ['bcfCompensable' => true, 'compensablePercentage' => 100],
			'3800' => ['bcfCompensable' => true, 'compensablePercentage' => 1],
			'3900' => ['bcfCompensable' => true, 'compensablePercentage' => 150],
			'3000' => ['bcfCompensable' => true, 'compensablePercentage' => -5],
		];
		$actual = [
			'mixed' => $calculator->computeCompensation($amounts, $mappings),
			'noMappings' => $calculator->computeCompensation($amounts, []),
			'empty' => $calculator->computeCompensation([], $mappings),
		];

		if (getenv('BCF_COMPENSATION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));

	}//end testCompensationsMatchGolden()
}//end class
