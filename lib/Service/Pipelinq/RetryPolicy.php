<?php

/**
 * Retry policy for the pipelinq HTTP adapter.
 *
 * Encapsulates the bounded exponential-backoff behaviour required by spec
 * `bookings-pipelinq-integration` ("The adapter SHALL provide a resilient
 * HTTP transport with bounded retries"): up to 3 attempts on transient
 * failures with delays of 1s, 2s, 4s, and zero retries on non-transient
 * client errors. The policy itself is pure decision logic — the caller owns
 * the actual sleep so the policy can be unit tested without timing.
 *
 * A failure classifier decides what counts as "transient": 5xx responses,
 * 408 Request Timeout, and 429 Too Many Requests are retried; every other
 * 4xx surfaces to the caller without delay.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
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

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Pure-logic retry policy: exponential backoff 1s/2s/4s, max 3 attempts.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class RetryPolicy {
	/**
	 * Maximum number of attempts (including the first one).
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Backoff schedule in seconds, indexed by attempt number (1-based).
	 *
	 * @var array<int, int>
	 */
	private const BACKOFF_SCHEDULE = [
		1 => 1,
		2 => 2,
		3 => 4,
	];

	/**
	 * Decide whether the caller should retry after this attempt.
	 *
	 * @param int $attempt 1-based attempt counter that just completed.
	 * @param bool $isTransient Whether the failure is classified as transient (5xx / 408 / 429 / network).
	 *
	 * @return bool TRUE when the caller MUST schedule another attempt; FALSE when the loop should exit.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function shouldRetry(int $attempt, bool $isTransient): bool {
		if ($isTransient === false) {
			return false;
		}

		return $attempt < self::MAX_ATTEMPTS;
	}//end shouldRetry()

	/**
	 * Backoff delay (in seconds) to apply BEFORE the next attempt.
	 *
	 * Returns the schedule element for the just-completed attempt.
	 *
	 * @param int $attempt 1-based attempt counter that just completed.
	 *
	 * @return int Seconds to wait before the next attempt (1, 2, or 4); 0 when the schedule is exhausted.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function backoffSeconds(int $attempt): int {
		return (self::BACKOFF_SCHEDULE[$attempt] ?? 0);
	}//end backoffSeconds()

	/**
	 * Classify an HTTP status code as transient (retry-worthy) or not.
	 *
	 * Retried: 408 Request Timeout, 429 Too Many Requests, every 5xx.
	 * Not retried: every other 4xx (incl. 400/401/403/404/422).
	 * Successes (1xx/2xx/3xx) are never "failures" so this method returns
	 * FALSE for them too — the caller decides what counts as success.
	 *
	 * @param int $statusCode HTTP status code from the response.
	 *
	 * @return bool TRUE when the status code is a transient failure.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function isTransientStatus(int $statusCode): bool {
		if ($statusCode === 408 || $statusCode === 429) {
			return true;
		}

		return $statusCode >= 500 && $statusCode < 600;
	}//end isTransientStatus()
}//end class
