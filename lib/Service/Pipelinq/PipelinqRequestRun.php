<?php

/**
 * Pipelinq Request Run
 *
 * The state of one PipelinqContactAdapter::request() call: the method and
 * path, the resolved URL and client options, and the progress of the retry
 * loop (attempt number, last status, whether the last failure was transient
 * and the exception to raise when the loop gives up).
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
 * @spec openspec/changes/archive/2026-06-14-bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Per-call state of the pipelinq retry loop.
 *
 * @spec openspec/changes/archive/2026-06-14-bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
final class PipelinqRequestRun {

	/**
	 * Fully qualified request URL, once resolved.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * Guzzle-shaped client options, once built.
	 *
	 * @var array<string, mixed>
	 */
	public array $options = [];

	/**
	 * Number of the attempt in progress (1-based once the loop runs).
	 *
	 * @var int
	 */
	public int $attempt = 0;

	/**
	 * Whether the last failed attempt is worth retrying.
	 *
	 * @var bool
	 */
	public bool $transient = false;

	/**
	 * HTTP status of the last failed attempt (0 at the transport layer).
	 *
	 * @var int
	 */
	public int $lastStatusCode = 0;

	/**
	 * HTTP status of the successful response.
	 *
	 * @var int
	 */
	public int $successStatus = 0;

	/**
	 * Exception describing the last failed attempt.
	 *
	 * @var PipelinqTransportException|null
	 */
	public ?PipelinqTransportException $lastException = null;

	/**
	 * Constructor.
	 *
	 * @param string $method HTTP method (GET / POST / PUT / DELETE).
	 * @param string $path   Path relative to the endpoint.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
	) {

	}//end __construct()

	/**
	 * Log context naming this request: app, method and path.
	 *
	 * @param string $appId The app id to tag the log line with.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/archive/2026-06-14-bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
	 */
	public function logContext(string $appId): array {
		return [
			'app'    => $appId,
			'method' => $this->method,
			'path'   => $this->path,
		];

	}//end logContext()
}//end class
