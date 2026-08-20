<?php

/**
 * Unit tests for TaxReportController.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-36
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TaxReportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxReportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the Vpb tax-report API controller.
 *
 * Covers REQ-VPB-009 (endpoint contract), REQ-VPB-003 (administration validation),
 * REQ-VPB-012 (annual endpoint) and the 500 fail path (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxReportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock TaxReportService.
	 *
	 * @var TaxReportService&MockObject
	 */
	private TaxReportService&MockObject $service;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

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
	private AdministrationContextService&MockObject $context;

	/**
	 * What canAccess() answers. Flipped by the ADR-005 refusal tests.
	 *
	 * Read through a callback rather than re-stubbed per test: a second
	 * `->method('canAccess')` APPENDS a matcher instead of replacing the first.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var TaxReportController
	 */
	private TaxReportController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TaxReportService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new TaxReportController(
			request: $this->request,
			taxReportService: $this->service,
			userSession: $this->userSession,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Set the administration_id request param.
	 *
	 * @param string $admin The administration id.
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
	 * A missing administration_id yields HTTP 400 (REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testQuarterMissingAdministrationReturns400(): void {
		$this->withAdmin('');
		$response = $this->controller->quarter('2025', '1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testQuarterMissingAdministrationReturns400()

	/**
	 * An out-of-range quarter yields HTTP 400.
	 *
	 * @return void
	 */
	public function testQuarterInvalidQuarterReturns400(): void {
		$this->withAdmin('adm-1');
		$response = $this->controller->quarter('2025', '5');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testQuarterInvalidQuarterReturns400()

	/**
	 * An out-of-range year yields HTTP 400.
	 *
	 * @return void
	 */
	public function testQuarterInvalidYearReturns400(): void {
		$this->withAdmin('adm-1');
		$response = $this->controller->quarter('1800', '1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testQuarterInvalidYearReturns400()

	/**
	 * A valid quarterly request returns HTTP 200 with the service payload (REQ-VPB-009).
	 *
	 * @return void
	 */
	public function testQuarterValidReturns200(): void {
		$this->withAdmin('adm-1');
		$payload = [
			'administrationId' => 'adm-1',
			'fiscalYear' => 2025,
			'quarter' => 1,
			'netTaxableIncome' => 40000.0,
		];
		$this->service->expects($this->once())
			->method('computeQuarter')
			->with('adm-1', 2025, 1)
			->willReturn($payload);

		$response = $this->controller->quarter('2025', '1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testQuarterValidReturns200()

	/**
	 * A service exception yields HTTP 500 with no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testQuarterServiceFailureReturns500(): void {
		$this->withAdmin('adm-1');
		$this->service->method('computeQuarter')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->quarter('2025', '1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Failed to compute tax statement'], $response->getData());

	}//end testQuarterServiceFailureReturns500()

	/**
	 * A valid annual request returns HTTP 200 (REQ-VPB-012).
	 *
	 * @return void
	 */
	public function testAnnualValidReturns200(): void {
		$this->withAdmin('adm-1');
		$payload = ['administrationId' => 'adm-1', 'fiscalYear' => 2025, 'estimatedLiability' => 38000.0];
		$this->service->expects($this->once())
			->method('computeAnnual')
			->with('adm-1', 2025)
			->willReturn($payload);

		$response = $this->controller->annual('2025');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testAnnualValidReturns200()

	/**
	 * quarter(): a foreign administration_id yields 404 and never reaches the service (ADR-005 / #518).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vpb-corporate-tax/spec.md
	 */
	public function testQuarterForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withAdmin('adm-not-mine');
		$this->service->expects($this->never())->method('computeQuarter');

		$response = $this->controller->quarter('2026', '1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testQuarterForeignAdministrationReturns404()

	/**
	 * annual(): a foreign administration_id yields 404 and never reaches the service (ADR-005 / #518).
	 *
	 * Both endpoints route through the same helper, which used to be named
	 * `validateAdministration()` and was entirely a character-class regex.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vpb-corporate-tax/spec.md
	 */
	public function testAnnualForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withAdmin('adm-not-mine');
		$this->service->expects($this->never())->method('computeAnnual');

		$response = $this->controller->annual('2026');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnnualForeignAdministrationReturns404()
}//end class
