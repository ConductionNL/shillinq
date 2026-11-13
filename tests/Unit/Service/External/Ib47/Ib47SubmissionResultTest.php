<?php

/**
 * Unit tests for Ib47SubmissionResult.
 *
 * The result an adapter returns keeps each member exactly as given, has no
 * extras unless they are passed, and cannot be changed afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Ib47
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Ib47;

use Error;
use OCA\Shillinq\Service\External\Ib47\Ib47SubmissionResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Ib47SubmissionResult.
 *
 * @covers \OCA\Shillinq\Service\External\Ib47\Ib47SubmissionResult
 */
class Ib47SubmissionResultTest extends TestCase {

	/**
	 * Every member is kept as given.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new Ib47SubmissionResult(
			deliveryStatus: 'DELIVERED',
			reference: 'ib47-1',
			dormant: false,
			extras: ['trace' => 'x-1'],
		);

		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertSame('ib47-1', $result->reference);
		$this->assertFalse($result->dormant);
		$this->assertSame(['trace' => 'x-1'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries none.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new Ib47SubmissionResult('DELIVERED', 'ib47-1', true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new Ib47SubmissionResult('DELIVERED', 'ib47-1', true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->dormant = false;
	}//end testItIsReadOnly()
}//end class
