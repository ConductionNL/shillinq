<?php

/**
 * Unit tests for DigipoortSubmissionResult.
 *
 * The result an adapter returns keeps each member exactly as given, has no
 * extras unless they are passed, and cannot be changed afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Digipoort
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Digipoort;

use Error;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DigipoortSubmissionResult.
 *
 * @covers \OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult
 */
class DigipoortSubmissionResultTest extends TestCase {

	/**
	 * Every member is kept as given.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new DigipoortSubmissionResult(
			deliveryStatus: 'DELIVERED',
			reference: 'dp-1',
			dormant: false,
			extras: ['trace' => 'x-1'],
		);

		$this->assertSame('DELIVERED', $result->deliveryStatus);
		$this->assertSame('dp-1', $result->reference);
		$this->assertFalse($result->dormant);
		$this->assertSame(['trace' => 'x-1'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries none.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new DigipoortSubmissionResult('DELIVERED', 'dp-1', true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new DigipoortSubmissionResult('DELIVERED', 'dp-1', true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->dormant = false;
	}//end testItIsReadOnly()
}//end class
