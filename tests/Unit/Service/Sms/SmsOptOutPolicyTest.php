<?php

/**
 * Unit tests for SmsOptOutPolicy.
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
use PHPUnit\Framework\TestCase;

/**
 * Verifies the fail-closed eligibility gate: inactive channels and opted-out
 * recipients are skipped, with PII-free reasons.
 */
final class SmsOptOutPolicyTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var SmsOptOutPolicy
	 */
	private SmsOptOutPolicy $policy;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policy = new SmsOptOutPolicy();

	}//end setUp()

	/**
	 * An active channel to a non-opted-out recipient is allowed.
	 *
	 * @return void
	 */
	public function testActiveChannelNonOptedOutIsAllowed(): void {
		self::assertTrue($this->policy->isSendAllowed(['status' => 'active'], ['smsOptOut' => false]));
		self::assertNull($this->policy->skipReason(['status' => 'active'], ['smsOptOut' => false]));

	}//end testActiveChannelNonOptedOutIsAllowed()

	/**
	 * A non-active channel is never sent from.
	 *
	 * @return void
	 */
	public function testInactiveChannelIsSkipped(): void {
		self::assertFalse($this->policy->isSendAllowed(['status' => 'inactive'], ['smsOptOut' => false]));
		self::assertSame('channel not active', $this->policy->skipReason(['status' => 'inactive'], []));
		self::assertFalse($this->policy->isSendAllowed(['status' => 'archived'], []));

	}//end testInactiveChannelIsSkipped()

	/**
	 * An opted-out recipient is skipped when the channel respects opt-out
	 * (the default, including when respectOptOut is absent — fail closed).
	 *
	 * @return void
	 */
	public function testOptedOutRecipientIsSkippedByDefault(): void {
		self::assertFalse($this->policy->isSendAllowed(['status' => 'active'], ['smsOptOut' => true]));
		self::assertSame(
			'recipient opted out of SMS reminders',
			$this->policy->skipReason(['status' => 'active'], ['smsOptOut' => true])
		);

		// respectOptOut explicitly true.
		self::assertFalse(
			$this->policy->isSendAllowed(['status' => 'active', 'respectOptOut' => true], ['smsOptOut' => true])
		);

	}//end testOptedOutRecipientIsSkippedByDefault()

	/**
	 * An operator may disable opt-out respect for a transactional channel.
	 *
	 * @return void
	 */
	public function testOptOutCanBeDisabledForTransactionalChannel(): void {
		self::assertTrue(
			$this->policy->isSendAllowed(['status' => 'active', 'respectOptOut' => false], ['smsOptOut' => true])
		);
		self::assertNull(
			$this->policy->skipReason(['status' => 'active', 'respectOptOut' => false], ['smsOptOut' => true])
		);

	}//end testOptOutCanBeDisabledForTransactionalChannel()

}//end class
