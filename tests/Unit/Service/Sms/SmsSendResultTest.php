<?php

/**
 * Unit tests for SmsSendResult.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\SmsOptOutPolicy;
use OCA\Shillinq\Service\Sms\SmsSendResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which send outcomes count as delivered, and the array shape the reminder
 * log stores.
 */
#[CoversClass(SmsSendResult::class)]
final class SmsSendResultTest extends TestCase {

	/**
	 * Each status and whether it counts as delivered.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function statuses(): array {
		return [
			'sent' => [SmsSendResult::STATUS_SENT, true],
			'pending' => [SmsSendResult::STATUS_PENDING, true],
			'failed' => [SmsSendResult::STATUS_FAILED, false],
			'skipped' => [SmsSendResult::STATUS_SKIPPED, false],
			'unknown' => ['queued', false],
		];

	}//end statuses()

	/**
	 * Sent and pending are delivered; failed, skipped and anything else are not.
	 *
	 * @param string $status    The status.
	 * @param bool   $delivered Whether it counts as delivered.
	 *
	 * @return void
	 */
	#[DataProvider('statuses')]
	public function testDelivery(string $status, bool $delivered): void {
		self::assertSame($delivered, (new SmsSendResult(status: $status))->isDelivered());

	}//end testDelivery()

	/**
	 * The array carries every field in a fixed order, nulls included.
	 *
	 * @return void
	 */
	public function testToArray(): void {
		self::assertSame(
			['status' => 'sent', 'providerReference' => 'msg-1', 'reason' => null, 'segments' => 2],
			(new SmsSendResult(status: SmsSendResult::STATUS_SENT, providerReference: 'msg-1', segments: 2))->toArray()
		);
		self::assertSame(
			['status' => 'skipped', 'providerReference' => null, 'reason' => 'opted_out', 'segments' => null],
			(new SmsSendResult(status: SmsSendResult::STATUS_SKIPPED, reason: 'opted_out'))->toArray()
		);

	}//end testToArray()

}//end class
