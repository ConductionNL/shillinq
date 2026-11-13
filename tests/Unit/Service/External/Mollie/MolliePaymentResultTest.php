<?php

/**
 * Unit tests for MolliePaymentResult.
 *
 * The result an adapter returns keeps each member exactly as given, has no
 * extras unless they are passed, and cannot be changed afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Mollie
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Mollie;

use Error;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MolliePaymentResult.
 *
 * @covers \OCA\Shillinq\Service\External\Mollie\MolliePaymentResult
 */
class MolliePaymentResultTest extends TestCase {

	/**
	 * Every member is kept as given.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new MolliePaymentResult(
			paymentStatus: 'open',
			molliePaymentId: 'tr_WDqYK6vllg',
			checkoutUrl: 'https://pay.example/checkout',
			dormant: false,
			extras: ['trace' => 'x-1'],
		);

		$this->assertSame('open', $result->paymentStatus);
		$this->assertSame('tr_WDqYK6vllg', $result->molliePaymentId);
		$this->assertSame('https://pay.example/checkout', $result->checkoutUrl);
		$this->assertFalse($result->dormant);
		$this->assertSame(['trace' => 'x-1'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries none.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new MolliePaymentResult('open', 'tr_WDqYK6vllg', 'https://pay.example/checkout', true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new MolliePaymentResult('open', 'tr_WDqYK6vllg', 'https://pay.example/checkout', true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->dormant = false;
	}//end testItIsReadOnly()
}//end class
