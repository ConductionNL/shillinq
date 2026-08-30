<?php

/**
 * Unit tests for BcfClaimController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BcfClaimController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BcfClaimService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the Btw-compensatiefonds compensable-VAT endpoint.
 *
 * Covers REQ-BCF-002/-004 (breakdown contract), REQ-BCF-010 (identifier
 * validation), REQ-BCF-012 (per-administration IDOR guard) and the 500 fail
 * path that leaks no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfClaimControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock BcfClaimService.
	 *
	 * @var BcfClaimService&MockObject
	 */
	private BcfClaimService&MockObject $service;

	/**
	 * Mock AdministrationContextService (the IDOR guard).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var BcfClaimController
	 */
	private BcfClaimController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(BcfClaimService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$this->controller = new BcfClaimController(
			request: $this->request,
			bcfClaimService: $this->service,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,mixed> $map Param map.
	 *
	 * @return void
	 */
	private function withParams(array $map): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return ($map[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * Grant or deny administration access on the guard.
	 *
	 * @param bool $allowed Whether the user may access the administration.
	 *
	 * @return void
	 */
	private function access(bool $allowed): void {
		$this->context->method('canAccess')->willReturn($allowed);

	}//end access()

	/**
	 * A missing administration_id yields HTTP 400 and never reaches the service.
	 *
	 * @return void
	 */
	public function testCompensationMissingAdministrationReturns400(): void {
		$this->withParams(['claim_quarter' => '2026-Q1']);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testCompensationMissingAdministrationReturns400()

	/**
	 * A missing claim_quarter yields HTTP 400.
	 *
	 * @return void
	 */
	public function testCompensationMissingQuarterReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCompensationMissingQuarterReturns400()

	/**
	 * A whitespace-only administration_id is treated as missing, not as a valid
	 * scope — the parameter is trimmed before the emptiness check.
	 *
	 * @return void
	 */
	public function testCompensationBlankAdministrationIsTreatedAsMissing(): void {
		$this->withParams(['administration_id' => '   ', 'claim_quarter' => '2026-Q1']);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCompensationBlankAdministrationIsTreatedAsMissing()

	/**
	 * A path-traversal administration_id is rejected with HTTP 400 before the
	 * data layer is touched (REQ-BCF-010).
	 *
	 * @return void
	 */
	public function testCompensationRejectsTraversalAdministrationId(): void {
		$this->withParams(['administration_id' => '../../etc/passwd', 'claim_quarter' => '2026-Q1']);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('administration_id', (string)$response->getData()['error']);

	}//end testCompensationRejectsTraversalAdministrationId()

	/**
	 * A malformed claim_quarter is rejected with HTTP 400 (REQ-BCF-001).
	 *
	 * @return void
	 */
	public function testCompensationRejectsMalformedQuarter(): void {
		$this->withParams(['administration_id' => 'adm-1', 'claim_quarter' => '2026 Q1; DROP']);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('claim_quarter', (string)$response->getData()['error']);

	}//end testCompensationRejectsMalformedQuarter()

	/**
	 * A user without membership of the administration gets HTTP 403 and the
	 * breakdown is never computed (REQ-BCF-012, ADR-005 Rule 3).
	 *
	 * @return void
	 */
	public function testCompensationDeniesForeignAdministrationWith403(): void {
		$this->withParams(['administration_id' => 'adm-other', 'claim_quarter' => '2026-Q1']);
		$this->access(false);
		$this->service->expects($this->never())->method('computeClaim');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCompensationDeniesForeignAdministrationWith403()

	/**
	 * A valid, permitted request returns HTTP 200 with the per-account
	 * breakdown and the quarter total from the service (REQ-BCF-002).
	 *
	 * @return void
	 */
	public function testCompensationReturnsBreakdownForPermittedAdministration(): void {
		$this->withParams(['administration_id' => 'adm-1', 'claim_quarter' => '2026-Q1']);
		$this->access(true);

		$payload = [
			'administrationId' => 'adm-1',
			'claimQuarter' => '2026-Q1',
			'totalCompensableAmount' => 1815.50,
			'breakdown' => [
				['glAccount' => '4300', 'vatAmount' => 1000.00, 'compensablePercentage' => 100, 'compensableAmount' => 1000.00],
				['glAccount' => '4310', 'vatAmount' => 1631.00, 'compensablePercentage' => 50, 'compensableAmount' => 815.50],
			],
		];
		$this->service->expects($this->once())->method('computeClaim')->willReturn($payload);

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());
		self::assertCount(2, $response->getData()['breakdown']);

	}//end testCompensationReturnsBreakdownForPermittedAdministration()

	/**
	 * The administration + quarter reaching the service are exactly the ones
	 * the guard approved — a scope swap between guard and read would be an IDOR.
	 *
	 * @return void
	 */
	public function testCompensationPassesGuardedScopeToService(): void {
		$this->withParams(['administration_id' => ' adm-1 ', 'claim_quarter' => ' 2026-Q4 ']);

		$guarded = null;
		$this->context->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use (&$guarded): bool {
				$guarded = $administrationId;
				return true;
			}
		);

		$seen = [];
		$this->service->method('computeClaim')->willReturnCallback(
			static function (string $administrationId, string $claimQuarter) use (&$seen): array {
				$seen = ['administrationId' => $administrationId, 'claimQuarter' => $claimQuarter];
				return [
					'administrationId' => $administrationId,
					'claimQuarter' => $claimQuarter,
					'totalCompensableAmount' => 0.0,
					'breakdown' => [],
				];
			}
		);

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('adm-1', $guarded);
		self::assertSame('adm-1', $seen['administrationId']);
		self::assertSame('2026-Q4', $seen['claimQuarter']);

	}//end testCompensationPassesGuardedScopeToService()

	/**
	 * A GL-fetch failure yields HTTP 500, is logged, and leaks neither the
	 * exception message nor a stack trace to the client (ADR-005).
	 *
	 * @return void
	 */
	public function testCompensationServiceFailureReturns500WithoutLeak(): void {
		$this->withParams(['administration_id' => 'adm-1', 'claim_quarter' => '2026-Q1']);
		$this->access(true);
		$this->service->method('computeClaim')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02] gl_transactions missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->compensation();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Failed to compute compensable VAT', $response->getData()['error']);
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));

	}//end testCompensationServiceFailureReturns500WithoutLeak()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
