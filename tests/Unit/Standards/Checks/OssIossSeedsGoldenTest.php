<?php

/**
 * Golden test for the OSS/IOSS sample objects: their data and that they pass their own rules.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
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

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\OssIossChecks;
use PHPUnit\Framework\TestCase;

/**
 * The seeds stay the same and stay compliant.
 */
final class OssIossSeedsGoldenTest extends TestCase {

	/**
	 * The seed data and its verdicts.
	 *
	 * @return void
	 */
	public function testSeedsAreUnchangedAndCompliant(): void {
		$seeds = OssIossChecks::seedObjects();
		$this->assertSame('acddefa715e534bede39205a61a63976', md5(serialize($seeds)));
		$this->assertSame([], OssIossChecks::seedSpec());

		$checks = OssIossChecks::checks();
		foreach ($seeds as $type => $objects) {
			foreach ($objects as $object) {
				foreach ($checks[$type] as $rule => $predicate) {
					$this->assertTrue($predicate($object), $type.' '.$rule);
				}
			}
		}

	}//end testSeedsAreUnchangedAndCompliant()
}//end class
