<?php

/**
 * Golden table for BsnValidator::isValid.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\BsnValidator;
use PHPUnit\Framework\TestCase;

/**
 * Absent passes; nine digits pass the 11-proef unless the sum is zero.
 */
final class BsnValidatorGoldenTest extends TestCase {

	/**
	 * The table.
	 *
	 * @return void
	 */
	public function testVerdictTable(): void {
		$cases = [
			[null, true],
			['', true],
			['   ', true],
			['111222333', true],
			[' 111222333 ', true],
			["111222333\n", true],
			['123456782', true],
			['999999990', true],
			['123456789', false],
			['000000000', false],
			['100000009', false],
			['010000008', false],
			['900000001', false],
			['12345678', false],
			['1234567890', false],
			['12345678a', false],
			['１２３４５６７８２', false],
		];

		$this->assertSame(
			array_column($cases, 1),
			array_map(static fn (array $case): bool => BsnValidator::isValid($case[0]), $cases)
		);

	}//end testVerdictTable()
}//end class
