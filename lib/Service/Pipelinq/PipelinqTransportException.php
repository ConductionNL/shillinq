<?php

/**
 * Transport-layer exception for the pipelinq HTTP adapter.
 *
 * Raised by {@see PipelinqContactAdapter} when an HTTP call cannot be
 * completed: the circuit breaker is open, the retry budget is exhausted,
 * the remote returns a non-retryable client error, or the request body
 * cannot be decoded. Carries the HTTP status (when one was returned) so
 * callers can map it to user-facing errors without re-implementing the
 * classification logic.
 *
 * The exception message is safe for logging — credentials and the token
 * are NEVER included.
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
 * Pipelinq transport-layer failure.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class PipelinqTransportException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message Safe-to-log message (never includes the auth token).
	 * @param int $statusCode HTTP status code; 0 when no response was received.
	 * @param \Throwable|null $previous Underlying exception, when any.
	 */
	public function __construct(
		string $message,
		private readonly int $statusCode = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $statusCode, previous: $previous);

	}//end __construct()

	/**
	 * Return the HTTP status that triggered the failure.
	 *
	 * Zero when the failure was network-level (no response, breaker open).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function statusCode(): int {
		return $this->statusCode;
	}//end statusCode()

	/**
	 * Was the failure caused by a fail-fast circuit breaker?
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function isCircuitOpen(): bool {
		return $this->statusCode === 0 && str_contains($this->getMessage(), 'circuit breaker open');
	}//end isCircuitOpen()
}//end class
