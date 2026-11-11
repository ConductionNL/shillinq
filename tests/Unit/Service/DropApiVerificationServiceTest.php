<?php

/**
 * Unit tests for DropApiVerificationService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DropApiVerificationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DROP-API verification helper (REQ-WMO-005 §verification).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DropApiVerificationServiceTest extends TestCase {

	/**
	 * Service under test.
	 */
	private DropApiVerificationService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new DropApiVerificationService();

	}//end setUp()

	/**
	 * Compose lookup uses the publicatieGemeenteblad reference.
	 */
	public function testComposeLookupRequest(): void {
		$r = $this->svc->composeLookupRequest(['publicationMunicipalGazette' => 'gmb-2025-401']);
		self::assertTrue($r['ok']);
		self::assertSame('gmb-2025-401', $r['gemeentebladId']);
		self::assertSame(['identifier' => 'gmb-2025-401'], $r['request']['query']);

	}//end testComposeLookupRequest()

	/**
	 * No gemeenteblad reference yields ok=false.
	 */
	public function testComposeLookupWithoutReferenceFails(): void {
		$r = $this->svc->composeLookupRequest([]);
		self::assertFalse($r['ok']);

	}//end testComposeLookupWithoutReferenceFails()

	/**
	 * Empty / null response → fail-soft message.
	 */
	public function testParseConnectionFailure(): void {
		$r = $this->svc->parseResponse(null, null);
		self::assertFalse($r['success']);
		self::assertStringContainsString('unavailable', $r['message']);

	}//end testParseConnectionFailure()

	/**
	 * 404 → not found.
	 */
	public function testParseNotFound(): void {
		$r = $this->svc->parseResponse('{}', 404);
		self::assertFalse($r['success']);
		self::assertStringContainsString('not found', $r['message']);

	}//end testParseNotFound()

	/**
	 * 200 with bindings → success.
	 */
	public function testParseSuccess(): void {
		$body = json_encode(['results' => ['bindings' => [['identifier' => 'gmb-2025-401']]]]);
		$r = $this->svc->parseResponse($body, 200);
		self::assertTrue($r['success']);
		self::assertSame('Verified', $r['message']);

	}//end testParseSuccess()

	/**
	 * applyVerification writes onto the ABB.
	 */
	public function testApplyVerification(): void {
		$abb = ['status' => 'publicatie'];
		$verified = ['verifiedAt' => '2026-01-01T00:00:00Z', 'success' => true, 'message' => 'Verified'];
		$updated = $this->svc->applyVerification($abb, $verified);
		self::assertSame($verified, $updated['dropVerification']);

	}//end testApplyVerification()

	/**
	 * Pin the success flag and message of every response class, and the
	 * verifiedAt format (not its value, which is the current instant).
	 *
	 * @return void
	 */
	public function testEveryResponseClassIsPinned(): void {
		$service = new DropApiVerificationService();
		$cases = [
			[null, 200, false, 'DROP API unavailable (no response)'],
			['{}', null, false, 'DROP API unavailable (no response)'],
			['', 500, false, 'DROP API error 500'],
			['', 503, false, 'DROP API error 503'],
			['{"results":{"bindings":[1]}}', 404, false, 'Gemeenteblad reference not found in DROP'],
			['', 301, false, 'DROP API HTTP 301'],
			['', 201, false, 'DROP API HTTP 201'],
			['not json', 200, false, 'DROP API returned invalid JSON'],
			['"scalar"', 200, false, 'DROP API returned invalid JSON'],
			['{}', 200, false, 'No matching publication in DROP'],
			['{"results":{"bindings":[]}}', 200, false, 'No matching publication in DROP'],
			['{"results":{"bindings":"x"}}', 200, true, 'Verified'],
			['{"results":{"bindings":[{"a":1}]}}', 200, true, 'Verified'],
		];
		foreach ($cases as $index => [$raw, $status, $success, $message]) {
			$result = $service->parseResponse($raw, $status);
			self::assertSame(['verifiedAt', 'success', 'message'], array_keys($result), 'case ' . $index);
			self::assertSame([$success, $message], [$result['success'], $result['message']], 'case ' . $index);
			self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $result['verifiedAt']);
		}

	}//end testEveryResponseClassIsPinned()
}//end class
