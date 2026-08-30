<?php

/**
 * Unit tests for the pipelinq circuit breaker.
 *
 * Verifies the failure threshold (5 → OPEN), the 5-minute cooldown to
 * HALF_OPEN, fail-fast behaviour while OPEN, and the WARNING-level
 * transition callbacks (consumed by `PipelinqContactAdapter` to log
 * each state change). The class is unit-pure: time is supplied via an
 * injectable clock so the test never sleeps.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the CLOSED → OPEN → HALF_OPEN → CLOSED/OPEN state machine.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class CircuitBreakerTest extends TestCase {
	/**
	 * The breaker starts CLOSED and allows requests.
	 *
	 * @return void
	 */
	public function testBreakerStartsClosed(): void {
		$breaker = new CircuitBreaker();

		self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
		self::assertTrue($breaker->allowRequest());

	}//end testBreakerStartsClosed()

	/**
	 * Five consecutive failures open the breaker (default threshold).
	 *
	 * Each transition is reported via the optional callback so the adapter
	 * can log it at WARNING.
	 *
	 * @return void
	 */
	public function testFiveConsecutiveFailuresOpenTheBreaker(): void {
		$now = 1000;
		$transitions = [];
		$breaker = new CircuitBreaker(
			failureThreshold: 5,
			cooldownSeconds: 300,
			clock: static fn (): int => $now,
			onTransition: static function (string $from, string $to, string $reason) use (&$transitions): void {
				$transitions[] = ['from' => $from, 'to' => $to, 'reason' => $reason];
			}
		);

		// 4 failures: still CLOSED.
		for ($i = 1; $i <= 4; $i++) {
			$breaker->recordFailure();
		}

		self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
		self::assertTrue($breaker->allowRequest());
		self::assertSame([], $transitions, 'No transition expected before the threshold');

		// 5th failure trips the breaker.
		$breaker->recordFailure();
		self::assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());
		self::assertFalse($breaker->allowRequest(), 'Breaker must fail fast while OPEN');

		self::assertCount(1, $transitions);
		self::assertSame(CircuitBreaker::STATE_CLOSED, $transitions[0]['from']);
		self::assertSame(CircuitBreaker::STATE_OPEN, $transitions[0]['to']);
		self::assertStringContainsString('5 consecutive failures', $transitions[0]['reason']);

	}//end testFiveConsecutiveFailuresOpenTheBreaker()

	/**
	 * One success between failures resets the counter — the breaker
	 * does NOT open at 5 cumulative failures.
	 *
	 * @return void
	 */
	public function testSuccessResetsTheFailureCounter(): void {
		$breaker = new CircuitBreaker(failureThreshold: 5);

		// 4 failures then a success.
		for ($i = 1; $i <= 4; $i++) {
			$breaker->recordFailure();
		}

		$breaker->recordSuccess();
		self::assertSame(0, $breaker->consecutiveFailures());

		// 4 more failures: still CLOSED.
		for ($i = 1; $i <= 4; $i++) {
			$breaker->recordFailure();
		}

		self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());

	}//end testSuccessResetsTheFailureCounter()

	/**
	 * After the 5-minute cooldown the breaker moves to HALF_OPEN and lets
	 * a single probe through.
	 *
	 * @return void
	 */
	public function testBreakerHalfOpensAfterCooldown(): void {
		$now = 1000;
		$transitions = [];
		$breaker = new CircuitBreaker(
			failureThreshold: 5,
			cooldownSeconds: 300,
			clock: static function () use (&$now): int {
				return $now;
			},
			onTransition: static function (string $from, string $to, string $reason) use (&$transitions): void {
				$transitions[] = ['from' => $from, 'to' => $to, 'reason' => $reason];
			}
		);

		// Trip the breaker.
		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		self::assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());
		self::assertFalse($breaker->allowRequest());

		// 299 seconds later — still OPEN.
		$now += 299;
		self::assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());
		self::assertFalse($breaker->allowRequest());

		// 300 seconds — HALF_OPEN, one probe allowed.
		$now += 1;
		self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());
		self::assertTrue($breaker->allowRequest());

		$halfOpenTransitions = array_values(
			array_filter(
				$transitions,
				static fn (array $t): bool => $t['to'] === CircuitBreaker::STATE_HALF_OPEN
			)
		);
		self::assertCount(1, $halfOpenTransitions);
		self::assertSame('cooldown elapsed', $halfOpenTransitions[0]['reason']);

	}//end testBreakerHalfOpensAfterCooldown()

	/**
	 * A successful probe in HALF_OPEN closes the breaker.
	 *
	 * @return void
	 */
	public function testHalfOpenProbeSuccessClosesBreaker(): void {
		$now = 1000;
		$breaker = new CircuitBreaker(
			failureThreshold: 5,
			cooldownSeconds: 300,
			clock: static function () use (&$now): int {
				return $now;
			}
		);

		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		$now += 301;
		self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());

		$breaker->recordSuccess();
		self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
		self::assertTrue($breaker->allowRequest());
		self::assertSame(0, $breaker->consecutiveFailures());

	}//end testHalfOpenProbeSuccessClosesBreaker()

	/**
	 * A failed probe in HALF_OPEN re-opens the breaker for another cooldown.
	 *
	 * @return void
	 */
	public function testHalfOpenProbeFailureReopensBreaker(): void {
		$now = 1000;
		$transitions = [];
		$breaker = new CircuitBreaker(
			failureThreshold: 5,
			cooldownSeconds: 300,
			clock: static function () use (&$now): int {
				return $now;
			},
			onTransition: static function (string $from, string $to, string $reason) use (&$transitions): void {
				$transitions[] = ['from' => $from, 'to' => $to, 'reason' => $reason];
			}
		);

		for ($i = 1; $i <= 5; $i++) {
			$breaker->recordFailure();
		}

		$now += 301;
		self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());

		$breaker->recordFailure();
		self::assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());
		self::assertFalse($breaker->allowRequest());

		$reopen = array_values(
			array_filter(
				$transitions,
				static fn (array $t): bool => $t['from'] === CircuitBreaker::STATE_HALF_OPEN
			)
		);
		self::assertCount(1, $reopen);
		self::assertSame(CircuitBreaker::STATE_OPEN, $reopen[0]['to']);
		self::assertSame('half-open probe failed', $reopen[0]['reason']);

	}//end testHalfOpenProbeFailureReopensBreaker()
}//end class
