<?php

/**
 * Unit tests for {@see LoggingPipelinqAdminNotifier}.
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain. The default
 * binding for {@see PipelinqAdminNotifier} until the persistent
 * notification surface lands. The test verifies:
 *
 *   - {@see LoggingPipelinqAdminNotifier::notifyAuthFailure()} emits a
 *     single ERROR-level log entry.
 *   - The log entry names the config-key location of the bad token but
 *     never the token value (ADR-005).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Pipelinq\LoggingPipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Tests the default logging-only notifier binding.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
final class LoggingPipelinqAdminNotifierTest extends TestCase {
	/**
	 * Build a recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

	}//end recordingLogger()

	/**
	 * notifyAuthFailure() emits one ERROR-level entry referencing the
	 * config-key location of the bad token.
	 *
	 * @return void
	 */
	public function testNotifyAuthFailureEmitsErrorWithConfigKey(): void {
		$logger = $this->recordingLogger();
		$notifier = new LoggingPipelinqAdminNotifier(logger: $logger);

		$dto = new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CONFIRMED,
			externalId: 'booking-z-1',
			timestamp: new DateTimeImmutable('2026-06-07T08:00:00Z', new DateTimeZone('UTC')),
			contactId: 'pl-contact-7'
		);

		$notifier->notifyAuthFailure(event: $dto);

		self::assertCount(1, $logger->records);
		self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
		self::assertStringContainsString('Invalid pipelinq API token', $logger->records[0]['message']);
		self::assertSame(
			PipelinqContactAdapter::CONFIG_KEY_TOKEN,
			$logger->records[0]['context']['configKey']
		);
		self::assertSame('booking-z-1', $logger->records[0]['context']['externalId']);

	}//end testNotifyAuthFailureEmitsErrorWithConfigKey()

}//end class
