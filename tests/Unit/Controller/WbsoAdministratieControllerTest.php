<?php

/**
 * Unit tests for WbsoAdministratieController.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WbsoAdministratieController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoAdministratieService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the WBSO realisatie API controller.
 *
 * Covers REQ-WBSO-010 (endpoint contract), REQ-WBSO-004 (administration
 * validation) and the 500 fail path that returns no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoAdministratieControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock WbsoAdministratieService.
	 *
	 * @var WbsoAdministratieService&MockObject
	 */
	private WbsoAdministratieService&MockObject $service;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock AdministrationContextService — the ADR-005 membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * What canAccess() answers. Defaults to true (member of every
	 * administration asked about) so the pre-existing tests, none of which
	 * were written with the guard in mind, keep exercising the same
	 * happy-path shape they always did.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var WbsoAdministratieController
	 */
	private WbsoAdministratieController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(WbsoAdministratieService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->canAccess = true;
		$this->administrationContext->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);
		$this->controller = new WbsoAdministratieController(
			request: $this->request,
			service: $this->service,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
		);

	}//end setUp()

	/**
	 * Configure the administration_id request param.
	 *
	 * @param string $admin The administration_id param value.
	 *
	 * @return void
	 */
	private function withAdmin(string $admin): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($admin): mixed {
				if ($key === 'administration_id') {
					return $admin;
				}

				return $default;
			}
		);

	}//end withAdmin()

	/**
	 * A valid request returns 200 with the service result.
	 *
	 * @return void
	 */
	public function testReturnsOkWithRealisatieSummary(): void {
		$this->withAdmin('adm-a');
		$payload = ['data' => [['decisionNumber' => 'WBSO-1', 'exceeded' => false]], 'total' => 1];
		$this->service->expects(self::once())
			->method('realisatieSummary')
			->with('adm-a')
			->willReturn($payload);

		$response = $this->controller->realisatie();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testReturnsOkWithRealisatieSummary()

	/**
	 * ADR-005 Rule 3 / REQ-001 (security-endpoint-guards): a caller with no
	 * AdministrationMembership for the requested administration_id is
	 * refused with a masked 404 — proving the request never previously
	 * enforced any membership check, any authenticated user could read
	 * another organization's statutory WBSO realisatie by quoting its
	 * administration_id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testNonMemberIsRefusedWithMasked404(): void {
		$this->withAdmin('adm-not-mine');
		$this->canAccess = false;
		$this->service->expects(self::never())->method('realisatieSummary');

		$response = $this->controller->realisatie();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testNonMemberIsRefusedWithMasked404()

	/**
	 * A member of the requested administration still gets the realisatie
	 * summary exactly as before (positive direction, REQ-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testMemberOfRequestedAdministrationSucceeds(): void {
		$this->withAdmin('adm-a');
		$this->canAccess = true;
		$payload = ['data' => [['decisionNumber' => 'WBSO-1', 'exceeded' => false]], 'total' => 1];
		$this->service->expects(self::once())
			->method('realisatieSummary')
			->with('adm-a')
			->willReturn($payload);

		$response = $this->controller->realisatie();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testMemberOfRequestedAdministrationSucceeds()

	/**
	 * A missing administration_id yields HTTP 400.
	 *
	 * @return void
	 */
	public function testMissingAdministrationIsBadRequest(): void {
		$this->withAdmin('');
		$this->service->expects(self::never())->method('realisatieSummary');

		$response = $this->controller->realisatie();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingAdministrationIsBadRequest()

	/**
	 * A malformed administration_id yields HTTP 400 before touching the service.
	 *
	 * @return void
	 */
	public function testMalformedAdministrationIsBadRequest(): void {
		$this->withAdmin('adm a/../../etc');
		$this->service->expects(self::never())->method('realisatieSummary');

		$response = $this->controller->realisatie();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMalformedAdministrationIsBadRequest()

	/**
	 * A service failure yields HTTP 500 and no stack trace in the body (ADR-005).
	 *
	 * @return void
	 */
	public function testServiceFailureIsInternalServerErrorWithoutStackTrace(): void {
		$this->withAdmin('adm-a');
		$this->service->method('realisatieSummary')
			->willThrowException(new \RuntimeException('boom at /var/www/secret.php:42'));

		$response = $this->controller->realisatie();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$body = $response->getData();
		self::assertArrayHasKey('error', $body);
		self::assertStringNotContainsString('secret.php', (string)$body['error']);
		self::assertStringNotContainsString('boom', (string)$body['error']);

	}//end testServiceFailureIsInternalServerErrorWithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
