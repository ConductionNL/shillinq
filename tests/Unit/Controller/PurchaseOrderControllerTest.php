<?php

/**
 * Unit tests for PurchaseOrderController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PurchaseOrderController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the approval-chain preview and the two transmission endpoints
 * (Peppol BIS Ordering 3.0 and the PDF + email fallback).
 *
 * Asserts the anonymous 401 guard, the path-parameter validation, the
 * cross-tenant 404 mask (ADR-005), the "not found" → 404 versus
 * approval-incomplete → 409 split of the send-block (REQ-PO3W-001) and the
 * 500 path that leaks no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock PurchaseOrderService.
	 *
	 * @var PurchaseOrderService&MockObject
	 */
	private PurchaseOrderService&MockObject $purchaseOrderService;

	/**
	 * Mock AdministrationContextService.
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
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Set up shared fixtures — authenticated with an accessible
	 * administration by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->purchaseOrderService = $this->createMock(PurchaseOrderService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => $text);

		$this->administrationContext->method('canAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Configure the request params from a key => value map.
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
	 * Drop the authenticated session so the guards see an anonymous caller.
	 *
	 * @return void
	 */
	private function anonymous(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);

	}//end anonymous()

	/**
	 * Build the controller over the current mocks.
	 *
	 * @return PurchaseOrderController
	 */
	private function controller(): PurchaseOrderController {
		return new PurchaseOrderController(
			$this->request,
			$this->purchaseOrderService,
			$this->administrationContext,
			$this->userSession,
			$this->logger,
			$this->l10n,
		);

	}//end controller()

	/**
	 * create() persists the purchase order and returns it with HTTP 201, for
	 * the legitimate caller (REQ-001 positive direction).
	 *
	 * @return void
	 */
	public function testCreateReturns201WithPersistedOrder(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'supplierId' => 'sup-1',
				'costCenter' => 'cc-1',
				'lines' => [['productCode' => 'p1', 'quantity' => 2, 'unitPrice' => 10.0, 'vatRate' => 21, 'glAccount' => 'gl-1']],
			]
		);
		$po = ['id' => 'po-1', 'administrationId' => 'adm-1', 'status' => 'draft'];
		$this->purchaseOrderService->expects($this->once())
			->method('createPurchaseOrder')
			->willReturnCallback(
				static function (string $administrationId, array $payload) use ($po): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('sup-1', $payload['supplierId']);
					return $po;
				}
			);

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame($po, $response->getData());

	}//end testCreateReturns201WithPersistedOrder()

	/**
	 * create() rejects an anonymous caller with HTTP 401 (REQ-001 negative
	 * direction — no session at all).
	 *
	 * @return void
	 */
	public function testCreateAnonymousReturns401(): void {
		$this->anonymous();
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('createPurchaseOrder');

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCreateAnonymousReturns401()

	/**
	 * create() masks a cross-tenant administrationId as HTTP 404 rather than
	 * creating the order (REQ-001 negative direction — authenticated but not a
	 * member of the named administration).
	 *
	 * @return void
	 */
	public function testCreateCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other', 'supplierId' => 'sup-1', 'costCenter' => 'cc-1']);
		$this->purchaseOrderService->expects($this->never())->method('createPurchaseOrder');

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCreateCrossTenantReturns404()

	/**
	 * A validation failure from the service (RuntimeException) answers a
	 * static localized message and a kebab-case slug — never the raw
	 * exception text (REQ-003 / ADR-050).
	 *
	 * @return void
	 */
	public function testCreateValidationFailureReturns400WithoutLeakingException(): void {
		$this->withParams(['administrationId' => 'adm-1', 'supplierId' => '', 'costCenter' => 'cc-1']);
		$this->purchaseOrderService->method('createPurchaseOrder')
			->willThrowException(new \RuntimeException('supplierId is required'));

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('purchase-order-create-invalid', $response->getData()['error']);
		self::assertArrayHasKey('message', $response->getData());
		self::assertStringNotContainsString('supplierId is required', (string)json_encode($response->getData()));

	}//end testCreateValidationFailureReturns400WithoutLeakingException()

	/**
	 * An unexpected failure yields HTTP 500 with a static slug and leaks no
	 * exception text (REQ-003 / ADR-050).
	 *
	 * @return void
	 */
	public function testCreateUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'supplierId' => 'sup-1', 'costCenter' => 'cc-1']);
		$this->purchaseOrderService->method('createPurchaseOrder')
			->willThrowException(new \LogicException('SQLSTATE[08006] connection refused'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('purchase-order-create-failed', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testCreateUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * send() advances the order and returns it with HTTP 200, for the
	 * legitimate caller (REQ-001 positive direction).
	 *
	 * @return void
	 */
	public function testSendReturns200WithUpdatedOrder(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$po = ['id' => 'po-1', 'status' => 'sent'];
		$this->purchaseOrderService->expects($this->once())
			->method('blockSendUntilApproved')
			->willReturnCallback(
				static function (string $administrationId, string $purchaseOrderId) use ($po): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('po-1', $purchaseOrderId);
					return $po;
				}
			);

		$response = $this->controller()->send('po-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($po, $response->getData());

	}//end testSendReturns200WithUpdatedOrder()

	/**
	 * send() masks a cross-tenant administrationId as HTTP 404 rather than
	 * advancing the order (REQ-001 negative direction).
	 *
	 * @return void
	 */
	public function testSendCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other']);
		$this->purchaseOrderService->expects($this->never())->method('blockSendUntilApproved');

		$response = $this->controller()->send('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSendCrossTenantReturns404()

	/**
	 * send() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testSendAnonymousReturns401(): void {
		$this->anonymous();
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('blockSendUntilApproved');

		$response = $this->controller()->send('po-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSendAnonymousReturns401()

	/**
	 * previewApprovalChain() returns the server-determined chain for the
	 * submitted amount with HTTP 200.
	 *
	 * @return void
	 */
	public function testPreviewApprovalChainReturns200WithChain(): void {
		$this->withParams(['amount' => '18500']);
		$chain = [
			['role' => 'budget_holder', 'threshold' => 5000.0],
			['role' => 'finance_manager', 'threshold' => 25000.0],
		];
		$this->purchaseOrderService->expects($this->once())
			->method('determineApprovalChain')
			->willReturnCallback(
				static function (float $amount) use ($chain): array {
					self::assertSame(18500.0, $amount);
					return $chain;
				}
			);

		$response = $this->controller()->previewApprovalChain();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['chain' => $chain], $response->getData());

	}//end testPreviewApprovalChainReturns200WithChain()

	/**
	 * A negative amount is clamped to 0.0 server-side rather than reaching the
	 * service as a negative threshold input.
	 *
	 * @return void
	 */
	public function testPreviewApprovalChainClampsNegativeAmount(): void {
		$this->withParams(['amount' => '-9999']);
		$this->purchaseOrderService->expects($this->once())
			->method('determineApprovalChain')
			->willReturnCallback(
				static function (float $amount): array {
					self::assertSame(0.0, $amount);
					return [];
				}
			);

		$response = $this->controller()->previewApprovalChain();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['chain' => []], $response->getData());

	}//end testPreviewApprovalChainClampsNegativeAmount()

	/**
	 * previewApprovalChain() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testPreviewApprovalChainAnonymousReturns401(): void {
		$this->anonymous();
		$this->withParams(['amount' => '100']);
		$this->purchaseOrderService->expects($this->never())->method('determineApprovalChain');

		$response = $this->controller()->previewApprovalChain();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testPreviewApprovalChainAnonymousReturns401()

	/**
	 * transmitPeppol() returns the updated PO carrying the Peppol message id
	 * with HTTP 200 (REQ-PO3W-002).
	 *
	 * @return void
	 */
	public function testTransmitPeppolReturns200WithUpdatedOrder(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$po = [
			'id' => 'po-1',
			'status' => 'sent',
			'peppolMessageId' => 'urn:msg:42',
			'peppolSentAt' => '2026-08-16T10:00:00+02:00',
		];
		$this->purchaseOrderService->expects($this->once())
			->method('sendToPeppol')
			->willReturnCallback(
				static function (string $administrationId, string $purchaseOrderId) use ($po): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('po-1', $purchaseOrderId);
					return $po;
				}
			);

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($po, $response->getData());

	}//end testTransmitPeppolReturns200WithUpdatedOrder()

	/**
	 * A malformed PO id in the path is rejected with HTTP 400 before the
	 * tenant check runs.
	 *
	 * @return void
	 */
	public function testTransmitPeppolMalformedIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPeppol');

		$response = $this->controller()->transmitPeppol('../../etc/passwd');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Invalid purchase order id', $response->getData()['error']);

	}//end testTransmitPeppolMalformedIdReturns400()

	/**
	 * A missing administrationId is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testTransmitPeppolMissingAdministrationReturns400(): void {
		$this->withParams([]);
		$this->purchaseOrderService->expects($this->never())->method('sendToPeppol');

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('administrationId is required', $response->getData()['error']);

	}//end testTransmitPeppolMissingAdministrationReturns400()

	/**
	 * A cross-tenant administration is masked as HTTP 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testTransmitPeppolCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPeppol');

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTransmitPeppolCrossTenantReturns404()

	/**
	 * An incomplete approval chain blocks the send with HTTP 409, not 200
	 * (REQ-PO3W-001 send-block).
	 *
	 * @return void
	 */
	public function testTransmitPeppolIncompleteApprovalReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->method('sendToPeppol')
			->willThrowException(new \RuntimeException('Approval chain is not complete'));

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Approval chain is not complete', $response->getData()['error']);

	}//end testTransmitPeppolIncompleteApprovalReturns409()

	/**
	 * A missing purchase order answers HTTP 404 rather than 409.
	 *
	 * @return void
	 */
	public function testTransmitPeppolMissingOrderReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->method('sendToPeppol')
			->willThrowException(new \RuntimeException('Purchase order not found'));

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTransmitPeppolMissingOrderReturns404()

	/**
	 * An unexpected transport failure yields HTTP 500 and leaks no stack
	 * trace.
	 *
	 * @return void
	 */
	public function testTransmitPeppolUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->method('sendToPeppol')
			->willThrowException(new \LogicException('access point token e30.secret'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'secret',
			(string)json_encode($response->getData())
		);

	}//end testTransmitPeppolUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * transmitPeppol() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testTransmitPeppolAnonymousReturns401(): void {
		$this->anonymous();
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPeppol');

		$response = $this->controller()->transmitPeppol('po-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTransmitPeppolAnonymousReturns401()

	/**
	 * transmitEmail() forwards the operator's audit reason and returns the
	 * updated PO with HTTP 200 (REQ-PO3W-002 D2).
	 *
	 * @return void
	 */
	public function testTransmitEmailForwardsFallbackReason(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'fallbackReason' => '  supplier not on Peppol  ',
			]
		);
		$po = ['id' => 'po-1', 'status' => 'sent', 'peppolFallbackReason' => 'supplier not on Peppol'];
		$this->purchaseOrderService->expects($this->once())
			->method('sendToPDFEmail')
			->willReturnCallback(
				static function (string $administrationId, string $purchaseOrderId, string $fallbackReason) use ($po): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('po-1', $purchaseOrderId);
					self::assertSame('supplier not on Peppol', $fallbackReason);
					return $po;
				}
			);

		$response = $this->controller()->transmitEmail('po-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($po, $response->getData());

	}//end testTransmitEmailForwardsFallbackReason()

	/**
	 * transmitEmail() enforces the same send-block as the Peppol path — an
	 * unsigned chain is HTTP 409.
	 *
	 * @return void
	 */
	public function testTransmitEmailIncompleteApprovalReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->method('sendToPDFEmail')
			->willThrowException(new \RuntimeException('Approval chain is not complete'));

		$response = $this->controller()->transmitEmail('po-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testTransmitEmailIncompleteApprovalReturns409()

	/**
	 * transmitEmail() masks a cross-tenant administration as HTTP 404.
	 *
	 * @return void
	 */
	public function testTransmitEmailCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPDFEmail');

		$response = $this->controller()->transmitEmail('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTransmitEmailCrossTenantReturns404()

	/**
	 * transmitEmail() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testTransmitEmailAnonymousReturns401(): void {
		$this->anonymous();
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPDFEmail');

		$response = $this->controller()->transmitEmail('po-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTransmitEmailAnonymousReturns401()

	/**
	 * transmitEmail() rejects a malformed PO id in the path with HTTP 400.
	 *
	 * @return void
	 */
	public function testTransmitEmailMalformedIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->purchaseOrderService->expects($this->never())->method('sendToPDFEmail');

		$response = $this->controller()->transmitEmail('po 1; DROP TABLE');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testTransmitEmailMalformedIdReturns400()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
