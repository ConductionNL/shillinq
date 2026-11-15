<?php

/**
 * Unit tests for RetryPolicy: which failures are retried, how often and how long to wait.
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

use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Transient failures are retried up to the attempt budget with a 1-2-4 second
 * backoff; every other failure stops at once.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase {

	/**
	 * Request Timeout, Too Many Requests and every 5xx are transient; the rest is not.
	 *
	 * @return void
	 */
	public function testTransientStatuses(): void {
		$policy = new RetryPolicy();

		foreach ([408, 429, 500, 502, 503, 599] as $status) {
			self::assertTrue($policy->isTransientStatus(statusCode: $status), (string) $status);
		}

		foreach ([200, 204, 301, 400, 401, 403, 404, 407, 422, 428, 430, 499, 600] as $status) {
			self::assertFalse($policy->isTransientStatus(statusCode: $status), (string) $status);
		}

	}//end testTransientStatuses()

	/**
	 * A transient failure is retried until the last attempt; a non-transient one never is.
	 *
	 * @return void
	 */
	public function testRetriesOnlyTransientFailuresWithinTheBudget(): void {
		$policy = new RetryPolicy();

		self::assertSame(3, RetryPolicy::MAX_ATTEMPTS);
		self::assertTrue($policy->shouldRetry(attempt: 1, isTransient: true));
		self::assertTrue($policy->shouldRetry(attempt: 2, isTransient: true));
		self::assertFalse($policy->shouldRetry(attempt: 3, isTransient: true), 'The last attempt is not retried.');
		self::assertFalse($policy->shouldRetry(attempt: 1, isTransient: false));

	}//end testRetriesOnlyTransientFailuresWithinTheBudget()

	/**
	 * The backoff doubles per attempt and is zero outside the schedule.
	 *
	 * @return void
	 */
	public function testBackoffSchedule(): void {
		$policy = new RetryPolicy();

		self::assertSame(1, $policy->backoffSeconds(attempt: 1));
		self::assertSame(2, $policy->backoffSeconds(attempt: 2));
		self::assertSame(4, $policy->backoffSeconds(attempt: 3));
		self::assertSame(0, $policy->backoffSeconds(attempt: 4));
		self::assertSame(0, $policy->backoffSeconds(attempt: 0));

	}//end testBackoffSchedule()
}//end class
