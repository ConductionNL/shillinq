<?php

/**
 * Example data for dossiq's VTH settings (DqVthInstellingen): the three
 * Zuiddrecht fee rules, each a calculation shillinq accepts.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\FeeCalculation;
use PHPUnit\Framework\TestCase;

/**
 * The Zuiddrecht fee rules.
 */
class ExampleSetFeeRulesTest extends TestCase {

	/**
	 * Three named rules for dossiq, each with the numbers its calculation needs.
	 *
	 * @return void
	 */
	public function testThreeRulesTheCalculationAccepts(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$rules = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'FeeSchedule') {
				continue;
			}

			(new FeeCalculation())->assertValid(schedule: $object['data']);
			$this->assertSame(expected: 'dossiq', actual: $object['data']['targetApp']);
			$rules[$object['data']['name']] = $object['data']['calculation'];
		}

		$this->assertSame(
			expected: ['Bouwen, per euro bouwkosten' => 'percentage', 'Kappen' => 'per_unit', 'Intrekken binnen 4 weken' => 'refund_percentage'],
			actual: $rules
		);
	}//end testThreeRulesTheCalculationAccepts()
}//end class
