<?php

/**
 * Unit tests for SmsReminderDispatcher.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\SmsOptOutPolicy;
use OCA\Shillinq\Service\Sms\SmsPhoneNumberNormalizer;
use OCA\Shillinq\Service\Sms\SmsProviderAdapterInterface;
use OCA\Shillinq\Service\Sms\SmsReminderDispatcher;
use OCA\Shillinq\Service\Sms\SmsSendResult;
use OCA\Shillinq\Service\Sms\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the dispatch pipeline orders the gate, validation, rendering and
 * provider hand-off correctly and fails closed at each step.
 */
final class SmsReminderDispatcherTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var SmsReminderDispatcher
	 */
	private SmsReminderDispatcher $dispatcher;

	/**
	 * Recording fake adapter.
	 *
	 * @var SmsProviderAdapterInterface
	 */
	private SmsProviderAdapterInterface $adapter;

	/**
	 * Last arguments the adapter was called with.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $lastSend = null;

	/**
	 * Wire the dispatcher with real helpers and a recording fake adapter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$self = $this;
		$this->adapter = new class($self) implements SmsProviderAdapterInterface {

			/**
			 * @var SmsReminderDispatcherTest
			 */
			private $test;

			/**
			 * @param SmsReminderDispatcherTest $test Enclosing test for recording.
			 */
			public function __construct($test) {
				$this->test = $test;
			}

			/**
			 * Record the call and return a sent result.
			 *
			 * @param string $connectorId Connector id.
			 * @param string $e164Recipient Recipient.
			 * @param string $body Body.
			 * @param string|null $senderId Sender.
			 *
			 * @return SmsSendResult Sent result.
			 */
			public function send(string $connectorId, string $e164Recipient, string $body, ?string $senderId = null): SmsSendResult {
				$this->test->recordSend(
					[
						'connectorId' => $connectorId,
						'recipient' => $e164Recipient,
						'body' => $body,
						'senderId' => $senderId,
					]
				);
				return new SmsSendResult(SmsSendResult::STATUS_SENT, 'ref-1', null, 1);
			}
		};

		$this->dispatcher = new SmsReminderDispatcher(
			new SmsOptOutPolicy(),
			new SmsPhoneNumberNormalizer(),
			new SmsTemplateRenderer(),
			$this->adapter
		);

	}//end setUp()

	/**
	 * Record the adapter call (used by the anonymous adapter).
	 *
	 * @param array<string, mixed> $args Call arguments.
	 *
	 * @return void
	 */
	public function recordSend(array $args): void {
		$this->lastSend = $args;

	}//end recordSend()

	/**
	 * Build a valid active channel.
	 *
	 * @return array<string, mixed>
	 */
	private function channel(): array {
		return [
			'status' => 'active',
			'respectOptOut' => true,
			'provider' => 'messagebird',
			'providerConfig' => ['connectorId' => 'openconnector-messagebird-nl'],
			'messageTemplate' => 'Hallo {{customerName}}, boeking {{bookingRef}}.',
			'senderId' => 'Bookings',
			'fallbackPhoneNumber' => '+31611112222',
			'phoneNumberFormat' => 'e164',
		];
	}//end channel()

	/**
	 * Happy path: opted-in recipient with a number gets a normalized, rendered
	 * message handed to the provider.
	 *
	 * @return void
	 */
	public function testHappyPathDispatches(): void {
		$result = $this->dispatcher->dispatch(
			$this->channel(),
			['phone' => '0612345678', 'smsOptOut' => false],
			['customerName' => 'Jan', 'bookingRef' => 'BK001']
		);

		self::assertSame(SmsSendResult::STATUS_SENT, $result->status);
		self::assertTrue($result->isDelivered());
		self::assertNotNull($this->lastSend);
		self::assertSame('+31612345678', $this->lastSend['recipient'], 'Domestic number normalized to E.164');
		self::assertSame('Hallo Jan, boeking BK001.', $this->lastSend['body']);
		self::assertSame('Bookings', $this->lastSend['senderId']);

	}//end testHappyPathDispatches()

	/**
	 * Opted-out recipient is skipped before any provider call.
	 *
	 * @return void
	 */
	public function testOptedOutRecipientIsSkipped(): void {
		$result = $this->dispatcher->dispatch(
			$this->channel(),
			['phone' => '0612345678', 'smsOptOut' => true],
			['customerName' => 'Jan']
		);

		self::assertSame(SmsSendResult::STATUS_SKIPPED, $result->status);
		self::assertNull($this->lastSend, 'Provider must not be called for opted-out recipient');

	}//end testOptedOutRecipientIsSkipped()

	/**
	 * Inactive channel is skipped.
	 *
	 * @return void
	 */
	public function testInactiveChannelIsSkipped(): void {
		$channel = $this->channel();
		$channel['status'] = 'inactive';
		$result = $this->dispatcher->dispatch($channel, ['phone' => '0612345678'], []);

		self::assertSame(SmsSendResult::STATUS_SKIPPED, $result->status);
		self::assertNull($this->lastSend);

	}//end testInactiveChannelIsSkipped()

	/**
	 * Falls back to the channel number when the recipient has none.
	 *
	 * @return void
	 */
	public function testUsesFallbackNumber(): void {
		$result = $this->dispatcher->dispatch(
			$this->channel(),
			['smsOptOut' => false],
			['customerName' => 'Jan', 'bookingRef' => 'BK1']
		);

		self::assertTrue($result->isDelivered());
		self::assertSame('+31611112222', $this->lastSend['recipient']);

	}//end testUsesFallbackNumber()

	/**
	 * No recipient number and no fallback → skipped, not sent.
	 *
	 * @return void
	 */
	public function testNoNumberAnywhereIsSkipped(): void {
		$channel = $this->channel();
		unset($channel['fallbackPhoneNumber']);
		$result = $this->dispatcher->dispatch($channel, ['smsOptOut' => false], ['customerName' => 'Jan']);

		self::assertSame(SmsSendResult::STATUS_SKIPPED, $result->status);
		self::assertNull($this->lastSend);

	}//end testNoNumberAnywhereIsSkipped()

	/**
	 * An invalid recipient number fails (not silently sent).
	 *
	 * @return void
	 */
	public function testInvalidNumberFails(): void {
		$result = $this->dispatcher->dispatch(
			$this->channel(),
			['phone' => '12345', 'smsOptOut' => false],
			['customerName' => 'Jan']
		);

		self::assertSame(SmsSendResult::STATUS_FAILED, $result->status);
		self::assertNull($this->lastSend);

	}//end testInvalidNumberFails()

	/**
	 * A channel without a connector cannot dispatch.
	 *
	 * @return void
	 */
	public function testMissingConnectorFails(): void {
		$channel = $this->channel();
		$channel['providerConfig'] = [];
		$result = $this->dispatcher->dispatch(
			$channel,
			['phone' => '+31612345678', 'smsOptOut' => false],
			['customerName' => 'Jan']
		);

		self::assertSame(SmsSendResult::STATUS_FAILED, $result->status);
		self::assertNull($this->lastSend);

	}//end testMissingConnectorFails()

}//end class
