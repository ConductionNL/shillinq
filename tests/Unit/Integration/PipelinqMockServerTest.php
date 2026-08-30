<?php

/**
 * Smoke test for the PipelinqMockServer integration-test scaffold.
 *
 * Ensures the in-process mock router (which members 03-10 reuse)
 * resolves the four canonical routes against the bundled fixtures
 * and honours forceStatus() / request-history capture used by
 * retry/circuit-breaker tests downstream.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
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

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\Shillinq\Tests\Integration\Pipelinq\PipelinqMockServer;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the PipelinqMockServer scaffold.
 */
final class PipelinqMockServerTest extends TestCase {
	/**
	 * Health route returns 200 with the canned ok payload.
	 */
	public function testHealthRouteReturnsOk(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(method: 'GET', path: '/health');
		self::assertSame(200, $response['status']);
		$payload = json_decode($response['body'], true);
		self::assertSame('ok', $payload['status']);

	}//end testHealthRouteReturnsOk()

	/**
	 * Contact route resolves the canned fixture for the externalId
	 * in the design example (org-kvk-12345678).
	 */
	public function testContactRouteServesCannedFixture(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(
			method: 'GET',
			path: '/contacts/org-kvk-12345678'
		);
		self::assertSame(200, $response['status']);
		$payload = json_decode($response['body'], true);
		self::assertSame('org-kvk-12345678', $payload['externalId']);
		self::assertSame('Bakkerij de Zon B.V.', $payload['legalName']);

	}//end testContactRouteServesCannedFixture()

	/**
	 * Klantbeeld route serves the canned summary fixture.
	 */
	public function testKlantbeeldRouteServesCannedFixture(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(
			method: 'GET',
			path: '/klantbeeld/org-kvk-12345678'
		);
		self::assertSame(200, $response['status']);
		$payload = json_decode($response['body'], true);
		self::assertArrayHasKey('summary', $payload);
		self::assertSame(12450.00, $payload['summary']['lifetimeValueEur']);

	}//end testKlantbeeldRouteServesCannedFixture()

	/**
	 * Timeline GET serves the canned entry list.
	 */
	public function testTimelineGetRouteServesCannedFixture(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(
			method: 'GET',
			path: '/contacts/org-kvk-12345678/timeline'
		);
		self::assertSame(200, $response['status']);
		$payload = json_decode($response['body'], true);
		self::assertCount(3, $payload['entries']);

	}//end testTimelineGetRouteServesCannedFixture()

	/**
	 * Timeline POST returns 202 — used by member 07 publish tests.
	 */
	public function testTimelinePostReturnsAccepted(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(
			method: 'POST',
			path: '/contacts/org-kvk-12345678/timeline',
			body: json_encode(['type' => 'booking.created'])
		);
		self::assertSame(202, $response['status']);
		$payload = json_decode($response['body'], true);
		self::assertTrue($payload['accepted']);
		self::assertSame('org-kvk-12345678', $payload['externalId']);

	}//end testTimelinePostReturnsAccepted()

	/**
	 * Unknown route resolves as 404 with a JSON envelope.
	 */
	public function testUnknownRouteReturns404(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(method: 'GET', path: '/no-such-endpoint');
		self::assertSame(404, $response['status']);

	}//end testUnknownRouteReturns404()

	/**
	 * forceStatus() applies once and is cleared after the next dispatch
	 * so retry/circuit-breaker tests can simulate transient outages.
	 */
	public function testForceStatusAppliesOnceOnly(): void {
		$mock = new PipelinqMockServer();
		$mock->forceStatus(503);
		$first = $mock->dispatch(method: 'GET', path: '/health');
		self::assertSame(503, $first['status']);

		$second = $mock->dispatch(method: 'GET', path: '/health');
		self::assertSame(200, $second['status']);

	}//end testForceStatusAppliesOnceOnly()

	/**
	 * Request history is captured so tests can assert what the
	 * adapter actually called.
	 */
	public function testRequestHistoryIsCaptured(): void {
		$mock = new PipelinqMockServer();
		$mock->dispatch(method: 'GET', path: '/health');
		$mock->dispatch(
			method: 'POST',
			path: '/contacts/org-kvk-12345678/timeline',
			body: '{"x":1}'
		);

		$history = $mock->getRequests();
		self::assertCount(2, $history);
		self::assertSame('GET', $history[0]['method']);
		self::assertSame('POST', $history[1]['method']);
		self::assertSame('{"x":1}', $history[1]['body']);

	}//end testRequestHistoryIsCaptured()

	/**
	 * Missing fixture resolves to 404 so tests can exercise the
	 * "Contact not found in pipelinq" branch (member 03).
	 */
	public function testMissingFixtureResolvesAs404(): void {
		$mock = new PipelinqMockServer();
		$response = $mock->dispatch(
			method: 'GET',
			path: '/contacts/does-not-exist'
		);
		self::assertSame(404, $response['status']);

	}//end testMissingFixtureResolvesAs404()
}//end class
