<?php

/**
 * Unit tests for IcpController.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\IcpController;
use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\ViesService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only ICP API controller.
 *
 * Covers REQ-ICP-003 (ledger contract), REQ-ICP-004 (reconcile contract),
 * REQ-ICP-002 (periodicity contract), parameter validation, and the 500 fail
 * path that leaks no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IcpService.
	 *
	 * @var IcpService&MockObject
	 */
	private IcpService&MockObject $service;

	/**
	 * Mock IcpFilingService.
	 *
	 * @var IcpFilingService&MockObject
	 */
	private IcpFilingService&MockObject $filing;

	/**
	 * Mock ViesService.
	 *
	 * @var ViesService&MockObject
	 */
	private ViesService&MockObject $vies;

	/**
	 * Mock ArInvoiceIcpPdfRenderer.
	 *
	 * @var ArInvoiceIcpPdfRenderer&MockObject
	 */
	private ArInvoiceIcpPdfRenderer&MockObject $pdfRenderer;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

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
	 * Mock ObjectServiceInterface — OpenRegister reads for the ICP invoice PDF.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The controller under test.
	 *
	 * @var IcpController
	 */
	private IcpController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(IcpService::class);
		$this->filing = $this->createMock(IcpFilingService::class);
		$this->vies = $this->createMock(ViesService::class);
		$this->pdfRenderer = $this->createMock(ArInvoiceIcpPdfRenderer::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		// Default to an authenticated session — each test can override.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new IcpController(
			request: $this->request,
			icpService: $this->service,
			filingService: $this->filing,
			viesService: $this->vies,
			pdfRenderer: $this->pdfRenderer,
			userSession: $this->userSession,
			logger: $this->logger,
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,string> $map Param map.
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
	 * A missing period_id yields HTTP 400 on the ledger endpoint (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testLedgerMissingPeriodReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$response = $this->controller->ledger();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLedgerMissingPeriodReturns400()

	/**
	 * A path-traversal period_id is rejected with HTTP 400 (IDOR-safe input).
	 *
	 * @return void
	 */
	public function testLedgerMalformedPeriodReturns400(): void {
		$this->withParams(['period_id' => '../../etc', 'administration_id' => 'adm-1']);
		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLedgerMalformedPeriodReturns400()

	/**
	 * A valid ledger request returns HTTP 200 with the service result (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testLedgerValidReturns200(): void {
		$this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
		$payload = [
			'period' => '2026-Q2',
			'lines' => [['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 25000.0]],
			'total' => 25000.0,
			'totalGoods' => 25000.0,
			'totalServices' => 0.0,
			'totalTriangulation' => 0.0,
			'supplyCount' => 1,
		];
		$this->service->expects($this->once())
			->method('ledger')
			->with('adm-1', '2026-Q2')
			->willReturn($payload);

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testLedgerValidReturns200()

	/**
	 * A valid reconcile request returns HTTP 200 with the outcome (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testReconcileValidReturns200(): void {
		$this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
		$payload = [
			'period' => '2026-Q2',
			'icpTotal' => 25000.0,
			'rubriek3b' => 25000.0,
			'matches' => true,
			'missing' => false,
			'difference' => 0.0,
		];
		$this->service->expects($this->once())
			->method('reconcile')
			->with('adm-1', '2026-Q2')
			->willReturn($payload);

		$response = $this->controller->reconcile();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testReconcileValidReturns200()

	/**
	 * A valid periodicity request returns HTTP 200 with the decision (REQ-ICP-002).
	 *
	 * @return void
	 */
	public function testPeriodicityValidReturns200(): void {
		$this->withParams(['quarter' => '2026-Q1', 'administration_id' => 'adm-1']);
		$payload = ['quarter' => '2026-Q1', 'breached' => true, 'goodsCumulative' => 50100.0];
		$this->service->expects($this->once())
			->method('periodicityCheck')
			->with('adm-1', '2026-Q1')
			->willReturn($payload);

		$response = $this->controller->periodicity();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testPeriodicityValidReturns200()

	/**
	 * A missing quarter yields HTTP 400 on the periodicity endpoint (REQ-ICP-002).
	 *
	 * @return void
	 */
	public function testPeriodicityMissingQuarterReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$response = $this->controller->periodicity();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testPeriodicityMissingQuarterReturns400()

	/**
	 * A service exception yields HTTP 500 with no stack trace leaked (ADR-005).
	 *
	 * @return void
	 */
	public function testServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
		$this->service->method('ledger')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
		self::assertStringNotContainsStringIgnoringCase('boom', (string)json_encode($response->getData()));

	}//end testServiceFailureReturns500WithoutStackTrace()

	/**
	 * The lookupVatId endpoint delegates to ViesService and returns the outcome (REQ-ICP-001).
	 *
	 * @return void
	 */
	public function testLookupVatIdReturns200(): void {
		$this->withParams(['vat_id' => 'BE0123456789', 'administration_id' => 'adm-1']);
		$this->vies->method('validate')->willReturn(
			[
				'vatId' => 'BE0123456789',
				'valid' => true,
				'outage' => false,
				'requestId' => 'X',
				'validationTimestamp' => 't',
				'validUntil' => 't',
				'name' => '',
				'address' => '',
				'saved' => true,
				'reusedPrior' => false,
			]
		);

		$response = $this->controller->lookupVatId();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['valid']);

	}//end testLookupVatIdReturns200()

	/**
	 * The lookupVatId endpoint rejects a malformed VAT-ID with 400 (input validation, ADR-005).
	 *
	 * @return void
	 */
	public function testLookupVatIdRejectsBadInput(): void {
		$this->withParams(['vat_id' => 'BE@@@inject;', 'administration_id' => 'adm-1']);

		$response = $this->controller->lookupVatId();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLookupVatIdRejectsBadInput()

	/**
	 * The correction endpoint delegates to IcpService::createCorrection (REQ-ICP-008).
	 *
	 * @return void
	 */
	public function testCorrectionReturns200(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'administration_id' => 'adm-1',
					'corrects_period' => '2026-Q1',
					'reason' => 'late supply',
					'lines' => [['buyerVatId' => 'BE0123456789', 'supplyType' => 'S', 'amountExclVat' => 1200.0]],
					default => $default,
				};
			}
		);
		$this->filing->method('createCorrection')->willReturn(
			[
				'administrationId' => 'adm-1',
				'type' => 'correction',
				'status' => 'draft',
				'correctsPeriod' => '2026-Q1',
				'correctionReason' => 'late supply',
				'lines' => [],
				'total' => 1200.0,
				'totalGoods' => 0.0,
				'totalServices' => 1200.0,
				'totalTriangulation' => 0.0,
				'evidence' => [],
				'saved' => true,
			]
		);

		$response = $this->controller->correction();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('correction', $response->getData()['type']);

	}//end testCorrectionReturns200()

	/**
	 * The correction endpoint rejects an empty lines array with 400.
	 *
	 * @return void
	 */
	public function testCorrectionRejectsEmptyLines(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'administration_id' => 'adm-1',
					'corrects_period' => '2026-Q1',
					'lines' => [],
					default => $default,
				};
			}
		);

		$response = $this->controller->correction();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCorrectionRejectsEmptyLines()

	/**
	 * The auditExport endpoint returns bundle metadata without leaking the server temp path (REQ-ICP-010).
	 *
	 * @return void
	 */
	public function testAuditExportReturns200WithoutPath(): void {
		$this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
		$this->filing->method('exportForInspection')->willReturn(
			['period' => '2026-Q2', 'zipPath' => '/tmp/secret_path.zip', 'supplyCount' => 3, 'manifest' => ['supplies.csv'], 'reference' => 'BD-1']
		);

		$response = $this->controller->auditExport();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayNotHasKey('zipPath', $data);
		self::assertSame(3, $data['supplyCount']);

	}//end testAuditExportReturns200WithoutPath()

	/**
	 * Wire the ObjectService mock to serve rows per schema slug, mirroring the
	 * fluent setRegister()->setSchema()->findAll() chain the controller uses.
	 *
	 * @param array<string, list<array<string,mixed>>> $rowsBySchema Rows per schema.
	 *
	 * @return void
	 */
	private function seedObjects(array $rowsBySchema): void {
		$schema = '';
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema): ObjectServiceInterface {
				$schema = (string)$slug;
				return $this->objectService;
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			static function () use (&$schema, $rowsBySchema): array {
				return ($rowsBySchema[$schema] ?? []);
			}
		);

	}//end seedObjects()

	/**
	 * Build a second controller bound to an anonymous session.
	 *
	 * @return IcpController The controller with no signed-in user.
	 */
	private function anonymousController(): IcpController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new IcpController(
			request: $this->request,
			icpService: $this->service,
			filingService: $this->filing,
			viesService: $this->vies,
			pdfRenderer: $this->pdfRenderer,
			userSession: $session,
			logger: $this->logger,
			objectService: $this->objectService,
		);

	}//end anonymousController()

	/**
	 * renderInvoicePdf is fail-closed: an anonymous session gets HTTP 401 and
	 * no invoice is ever read (REQ-ICP-001 / ADR-005).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfRequiresAuthentication(): void {
		$this->pdfRenderer->expects($this->never())->method('render');

		$response = $this->anonymousController()->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRenderInvoicePdfRequiresAuthentication()

	/**
	 * A missing invoice_id yields HTTP 400 before any read (REQ-ICP-007).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfMissingInvoiceReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$this->pdfRenderer->expects($this->never())->method('render');

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('invoice_id', (string)$response->getData()['error']);

	}//end testRenderInvoicePdfMissingInvoiceReturns400()

	/**
	 * A path-traversal administration_id is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfMalformedAdministrationReturns400(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => '../../etc']);
		$this->pdfRenderer->expects($this->never())->method('render');

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('administration_id', (string)$response->getData()['error']);

	}//end testRenderInvoicePdfMalformedAdministrationReturns400()

	/**
	 * An invoice that is not in the administration's own set yields HTTP 404 —
	 * the lookup never escapes the administration filter (IDOR-safe).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfUnknownInvoiceReturns404(): void {
		$this->withParams(['invoice_id' => 'AR-2026-9999', 'administration_id' => 'adm-1']);
		$this->seedObjects(
			[
				'ARInvoice' => [['id' => 'AR-2026-0001', 'administrationId' => 'adm-1']],
			]
		);
		$this->pdfRenderer->expects($this->never())->method('render');

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRenderInvoicePdfUnknownInvoiceReturns404()

	/**
	 * A valid request resolves invoice + customer + seller, hands all three to
	 * the renderer and serves the result inline with the ICP marker header
	 * (REQ-ICP-007).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfReturnsInlineDocument(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => 'adm-1']);
		$this->seedObjects(
			[
				'ARInvoice' => [
					['id' => 'AR-2026-0002', 'customerId' => 'C-2', 'administrationId' => 'adm-1'],
					['id' => 'AR-2026-0001', 'customerId' => 'C-1', 'invoiceNumber' => 'AR-2026-0001', 'administrationId' => 'adm-1'],
				],
				'CustomerMaster' => [
					['id' => 'C-9', 'name' => 'Other B.V.'],
					['id' => 'C-1', 'name' => 'Acme SPRL', 'vatId' => 'BE0123456789'],
				],
				'Administration' => [
					['id' => 'adm-2', 'legalName' => 'Wrong Operator'],
					['id' => 'adm-1', 'legalName' => 'Shillinq Operator B.V.', 'vatId' => 'NL001234567B01'],
				],
			]
		);

		$seen = [];
		$this->pdfRenderer->expects($this->once())->method('render')->willReturnCallback(
			static function (array $invoice, array $customer, array $seller) use (&$seen): array {
				$seen = ['invoice' => $invoice, 'customer' => $customer, 'seller' => $seller];
				return [
					'filename' => 'arinvoice-AR-2026-0001.pdf',
					'html' => '<html><body>ICP</body></html>',
					'mimeType' => 'application/pdf',
				];
			}
		);

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('<html><body>ICP</body></html>', $response->render());

		$headers = $response->getHeaders();
		self::assertSame('true', $headers['X-Shillinq-Icp']);
		self::assertSame('text/html; charset=utf-8', $headers['Content-Type']);

		/*
		 * Observed, not desired: DataDisplayResponse::__construct() calls
		 * addHeader('Content-Disposition', 'inline; filename=""') AFTER applying
		 * the headers passed to it, so the filename the controller computes is
		 * always discarded by the framework. Asserted as-is so the day someone
		 * fixes it (e.g. by switching to DownloadResponse) this test says so
		 * rather than silently passing on a header nobody reads.
		 */
		self::assertSame('inline; filename=""', $headers['Content-Disposition']);

		self::assertSame('AR-2026-0001', $seen['invoice']['id']);
		self::assertSame('Acme SPRL', $seen['customer']['name']);
		self::assertSame('Shillinq Operator B.V.', $seen['seller']['legalName']);

	}//end testRenderInvoicePdfReturnsInlineDocument()

	/**
	 * A missing buyer VAT-ID on an ICP supply is a correctable data defect, not
	 * a server error: HTTP 422 carrying the machine-readable error code
	 * (REQ-ICP-007).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfMissingBuyerVatIdReturns422(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => 'adm-1']);
		$this->seedObjects(
			[
				'ARInvoice' => [['id' => 'AR-2026-0001', 'administrationId' => 'adm-1']],
			]
		);
		$this->pdfRenderer->method('render')->willThrowException(
			new \InvalidArgumentException(
				ArInvoiceIcpPdfRenderer::ERROR_VATID_MISSING . ': Cannot render ICP invoice without buyer VAT-ID'
			)
		);

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(ArInvoiceIcpPdfRenderer::ERROR_VATID_MISSING, $response->getData()['error']);

	}//end testRenderInvoicePdfMissingBuyerVatIdReturns422()

	/**
	 * Any other InvalidArgumentException is still a 422, but without claiming
	 * the VAT-ID error code.
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfOtherValidationFailureReturns422(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => 'adm-1']);
		$this->seedObjects(
			[
				'ARInvoice' => [['id' => 'AR-2026-0001', 'administrationId' => 'adm-1']],
			]
		);
		$this->pdfRenderer->method('render')->willThrowException(
			new \InvalidArgumentException('supplyType is not a recognised ICP supply type')
		);

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Render failed', $response->getData()['error']);

	}//end testRenderInvoicePdfOtherValidationFailureReturns422()

	/**
	 * A renderer crash yields HTTP 500, is logged, and leaks no stack trace
	 * (ADR-005).
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfRendererCrashReturns500WithoutLeak(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => 'adm-1']);
		$this->seedObjects(
			[
				'ARInvoice' => [['id' => 'AR-2026-0001', 'administrationId' => 'adm-1']],
			]
		);
		$this->pdfRenderer->method('render')->willThrowException(new \RuntimeException('wkhtmltopdf segfault'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('segfault', (string)json_encode($response->getData()));

	}//end testRenderInvoicePdfRendererCrashReturns500WithoutLeak()

	/**
	 * A failure while loading the invoice context yields HTTP 500 with a
	 * generic message and never reaches the renderer.
	 *
	 * @return void
	 */
	public function testRenderInvoicePdfContextLoadFailureReturns500(): void {
		$this->withParams(['invoice_id' => 'AR-2026-0001', 'administration_id' => 'adm-1']);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willThrowException(
			new \RuntimeException('SQLSTATE[42S02] oc_openregister_objects missing')
		);
		$this->pdfRenderer->expects($this->never())->method('render');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->renderInvoicePdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Failed to load invoice context', $response->getData()['error']);
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));

	}//end testRenderInvoicePdfContextLoadFailureReturns500()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
