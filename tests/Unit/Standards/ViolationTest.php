<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use JsonSerializable;
use OCA\Shillinq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Violation: what a rule violation serialises to.
 *
 * @covers \OCA\Shillinq\Standards\Violation
 */
class ViolationTest extends TestCase {

	/**
	 * A violation serialises to its four fields, in a fixed order.
	 *
	 * @return void
	 */
	public function testAViolationSerialisesItsFields(): void {
		$violation = new Violation(
			ruleId: 'en16931-br-co-15',
			severity: 'mandatory',
			source: 'EN 16931 BR-CO-15',
			statement: 'Invoice total amount with VAT = total without VAT + VAT total.'
		);

		$this->assertInstanceOf(JsonSerializable::class, $violation);
		$this->assertSame(
			[
				'ruleId' => 'en16931-br-co-15',
				'severity' => 'mandatory',
				'source' => 'EN 16931 BR-CO-15',
				'statement' => 'Invoice total amount with VAT = total without VAT + VAT total.',
			],
			$violation->jsonSerialize()
		);
	}//end testAViolationSerialisesItsFields()

	/**
	 * Encoded as JSON, a list of violations becomes a list of plain objects.
	 *
	 * @return void
	 */
	public function testViolationsEncodeAsPlainObjects(): void {
		$json = json_encode(
			[
				new Violation(ruleId: 'a', severity: 'recommended', source: 'S', statement: 'é "quoted"'),
				new Violation(ruleId: 'b', severity: 'conditional', source: '', statement: ''),
			],
			JSON_UNESCAPED_UNICODE
		);

		$this->assertSame(
			'[{"ruleId":"a","severity":"recommended","source":"S","statement":"é \"quoted\""},'
			. '{"ruleId":"b","severity":"conditional","source":"","statement":""}]',
			$json
		);
	}//end testViolationsEncodeAsPlainObjects()
}//end class
