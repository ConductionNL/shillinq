<?php

/**
 * Unit tests for LogSmsProviderAdapter.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\LogSmsProviderAdapter;
use OCA\Shillinq\Service\Sms\SmsPhoneNumberNormalizer;
use OCA\Shillinq\Service\Sms\SmsSendResult;
use OCA\Shillinq\Service\Sms\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the log adapter returns a pending result and never writes the
 * message body or an unmasked phone number to the log (ADR-005 / GDPR).
 */
final class LogSmsProviderAdapterTest extends TestCase {

	/**
	 * The adapter logs masked, body-free context and returns pending.
	 *
	 * @return void
	 */
	public function testSendLogsMaskedAndReturnsPending(): void {
		$records = [];
		$logger = new class($records) extends AbstractLogger {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records;

			/**
			 * @param array<int, array<string, mixed>> $records Reference store.
			 */
			public function __construct(array &$records) {
				$this->records = & $records;
			}

			/**
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};

		$adapter = new LogSmsProviderAdapter($logger, new SmsPhoneNumberNormalizer(), new SmsTemplateRenderer());

		$body = 'Hallo Jan, herinnering: uw boeking op 21 mei.';
		$result = $adapter->send('openconnector-messagebird-nl', '+31612345678', $body, 'Bookings');

		self::assertSame(SmsSendResult::STATUS_PENDING, $result->status);
		self::assertNotNull($result->providerReference);
		self::assertSame(1, $result->segments);

		self::assertCount(1, $logger->records);
		$context = $logger->records[0]['context'];

		// Masked recipient, never the full number.
		self::assertStringStartsWith('+31', $context['recipient']);
		self::assertStringContainsString('*', $context['recipient']);
		self::assertStringNotContainsString('612345678', $context['recipient']);

		// The message body must never appear anywhere in the log record.
		$encoded = json_encode($logger->records[0]);
		self::assertStringNotContainsString('Hallo Jan', (string)$encoded, 'Body must not be logged');
		self::assertStringNotContainsString('herinnering', (string)$encoded, 'Body must not be logged');

		// Only length/segments are exposed.
		self::assertSame(mb_strlen($body), $context['length']);
		self::assertSame('Bookings', $context['sender']);

	}//end testSendLogsMaskedAndReturnsPending()

}//end class
