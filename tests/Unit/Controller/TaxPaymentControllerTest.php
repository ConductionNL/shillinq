<?php

/**
 * Unit tests for TaxPaymentController.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-35
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TaxPaymentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxPaymentReconciliationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the Vpb payment-reconciliation API controller.
 *
 * Covers REQ-VPB-008 (reconcile contract + validation) and the 500 fail path (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxPaymentControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock TaxPaymentReconciliationService.
	 *
	 * @var TaxPaymentReconciliationService&MockObject
	 */
	private TaxPaymentReconciliationService&MockObject $service;

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
	 * What canAccess() answers. Flipped by the ADR-005 refusal test.
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
	 * @var TaxPaymentController
	 */
	private TaxPaymentController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TaxPaymentReconciliationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new TaxPaymentController(
			request: $this->request,
			reconciliation: $this->service,
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
	public function testReconcileMissingAdministrationReturns400(): void {
		$this->withAdmin('');
		$response = $this->controller->reconcile('pay-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testReconcileMissingAdministrationReturns400()

	/**
	 * A malformed payment id yields HTTP 400.
	 *
	 * @return void
	 */
	public function testReconcileMalformedIdReturns400(): void {
		$this->withAdmin('adm-1');
		$response = $this->controller->reconcile('../../etc/passwd');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testReconcileMalformedIdReturns400()

	/**
	 * A valid reconcile returns HTTP 200 with the service payload (REQ-VPB-008).
	 *
	 * @return void
	 */
	public function testReconcileValidReturns200(): void {
		$this->withAdmin('adm-1');
		$payload = [
			'matched' => true,
			'paymentAmount' => 15000.0,
			'glAmount' => 15000.0,
			'variance' => 0.0,
			'glLineCount' => 1,
		];
		$this->service->expects($this->once())
			->method('reconcile')
			->with('adm-1', 'pay-1')
			->willReturn($payload);

		$response = $this->controller->reconcile('pay-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testReconcileValidReturns200()

	/**
	 * A service exception yields HTTP 500 with no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testReconcileServiceFailureReturns500(): void {
		$this->withAdmin('adm-1');
		$this->service->method('reconcile')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->reconcile('pay-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Failed to reconcile payment'], $response->getData());

	}//end testReconcileServiceFailureReturns500()

	/**
	 * A well-formed administration_id the caller has NO membership for yields 404 (ADR-005 / #518).
	 *
	 * reconcile() WRITES against the administration named on the wire, and both
	 * of the old checks were character-class tests. The service must never be
	 * reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vpb-corporate-tax/spec.md
	 */
	public function testForeignAdministrationReturns404AndNeverReachesTheService(): void {
		$this->canAccess = false;
		$this->withAdmin('adm-not-mine');
		$this->service->expects($this->never())->method('reconcile');

		$response = $this->controller->reconcile('tp-001');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testForeignAdministrationReturns404AndNeverReachesTheService()
}//end class
