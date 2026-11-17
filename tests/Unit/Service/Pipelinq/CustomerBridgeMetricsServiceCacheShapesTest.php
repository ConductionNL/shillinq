<?php

/**
 * Unit tests for CustomerBridgeMetricsService over raw cache values and unusual reason tags.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A distributed cache may hand back a native integer, a non-scalar or junk
 * for a counter, and a reason tag may be empty or pure punctuation; the
 * service must read and key all of them predictably.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
 */
#[CoversClass(CustomerBridgeMetricsService::class)]
final class CustomerBridgeMetricsServiceCacheShapesTest extends TestCase {

	/**
	 * Raw cache contents, stored without casting.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * The service over an ICache that keeps values exactly as given.
	 *
	 * @return CustomerBridgeMetricsService
	 */
	private function service(): CustomerBridgeMetricsService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);

		return new CustomerBridgeMetricsService($factory);

	}//end service()

	/**
	 * A counter stored as a native integer is read as that integer and incremented from it.
	 *
	 * @return void
	 */
	public function testANativeIntegerCounterIsReadAsIs(): void {
		$this->store[CustomerBridgeMetricsService::COUNTER_TIMELINE_PUBLISH_SUCCESS] = 41;
		$service = $this->service();

		self::assertSame(41, $service->snapshot()[CustomerBridgeMetricsService::COUNTER_TIMELINE_PUBLISH_SUCCESS]);

		$service->recordTimelinePublishSuccess();
		self::assertSame('42', $this->store[CustomerBridgeMetricsService::COUNTER_TIMELINE_PUBLISH_SUCCESS]);

	}//end testANativeIntegerCounterIsReadAsIs()

	/**
	 * A non-scalar, a float, a negative or a non-numeric string reads as zero.
	 *
	 * @return void
	 */
	public function testUnreadableCounterValuesReadAsZero(): void {
		$service = $this->service();
		$key     = CustomerBridgeMetricsService::COUNTER_RETRY_ATTEMPTS;

		foreach ([['7'], 7.5, '-3', '12abc', '', true] as $raw) {
			$this->store[$key] = $raw;
			self::assertSame(0, $service->snapshot()[$key], var_export($raw, true));
		}

		$this->store[$key] = ['7'];
		$service->recordRetryAttempt(attempt: 1);
		self::assertSame('1', $this->store[$key], 'Incrementing an unreadable counter starts from zero.');

	}//end testUnreadableCounterValuesReadAsZero()

	/**
	 * An empty or punctuation-only reason is counted under `unknown`.
	 *
	 * @return void
	 */
	public function testAReasonWithoutLettersOrDigitsIsUnknown(): void {
		$service = $this->service();

		$service->recordPermanentFailure(reason: '');
		$service->recordPermanentFailure(reason: '!!! ---');
		$service->recordContactFallback(reason: '___');

		self::assertSame('2', $this->store[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE . '.unknown']);
		self::assertSame('1', $this->store[CustomerBridgeMetricsService::COUNTER_CONTACT_FALLBACK . '.unknown']);

	}//end testAReasonWithoutLettersOrDigitsIsUnknown()

	/**
	 * A reason is lower-cased, joined with underscores, trimmed of them and clamped to 32 characters.
	 *
	 * @return void
	 */
	public function testAReasonIsNormalisedAndClamped(): void {
		$service = $this->service();

		$service->recordPermanentFailure(reason: '  Upstream: TIMEOUT (504)  ');
		$service->recordPermanentFailure(reason: str_repeat('ab', 40));

		self::assertSame('1', $this->store[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE . '.upstream_timeout_504']);
		self::assertSame('1', $this->store[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE . '.' . str_repeat('ab', 16)]);

	}//end testAReasonIsNormalisedAndClamped()
}//end class
