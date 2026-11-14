<?php

/**
 * Unit tests for the LoggingTimelineRetryQueue.
 *
 * The fallback queue logs every deferred timeline event and reports the
 * running per-process deferral count as the dead-letter gauge.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTimeImmutable;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\LoggingTimelineRetryQueue;
use OCA\Shillinq\Kernel\Model\TimelineEventDto;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the logging fallback queue.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
#[CoversClass(LoggingTimelineRetryQueue::class)]
#[UsesClass(TimelineEventDto::class)]
#[UsesClass(CustomerBridgeMetricsService::class)]
final class LoggingTimelineRetryQueueCasesTest extends TestCase {

	/**
	 * Build a timeline event.
	 *
	 * @param string $externalId The booking id.
	 *
	 * @return TimelineEventDto
	 */
	private function event(string $externalId = 'booking-1'): TimelineEventDto {
		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: $externalId,
			timestamp: new DateTimeImmutable('2026-01-02T03:04:05Z'),
			contactId: 'contact-9'
		);

	}//end event()

	/**
	 * Build a metrics service over an in-memory cache.
	 *
	 * @param array<string, mixed> &$store The cache contents (output).
	 *
	 * @return CustomerBridgeMetricsService
	 */
	private function metrics(array &$store): CustomerBridgeMetricsService {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store): mixed {
				return ($store[$key] ?? null);
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->with(CustomerBridgeMetricsService::CACHE_NAMESPACE)->willReturn($cache);

		return new CustomerBridgeMetricsService(cacheFactory: $factory);

	}//end metrics()

	/**
	 * Every deferral is logged at WARNING with the event's identity.
	 *
	 * @return void
	 */
	public function testEnqueueLogsTheDeferredEvent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'pipelinq timeline publish deferred (no persistent queue yet)',
				[
					'app' => 'shillinq',
					'type' => 'booking.created',
					'externalId' => 'booking-7',
					'contactId' => 'contact-9',
				]
			);

		(new LoggingTimelineRetryQueue(logger: $logger))->enqueue(event: $this->event(externalId: 'booking-7'));

	}//end testEnqueueLogsTheDeferredEvent()

	/**
	 * The dead-letter gauge counts the deferrals seen by this queue.
	 *
	 * @return void
	 */
	public function testEnqueueReportsTheRunningDeferralCount(): void {
		$store   = [];
		$metrics = $this->metrics(store: $store);
		$queue   = new LoggingTimelineRetryQueue(
			logger: $this->createMock(LoggerInterface::class),
			metrics: $metrics
		);

		$queue->enqueue(event: $this->event());
		self::assertSame(1, $metrics->snapshot()[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT]);

		$queue->enqueue(event: $this->event(externalId: 'booking-2'));
		$queue->enqueue(event: $this->event(externalId: 'booking-3'));
		self::assertSame(3, $metrics->snapshot()[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT]);

	}//end testEnqueueReportsTheRunningDeferralCount()

	/**
	 * Without a metrics service the queue still logs and does not fail.
	 *
	 * @return void
	 */
	public function testEnqueueWithoutMetricsOnlyLogs(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('warning');

		$queue = new LoggingTimelineRetryQueue(logger: $logger);
		$queue->enqueue(event: $this->event());
		$queue->enqueue(event: $this->event());

	}//end testEnqueueWithoutMetricsOnlyLogs()
}//end class
