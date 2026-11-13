<?php

/**
 * Unit tests for SmsSendResult: which outcomes count as delivered, and the
 * array shape the notification history stores.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\SmsSendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Sms\SmsSendResult
 */
final class SmsSendResultTest extends TestCase {

	public function testSentAndPendingCountAsDeliveredFailedAndSkippedDoNot(): void {
		self::assertTrue((new SmsSendResult(SmsSendResult::STATUS_SENT, 'ref-1', null, 1))->isDelivered());
		self::assertTrue((new SmsSendResult(SmsSendResult::STATUS_PENDING))->isDelivered());
		self::assertFalse((new SmsSendResult(SmsSendResult::STATUS_FAILED, null, 'gateway down'))->isDelivered());
		self::assertFalse((new SmsSendResult(SmsSendResult::STATUS_SKIPPED, null, 'opted out'))->isDelivered());
		self::assertFalse((new SmsSendResult('SENT'))->isDelivered());
	}

	public function testToArrayCarriesEveryFieldInOrder(): void {
		self::assertSame(
			['status' => 'sent', 'providerReference' => 'ref-9', 'reason' => null, 'segments' => 2],
			(new SmsSendResult(SmsSendResult::STATUS_SENT, 'ref-9', null, 2))->toArray()
		);
		self::assertSame(
			['status' => 'failed', 'providerReference' => null, 'reason' => 'no connector', 'segments' => null],
			(new SmsSendResult(SmsSendResult::STATUS_FAILED, reason: 'no connector'))->toArray()
		);
	}
}
