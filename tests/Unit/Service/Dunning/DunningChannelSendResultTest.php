<?php

/**
 * Unit tests for DunningChannelSendResult: the value it carries and the
 * postageStatus a DunningRun records from it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\DunningChannelSendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Dunning\DunningChannelSendResult
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DunningChannelSendResultTest extends TestCase {

	/**
	 * The constructor keeps what it was given, with empty defaults.
	 *
	 * @return void
	 */
	public function testItCarriesItsValues(): void {
		$result = new DunningChannelSendResult(
			channel: 'EMAIL',
			deliveryStatus: 'BOUNCED',
			providerMessageId: 'msg-1',
			extras: ['dossierId' => 'd-1'],
			errorMessage: 'mailbox full'
		);

		self::assertSame('EMAIL', $result->channel);
		self::assertSame('BOUNCED', $result->deliveryStatus);
		self::assertSame('msg-1', $result->providerMessageId);
		self::assertSame(['dossierId' => 'd-1'], $result->extras);
		self::assertSame('mailbox full', $result->errorMessage);

		$bare = new DunningChannelSendResult(channel: 'POST', deliveryStatus: 'PENDING');
		self::assertNull($bare->providerMessageId);
		self::assertSame([], $bare->extras);
		self::assertNull($bare->errorMessage);
	}//end testItCarriesItsValues()

	/**
	 * Without a barcode or tracking url there is no postage status.
	 *
	 * @return void
	 */
	public function testNoPostageStatusWithoutBarcodeOrTracking(): void {
		$result = new DunningChannelSendResult(
			channel: 'POST',
			deliveryStatus: 'PENDING',
			extras: ['barcode' => '', 'deliveredAt' => '2026-11-13']
		);

		self::assertNull($result->postageStatus());
	}//end testNoPostageStatusWithoutBarcodeOrTracking()

	/**
	 * A barcode alone gives a status with only the barcode.
	 *
	 * @return void
	 */
	public function testABarcodeAloneIsTheStatus(): void {
		$result = new DunningChannelSendResult(channel: 'POST', deliveryStatus: 'PENDING', extras: ['barcode' => '3SABCD1234']);

		self::assertSame(['barcode' => '3SABCD1234'], $result->postageStatus());
	}//end testABarcodeAloneIsTheStatus()

	/**
	 * A tracking url alone gives a status with only the url.
	 *
	 * @return void
	 */
	public function testATrackingUrlAloneIsTheStatus(): void {
		$result = new DunningChannelSendResult(
			channel: 'POST',
			deliveryStatus: 'PENDING',
			extras: ['trackingUrl' => 'https://track.example/3S']
		);

		self::assertSame(['trackingUrl' => 'https://track.example/3S'], $result->postageStatus());
	}//end testATrackingUrlAloneIsTheStatus()

	/**
	 * Barcode, tracking url and delivery date all go into the status, the date as a string.
	 *
	 * @return void
	 */
	public function testTheFullStatusIncludesTheDeliveryDate(): void {
		$result = new DunningChannelSendResult(
			channel: 'POST',
			deliveryStatus: 'DELIVERED',
			extras: ['barcode' => '3SABCD1234', 'trackingUrl' => 'https://track.example/3S', 'deliveredAt' => 20261113]
		);

		self::assertSame(
			['barcode' => '3SABCD1234', 'trackingUrl' => 'https://track.example/3S', 'deliveredAt' => '20261113'],
			$result->postageStatus()
		);
	}//end testTheFullStatusIncludesTheDeliveryDate()
}//end class
