<?php

/**
 * Unit tests for ServiceReceiptController.
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
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ServiceReceiptController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ServiceReceiptService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the service-receipt (prestatieverklaring) accept + add-line endpoints.
 *
 * Both are 3-way-match writes: the id slug is validated before any lookup, the
 * administration scope is checked against the caller's memberships and masked
 * as 404 when foreign (ADR-005), a lifecycle refusal maps to 409, a validation
 * refusal to 400, and an unexpected failure to a generic 500 without a stack
 * trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ServiceReceiptControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ServiceReceiptService (server-authoritative writes).
	 *
	 * @var ServiceReceiptService&MockObject
	 */
	private ServiceReceiptService&MockObject $serviceReceiptService;

	/**
	 * Mock AdministrationContextService (IDOR + tenant scope).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

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
	 * The user the session reports; null models an anonymous caller.
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * The controller under test.
	 *
	 * @var ServiceReceiptController
	 */
	private ServiceReceiptController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->serviceReceiptService = $this->createMock(ServiceReceiptService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->currentUser = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->currentUser;
			}
		);

		$this->controller = new ServiceReceiptController(
			request: $this->request,
			serviceReceiptService: $this->serviceReceiptService,
			administrationContext: $this->administrationContext,
			userSession: $this->userSession,
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
	 * Accepting a confirmed receipt returns 200 with the updated record, scoped
	 * to the administration from the request body.
	 *
	 * @return void
	 */
	public function testAcceptReturns200WithUpdatedReceipt(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->serviceReceiptService->expects($this->once())
			->method('acceptServiceReceipt')
			->willReturnCallback(
				static function (string $administrationId, string $receiptId) use (&$seen): array {
					$seen = [$administrationId, $receiptId];
					return ['id' => $receiptId, 'statusCode' => 'accepted'];
				}
			);

		$response = $this->controller->accept('svc-9');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'svc-9'], $seen);
		self::assertSame('accepted', $response->getData()['statusCode']);

	}//end testAcceptReturns200WithUpdatedReceipt()

	/**
	 * An anonymous accept is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testAcceptRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->serviceReceiptService->expects($this->never())->method('acceptServiceReceipt');

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testAcceptRejectsAnonymousCaller()

	/**
	 * A malformed receipt id is rejected with 400 before any lookup.
	 *
	 * @return void
	 */
	public function testAcceptRejectsMalformedIdWith400(): void {
		$this->serviceReceiptService->expects($this->never())->method('acceptServiceReceipt');

		$response = $this->controller->accept('../../secrets');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Invalid service receipt id'], $response->getData());

	}//end testAcceptRejectsMalformedIdWith400()

	/**
	 * A missing administrationId is a 400 — the write is never unscoped.
	 *
	 * @return void
	 */
	public function testAcceptRequiresAdministrationId(): void {
		$this->withParams([]);
		$this->serviceReceiptService->expects($this->never())->method('acceptServiceReceipt');

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testAcceptRequiresAdministrationId()

	/**
	 * Accepting inside another tenant's administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testAcceptForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->serviceReceiptService->expects($this->never())->method('acceptServiceReceipt');

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Service receipt not found'], $response->getData());

	}//end testAcceptForeignAdministrationReturns404()

	/**
	 * A lifecycle refusal ("requires statusCode ...") maps to 409 Conflict.
	 *
	 * @return void
	 */
	public function testAcceptLifecycleRefusalReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->serviceReceiptService->method('acceptServiceReceipt')
			->willThrowException(new \RuntimeException('accept requires statusCode confirmed'));

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => 'accept requires statusCode confirmed'], $response->getData());

	}//end testAcceptLifecycleRefusalReturns409()

	/**
	 * An unknown receipt reported by the service maps to 404.
	 *
	 * @return void
	 */
	public function testAcceptUnknownReceiptReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->serviceReceiptService->method('acceptServiceReceipt')
			->willThrowException(new \RuntimeException('SvcReceipt not found'));

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAcceptUnknownReceiptReturns404()

	/**
	 * An unexpected failure is logged and returns a generic 500 with no trace.
	 *
	 * @return void
	 */
	public function testAcceptUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->serviceReceiptService->method('acceptServiceReceipt')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_svc_receipt missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->accept('svc-9');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not accept service receipt'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testAcceptUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * Appending a line returns 201 with the persisted line and forwards the whole
	 * measurement payload (percentage / quantity / amount) to the service.
	 *
	 * @return void
	 */
	public function testAddLineReturns201WithPersistedLine(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'poLineId' => 'po-line-3',
				'percentageComplete' => 40,
				'periodStart' => '2026-07-01',
				'periodEnd' => '2026-07-31',
				'notes' => 'milestone 2 delivered',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->serviceReceiptService->expects($this->once())
			->method('addServiceReceiptLine')
			->willReturnCallback(
				static function (string $administrationId, string $receiptId, array $payload) use (&$seen): array {
					$seen = [$administrationId, $receiptId, $payload];
					return [
						'id' => 'svc-line-1',
						'receiptId' => $receiptId,
						'poLineId' => $payload['poLineId'],
						'percentageComplete' => $payload['percentageComplete'],
					];
				}
			);

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('adm-1', $seen[0]);
		self::assertSame('svc-9', $seen[1]);
		self::assertSame('po-line-3', $seen[2]['poLineId']);
		self::assertSame(40, $seen[2]['percentageComplete']);
		self::assertNull($seen[2]['quantityConfirmed']);
		self::assertSame('svc-line-1', $response->getData()['id']);

	}//end testAddLineReturns201WithPersistedLine()

	/**
	 * An anonymous add-line is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testAddLineRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->serviceReceiptService->expects($this->never())->method('addServiceReceiptLine');

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAddLineRejectsAnonymousCaller()

	/**
	 * A missing administrationId is a 400 on the add-line endpoint too.
	 *
	 * @return void
	 */
	public function testAddLineRequiresAdministrationId(): void {
		$this->withParams(['poLineId' => 'po-line-3']);
		$this->serviceReceiptService->expects($this->never())->method('addServiceReceiptLine');

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testAddLineRequiresAdministrationId()

	/**
	 * Adding a line inside another tenant's administration is masked as 404.
	 *
	 * @return void
	 */
	public function testAddLineForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'poLineId' => 'po-line-3']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->serviceReceiptService->expects($this->never())->method('addServiceReceiptLine');

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Service receipt not found'], $response->getData());

	}//end testAddLineForeignAdministrationReturns404()

	/**
	 * A service-level validation refusal (no measurement supplied) maps to 400.
	 *
	 * @return void
	 */
	public function testAddLineValidationRefusalReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'poLineId' => 'po-line-3']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->serviceReceiptService->method('addServiceReceiptLine')
			->willThrowException(
				new \RuntimeException('one of percentageComplete, quantityConfirmed or amountConfirmedCents is required')
			);

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('percentageComplete', (string)$response->getData()['error']);

	}//end testAddLineValidationRefusalReturns400()

	/**
	 * An unknown PO line reported by the service maps to 404.
	 *
	 * @return void
	 */
	public function testAddLineUnknownPoLineReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'poLineId' => 'po-line-nope']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->serviceReceiptService->method('addServiceReceiptLine')
			->willThrowException(new \RuntimeException('PurchaseOrderLine not found'));

		$response = $this->controller->addLine('svc-9');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAddLineUnknownPoLineReturns404()

	/**
	 * A malformed receipt id is rejected with 400 on the add-line endpoint.
	 *
	 * @return void
	 */
	public function testAddLineRejectsMalformedIdWith400(): void {
		$this->serviceReceiptService->expects($this->never())->method('addServiceReceiptLine');

		$response = $this->controller->addLine('svc 9; DROP TABLE');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Invalid service receipt id'], $response->getData());

	}//end testAddLineRejectsMalformedIdWith400()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
