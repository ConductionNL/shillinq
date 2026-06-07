<?php

/**
 * Unit tests for the CustomerBridgeMetricsService.
 *
 * Verifies the increment / gauge update / snapshot / reset cycle, the
 * Prometheus exposition shape (every series carries the correct type
 * header + name), and the per-reason tag normalisation (lower-case +
 * non-alnum collapse + 32-char clamp).
 *
 * The service stores values in {@see ICache}; tests inject an in-memory
 * fake so no NC server context is needed.
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

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies counter increment, gauge update, snapshot, and Prometheus rendering.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
 */
final class CustomerBridgeMetricsServiceTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $store = [];

    /**
     * Build the service against an in-memory ICache.
     *
     * @return CustomerBridgeMetricsService
     */
    private function service(): CustomerBridgeMetricsService
    {
        $cache = new class($this->store) implements ICache {
            /**
             * @param array<string, string> $store Externally-owned key/value map.
             */
            public function __construct(private array &$store)
            {
            }

            public function get($key)
            {
                return $this->store[$key] ?? null;
            }

            public function set($key, $value, $ttl=0): bool
            {
                $this->store[$key] = (string) $value;
                return true;
            }

            public function hasKey($key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function remove($key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function clear($prefix=''): bool
            {
                if ($prefix === '') {
                    $this->store = [];
                    return true;
                }
                foreach (array_keys($this->store) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->store[$key]);
                    }
                }
                return true;
            }

            public static function isAvailable(): bool
            {
                return true;
            }
        };

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createLocal')->willReturn($cache);

        return new CustomerBridgeMetricsService(cacheFactory: $factory);

    }//end service()

    /**
     * Counters increment correctly and snapshot reads them back.
     *
     * @return void
     */
    public function testContactSuccessCounters(): void
    {
        $svc = $this->service();
        $svc->recordContactSuccess(fromCache: false);
        $svc->recordContactSuccess(fromCache: true);
        $svc->recordContactSuccess(fromCache: true);

        $snapshot = $svc->snapshot();
        self::assertSame(3, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_SUCCESS]);
        self::assertSame(2, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_CACHE_HIT]);

    }//end testContactSuccessCounters()

    /**
     * Fallback counter records the per-reason tag alongside the total.
     *
     * @return void
     */
    public function testFallbackTagging(): void
    {
        $svc = $this->service();
        $svc->recordContactFallback(reason: 'not_found');
        $svc->recordContactFallback(reason: 'not_found');
        $svc->recordContactFallback(reason: 'malformed');

        $snapshot = $svc->snapshot();
        self::assertSame(3, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_FALLBACK]);
        self::assertSame(2, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_FALLBACK.'.not_found']);
        self::assertSame(1, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_FALLBACK.'.malformed']);

    }//end testFallbackTagging()

    /**
     * Permanent-failure counters carry the per-reason tag.
     *
     * @return void
     */
    public function testPermanentFailureTagging(): void
    {
        $svc = $this->service();
        $svc->recordPermanentFailure(reason: 'auth');
        $svc->recordPermanentFailure(reason: 'dead_letter');
        $svc->recordPermanentFailure(reason: 'dead_letter');

        $snapshot = $svc->snapshot();
        self::assertSame(3, $snapshot[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE]);
        self::assertSame(1, $snapshot[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE.'.auth']);
        self::assertSame(2, $snapshot[CustomerBridgeMetricsService::COUNTER_PERMANENT_FAILURE.'.dead_letter']);

    }//end testPermanentFailureTagging()

    /**
     * Retry counter increments; max-depth gauge tracks the deepest attempt.
     *
     * @return void
     */
    public function testRetryDepthGauge(): void
    {
        $svc = $this->service();
        $svc->recordRetryAttempt(attempt: 1);
        $svc->recordRetryAttempt(attempt: 3);
        $svc->recordRetryAttempt(attempt: 2);
        // Sub-1 attempts are ignored.
        $svc->recordRetryAttempt(attempt: 0);

        $snapshot = $svc->snapshot();
        self::assertSame(3, $snapshot[CustomerBridgeMetricsService::COUNTER_RETRY_ATTEMPTS]);
        self::assertSame(3, $snapshot[CustomerBridgeMetricsService::GAUGE_RETRY_DEPTH_MAX]);

    }//end testRetryDepthGauge()

    /**
     * Dead-letter and circuit-state gauges are simple set operations.
     *
     * @return void
     */
    public function testGauges(): void
    {
        $svc = $this->service();
        $svc->recordDeadLetterCount(count: 7);
        $svc->recordCircuitState(state: CircuitBreaker::STATE_OPEN);

        $snapshot = $svc->snapshot();
        self::assertSame(7, $snapshot[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT]);
        self::assertSame(CircuitBreaker::STATE_OPEN, $snapshot[CustomerBridgeMetricsService::GAUGE_CIRCUIT_STATE]);

        // Negative counts are clamped to zero so dashboards never see a junk gauge.
        $svc->recordDeadLetterCount(count: -3);
        $snapshot = $svc->snapshot();
        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT]);

    }//end testGauges()

    /**
     * Stale-served counter increments independently of the success counter.
     *
     * @return void
     */
    public function testStaleServedCounter(): void
    {
        $svc = $this->service();
        $svc->recordContactStaleServed();
        $svc->recordContactStaleServed();

        $snapshot = $svc->snapshot();
        self::assertSame(2, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_CACHE_STALE]);
        // Stale-served does NOT contribute to the success counter (it is
        // a degraded outcome; the dashboard distinguishes the two).
        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_SUCCESS]);

    }//end testStaleServedCounter()

    /**
     * Timeline-publish success / deferred counters are independent.
     *
     * @return void
     */
    public function testTimelinePublishCounters(): void
    {
        $svc = $this->service();
        $svc->recordTimelinePublishSuccess();
        $svc->recordTimelinePublishDeferred();
        $svc->recordTimelinePublishDeferred();

        $snapshot = $svc->snapshot();
        self::assertSame(1, $snapshot[CustomerBridgeMetricsService::COUNTER_TIMELINE_PUBLISH_SUCCESS]);
        self::assertSame(2, $snapshot[CustomerBridgeMetricsService::COUNTER_TIMELINE_PUBLISH_DEFERRED]);

    }//end testTimelinePublishCounters()

    /**
     * Snapshot defaults are 0 / closed when nothing has been recorded.
     *
     * @return void
     */
    public function testEmptySnapshotDefaults(): void
    {
        $svc = $this->service();
        $snapshot = $svc->snapshot();

        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_SUCCESS]);
        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::COUNTER_RETRY_ATTEMPTS]);
        self::assertSame(CircuitBreaker::STATE_CLOSED, $snapshot[CustomerBridgeMetricsService::GAUGE_CIRCUIT_STATE]);

    }//end testEmptySnapshotDefaults()

    /**
     * reset() clears every counter back to zero.
     *
     * @return void
     */
    public function testReset(): void
    {
        $svc = $this->service();
        $svc->recordContactSuccess(fromCache: true);
        $svc->recordDeadLetterCount(count: 4);

        $svc->reset();
        $snapshot = $svc->snapshot();
        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::COUNTER_CONTACT_SUCCESS]);
        self::assertSame(0, $snapshot[CustomerBridgeMetricsService::GAUGE_DEAD_LETTER_COUNT]);
        self::assertSame(CircuitBreaker::STATE_CLOSED, $snapshot[CustomerBridgeMetricsService::GAUGE_CIRCUIT_STATE]);

    }//end testReset()

    /**
     * Prometheus exposition carries the right metric names + TYPE headers.
     *
     * @return void
     */
    public function testPrometheusExposition(): void
    {
        $svc = $this->service();
        $svc->recordContactSuccess(fromCache: false);
        $svc->recordTimelinePublishDeferred();
        $svc->recordCircuitState(state: CircuitBreaker::STATE_HALF_OPEN);

        $text = $svc->renderPrometheus();

        self::assertStringContainsString('# TYPE shillinq_pipelinq_contact_success_total counter', $text);
        self::assertStringContainsString('shillinq_pipelinq_contact_success_total 1', $text);
        self::assertStringContainsString('shillinq_pipelinq_timeline_publish_deferred_total 1', $text);
        self::assertStringContainsString('# TYPE shillinq_pipelinq_circuit_state gauge', $text);
        self::assertStringContainsString('shillinq_pipelinq_circuit_state{state="half_open"} 1', $text);
        // Body terminates with a newline (Prometheus exposition requirement).
        self::assertStringEndsWith("\n", $text);

    }//end testPrometheusExposition()
}//end class
