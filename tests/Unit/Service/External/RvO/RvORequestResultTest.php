<?php

/**
 * Unit tests for RvORequestResult.
 *
 * The result an adapter returns keeps each member exactly as given, has no
 * extras unless they are passed, and cannot be changed afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\RvO
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

namespace OCA\Shillinq\Tests\Unit\Service\External\RvO;

use Error;
use OCA\Shillinq\Service\External\RvO\RvORequestResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RvORequestResult.
 *
 * @covers \OCA\Shillinq\Service\External\RvO\RvORequestResult
 */
class RvORequestResultTest extends TestCase {

	/**
	 * Every member is kept as given.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new RvORequestResult(
			deliveryStatus: 'SUBMITTED',
			aanvraagnummer: 'WBSO-2026-1',
			dormant: false,
			extras: ['trace' => 'x-1'],
		);

		$this->assertSame('SUBMITTED', $result->deliveryStatus);
		$this->assertSame('WBSO-2026-1', $result->aanvraagnummer);
		$this->assertFalse($result->dormant);
		$this->assertSame(['trace' => 'x-1'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries none.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new RvORequestResult('SUBMITTED', 'WBSO-2026-1', true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new RvORequestResult('SUBMITTED', 'WBSO-2026-1', true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->dormant = false;
	}//end testItIsReadOnly()
}//end class
