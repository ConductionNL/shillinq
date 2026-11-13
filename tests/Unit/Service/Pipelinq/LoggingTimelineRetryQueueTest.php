<?php

/**
 * Unit tests for LoggingTimelineRetryQueue.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTimeImmutable;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\LoggingTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The fallback queue used before a persistent one exists: every deferred
 * event is a warning naming it, and the running count of deferrals is
 * reported as the dead-letter gauge when metrics are wired.
 *
 * @covers \OCA\Shillinq\Service\Pipelinq\LoggingTimelineRetryQueue
 * @uses \OCA\Shillinq\Service\Pipelinq\TimelineEventDto
 * @uses \OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService
 */
final class LoggingTimelineRetryQueueTest extends TestCase {

	/**
	 * A timeline event.
	 *
	 * @param string $externalId The external id.
	 *
	 * @return TimelineEventDto The event.
	 */
	private static function event(string $externalId): TimelineEventDto {
		return new TimelineEventDto('booking.created', $externalId, new DateTimeImmutable('2026-11-01T10:00:00Z'), 'contact-1');
	}//end event()

	/**
	 * Each deferral is warned about and the gauge counts them.
	 *
	 * @return void
	 */
	public function testEachDeferralIsLoggedAndCounted(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))
			->method('warning')
			->with(
				'pipelinq timeline publish deferred (no persistent queue yet)',
				$this->callback(static fn (array $context): bool => $context['app'] === 'shillinq' && $context['type'] === 'booking.created' && $context['contactId'] === 'contact-1')
			);
		$gauge = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value) use (&$gauge): bool {
				$gauge[] = [$key, $value];

				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);
		$queue = new LoggingTimelineRetryQueue($logger, new CustomerBridgeMetricsService($factory));

		$queue->enqueue(self::event('b-1'));
		$queue->enqueue(self::event('b-2'));

		$this->assertSame(
			[
				[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT, '1'],
				[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT, '2'],
			],
			$gauge
		);
	}//end testEachDeferralIsLoggedAndCounted()

	/**
	 * Without metrics a deferral is still logged.
	 *
	 * @return void
	 */
	public function testWithoutMetricsADeferralIsStillLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'pipelinq timeline publish deferred (no persistent queue yet)',
				['app' => 'shillinq', 'type' => 'booking.created', 'externalId' => 'b-9', 'contactId' => 'contact-1']
			);

		(new LoggingTimelineRetryQueue($logger))->enqueue(self::event('b-9'));
	}//end testWithoutMetricsADeferralIsStillLogged()
}//end class
