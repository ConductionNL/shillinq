<?php

/**
 * In-process pipelinq API mock used by the customer-bridge integration
 * test scaffold.
 *
 * Reused by every member of the bookings-pipelinq-customer-bridge
 * chain that needs a deterministic pipelinq HTTP surface (Contact
 * read in member 03, klantbeeld read in member 04, timeline publish
 * in member 07, lifecycle events in member 08, retry/circuit-breaker
 * in member 09, end-to-end in member 10). Returns canned Contact,
 * klantbeeld and timeline fixtures from
 * tests/Integration/Pipelinq/fixtures/.
 *
 * Implementation note — the mock is an in-process router (no socket
 * listener). Members 03+ plug it into an OCP\Http\Client\IClient
 * test double that calls `dispatch()` instead of issuing a real
 * HTTP request. This keeps the scaffold dependency-free and CI-safe
 * (no port conflicts, no flaky network).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration\Pipelinq
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration\Pipelinq;

use RuntimeException;

/**
 * In-process pipelinq API mock used by the chain's integration tests.
 *
 * The router matches incoming method+path pairs against:
 *   - GET  /health                         → 200 { status: "ok" }
 *   - GET  /contacts/{externalId}          → fixtures/contact-{id}.json
 *   - GET  /klantbeeld/{externalId}        → fixtures/klantbeeld-{id}.json
 *   - GET  /contacts/{externalId}/timeline → fixtures/timeline-{id}.json
 *   - POST /contacts/{externalId}/timeline → 202 echo (member 07 publish)
 *
 * Unmatched routes resolve as 404 with a JSON envelope so callers can
 * exercise the "missing fixture" path. The mock also exposes a
 * scripted-failure mode (`forceStatus(int $code)`) used by members
 * 09 (retry/circuit-breaker) and 10 (end-to-end). Members 02+ wire
 * the mock through their HTTP adapter test seam.
 */
final class PipelinqMockServer {

	/**
	 * Path to the bundled fixture directory.
	 *
	 * @var string
	 */
	private string $fixtureDir;

	/**
	 * Override status code returned for the NEXT dispatch when set.
	 *
	 * @var integer|null
	 */
	private ?int $forcedStatus = null;

	/**
	 * History of dispatched requests for assertion in tests.
	 *
	 * @var array<int, array{method: string, path: string, body: ?string}>
	 */
	private array $requests = [];

	/**
	 * Constructor.
	 *
	 * @param string|null $fixtureDir Override fixture directory. Defaults
	 *                                to the bundled `fixtures/` next to
	 *                                this class.
	 */
	public function __construct(?string $fixtureDir = null) {
		$this->fixtureDir = ($fixtureDir ?? (__DIR__ . '/fixtures'));

	}//end __construct()

	/**
	 * Force the next dispatch to return the given HTTP status code.
	 *
	 * Used by retry/circuit-breaker tests (member 09) to simulate
	 * transient and permanent server-side failures. The forced code
	 * applies once and is cleared after the next dispatch.
	 *
	 * @param int $code HTTP status code to return.
	 *
	 * @return void
	 */
	public function forceStatus(int $code): void {
		$this->forcedStatus = $code;

	}//end forceStatus()

	/**
	 * Return the full request-history captured by dispatch().
	 *
	 * @return array<int, array{method: string, path: string, body: ?string}>
	 */
	public function getRequests(): array {
		return $this->requests;
	}//end getRequests()

	/**
	 * Reset the request history and any forced-status override.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->requests = [];
		$this->forcedStatus = null;

	}//end reset()

	/**
	 * Dispatch an HTTP request through the in-process router.
	 *
	 * @param string $method HTTP method (GET, POST).
	 * @param string $path Request path (must start with `/`).
	 * @param string|null $body Request body for POST routes.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public function dispatch(string $method, string $path, ?string $body = null): array {
		$this->requests[] = [
			'method' => $method,
			'path' => $path,
			'body' => $body,
		];

		if ($this->forcedStatus !== null) {
			$forced = $this->forcedStatus;
			$this->forcedStatus = null;
			return $this->jsonResponse(
				status: $forced,
				payload: ['error' => 'forced status ' . $forced]
			);
		}

		if ($method === 'GET' && $path === '/health') {
			return $this->jsonResponse(status: 200, payload: ['status' => 'ok']);
		}

		if ($method === 'GET' && preg_match('#^/contacts/([^/]+)$#', $path, $m) === 1) {
			return $this->fixtureResponse(prefix: 'contact', externalId: $m[1]);
		}

		if ($method === 'GET' && preg_match('#^/klantbeeld/([^/]+)$#', $path, $m) === 1) {
			return $this->fixtureResponse(prefix: 'klantbeeld', externalId: $m[1]);
		}

		if ($method === 'GET' && preg_match('#^/contacts/([^/]+)/timeline$#', $path, $m) === 1) {
			return $this->fixtureResponse(prefix: 'timeline', externalId: $m[1]);
		}

		if ($method === 'POST' && preg_match('#^/contacts/([^/]+)/timeline$#', $path, $m) === 1) {
			return $this->jsonResponse(
				status: 202,
				payload: [
					'accepted' => true,
					'externalId' => $m[1],
					'entryId' => 'mock-' . bin2hex(random_bytes(4)),
				]
			);
		}

		return $this->jsonResponse(
			status: 404,
			payload: [
				'error' => 'no_route',
				'path' => $path,
			]
		);

	}//end dispatch()

	/**
	 * Resolve a fixture file from disk and return it as a response.
	 *
	 * Falls back to 404 when the file is missing — used by tests to
	 * exercise the "Contact not found in pipelinq" branch.
	 *
	 * @param string $prefix Fixture filename prefix (contact/klantbeeld/timeline).
	 * @param string $externalId Pipelinq Contact externalId.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private function fixtureResponse(string $prefix, string $externalId): array {
		$file = $this->fixtureDir . '/' . $prefix . '-' . $externalId . '.json';
		if (file_exists($file) === false) {
			return $this->jsonResponse(
				status: 404,
				payload: [
					'error' => 'not_found',
					'externalId' => $externalId,
				]
			);
		}

		$body = file_get_contents($file);
		if ($body === false) {
			throw new RuntimeException('Failed to read fixture ' . $file);
		}

		return [
			'status' => 200,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => $body,
		];

	}//end fixtureResponse()

	/**
	 * Build a JSON HTTP response envelope.
	 *
	 * @param int $status HTTP status code.
	 * @param array<string, mixed> $payload Payload to JSON-encode.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private function jsonResponse(int $status, array $payload): array {
		return [
			'status' => $status,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => (string)json_encode($payload),
		];

	}//end jsonResponse()
}//end class
