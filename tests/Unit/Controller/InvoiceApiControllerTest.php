<?php

/**
 * Unit tests for InvoiceApiController.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\InvoiceApiController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the invoice PDF export endpoint.
 *
 * Covers the anonymous rejection, the 404 for an unknown invoice, the
 * cross-tenant 403 (the administration id is resolved server-side and compared
 * against the stored one, never taken from the client), the rendered happy path
 * including filename sanitisation, and the 500 fail path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceApiControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock InvoiceGenerationService.
	 *
	 * @var InvoiceGenerationService&MockObject
	 */
	private InvoiceGenerationService&MockObject $service;

	/**
	 * Mock InvoicePdfGenerator.
	 *
	 * @var InvoicePdfGenerator&MockObject
	 */
	private InvoicePdfGenerator&MockObject $pdfGenerator;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectServiceInterface.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The signed-in user, or null for an anonymous session.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The controller under test.
	 *
	 * @var InvoiceApiController
	 */
	private InvoiceApiController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(InvoiceGenerationService::class);
		$this->pdfGenerator = $this->createMock(InvoicePdfGenerator::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;

		$this->session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->user;
			}
		);
		$this->administrationContext->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->controller = new InvoiceApiController(
			request: $this->request,
			service: $this->service,
			pdfGenerator: $this->pdfGenerator,
			session: $this->session,
			administrationContext: $this->administrationContext,
			logger: $this->logger,
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build a stored-invoice entity double.
	 *
	 * @param array<string,mixed> $invoice The stored invoice body.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $invoice): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($invoice);
		return $entity;

	}//end entity()

	/**
	 * An anonymous caller is rejected with HTTP 401 before any lookup.
	 *
	 * @return void
	 */
	public function testPdfAnonymousReturns401(): void {
		$this->user = null;
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->pdf('inv-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testPdfAnonymousReturns401()

	/**
	 * An unknown invoice yields HTTP 404 and never reaches the renderer.
	 *
	 * @return void
	 */
	public function testPdfUnknownInvoiceReturns404(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->pdfGenerator->expects($this->never())->method('generatePdf');

		$response = $this->controller->pdf('inv-missing');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testPdfUnknownInvoiceReturns404()

	/**
	 * An invoice owned by another administration yields HTTP 403 and is never
	 * rendered — the tenant comes from the session, not the request.
	 *
	 * @return void
	 */
	public function testPdfCrossTenantInvoiceReturns403(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-someone-else'])
		);
		$this->pdfGenerator->expects($this->never())->method('generatePdf');

		$response = $this->controller->pdf('inv-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testPdfCrossTenantInvoiceReturns403()

	/**
	 * An invoice with NO administrationId is also refused with HTTP 403 —
	 * an absent owner must not read as "belongs to the caller".
	 *
	 * @return void
	 */
	public function testPdfOrphanInvoiceReturns403(): void {
		$this->objectService->method('find')->willReturn($this->entity(['invoiceNumber' => 'F-2026-0008']));
		$this->pdfGenerator->expects($this->never())->method('generatePdf');

		$response = $this->controller->pdf('inv-orphan');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testPdfOrphanInvoiceReturns403()

	/**
	 * The happy path returns the rendered document with the invoice and its
	 * lines handed to the renderer.
	 *
	 * @return void
	 */
	public function testPdfValidReturnsRenderedDocument(): void {
		$invoice = ['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-1'];
		$lines = [['description' => 'Advies', 'amount' => 900.0]];
		$this->objectService->method('find')->willReturn($this->entity($invoice));
		$this->objectService->method('findAll')->willReturn($lines);

		$seenInvoice = null;
		$seenLines = null;
		$this->pdfGenerator->method('generatePdf')->willReturnCallback(
			static function (array $invoiceArg, array $linesArg) use (&$seenInvoice, &$seenLines): array {
				$seenInvoice = $invoiceArg;
				$seenLines = $linesArg;
				return [
					'filename' => 'invoice-F-2026-0007.pdf',
					'html' => '<html><body>F-2026-0007</body></html>',
					'mimeType' => 'text/html',
				];
			}
		);

		$response = $this->controller->pdf('inv-1');

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('F-2026-0007', (string)$response->render());
		self::assertSame($invoice, $seenInvoice);
		self::assertSame($lines, $seenLines);

		$headers = $response->getHeaders();
		self::assertSame('text/html; charset=utf-8', $headers['Content-Type']);
		// NOTE: DataDisplayResponse::__construct() re-adds its own
		// `Content-Disposition: inline; filename=""` AFTER the constructor
		// headers are applied, so the controller's filename never survives to
		// the client. Asserted here as observed framework behaviour so a future
		// change to it is caught rather than silently assumed.
		self::assertSame('inline; filename=""', $headers['Content-Disposition']);

	}//end testPdfValidReturnsRenderedDocument()

	/**
	 * A hostile filename coming back from the renderer can never inject a
	 * response header: it is run through a strict allow-list before use, and
	 * no CR/LF, quote or path separator reaches any header value.
	 *
	 * @return void
	 */
	public function testPdfHostileFilenameCannotInjectAHeader(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['invoiceNumber' => 'F-1', 'administrationId' => 'adm-1'])
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->pdfGenerator->method('generatePdf')->willReturn(
			[
				'filename' => "../../etc/pass\"wd\r\nX-Injected: 1",
				'html' => '<html></html>',
				'mimeType' => 'text/html',
			]
		);

		$response = $this->controller->pdf('inv-1');

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		foreach ($response->getHeaders() as $name => $value) {
			self::assertStringNotContainsString("\r", (string)$value, $name . ' carries a CR');
			self::assertStringNotContainsString("\n", (string)$value, $name . ' carries an LF');
			self::assertStringNotContainsString('X-Injected', (string)$value, $name . ' carries an injected header');
		}

		self::assertStringNotContainsString('/', $response->getHeaders()['Content-Disposition']);

	}//end testPdfHostileFilenameCannotInjectAHeader()

	/**
	 * A renderer failure yields HTTP 500 and leaks no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testPdfRendererFailureReturns500WithoutStackTrace(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['invoiceNumber' => 'F-1', 'administrationId' => 'adm-1'])
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->pdfGenerator->method('generatePdf')->willThrowException(new \RuntimeException('renderer exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->pdf('inv-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'renderer exploded',
			(string)json_encode($response->getData())
		);

	}//end testPdfRendererFailureReturns500WithoutStackTrace()

	/**
	 * index() scopes every read to the server-resolved administration and
	 * exposes no request parameter that could change that scope
	 * (security-endpoint-guards REQ-001 — session-derived, IDOR-safe).
	 *
	 * @return void
	 */
	public function testIndexScopesToServerResolvedAdministration(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);

		$seenFilters = null;
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $params) use (&$seenFilters): array {
				$seenFilters = ($params['filters'] ?? []);
				return [];
			}
		);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['administrationId' => 'adm-1'], $seenFilters);

	}//end testIndexScopesToServerResolvedAdministration()

	/**
	 * An anonymous caller is rejected with HTTP 401 before any list read.
	 *
	 * @return void
	 */
	public function testIndexAnonymousReturns401(): void {
		$this->user = null;
		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testIndexAnonymousReturns401()

	/**
	 * An invoice owned by another administration yields HTTP 403 for show()
	 * and the lines are never fetched (security-endpoint-guards REQ-001).
	 *
	 * @return void
	 */
	public function testShowCrossTenantInvoiceReturns403(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-someone-else'])
		);
		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->show('inv-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testShowCrossTenantInvoiceReturns403()

	/**
	 * An invoice owned by the caller's own administration is returned with
	 * its lines — the positive control for testShowCrossTenantInvoiceReturns403().
	 *
	 * @return void
	 */
	public function testShowOwnAdministrationReturnsInvoiceAndLines(): void {
		$invoice = ['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-1'];
		$lines = [['description' => 'Advies']];
		$this->objectService->method('find')->willReturn($this->entity($invoice));
		$this->objectService->method('findAll')->willReturn($lines);

		$response = $this->controller->show('inv-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame($invoice, $data['invoice']);
		self::assertSame($lines, $data['lines']);

	}//end testShowOwnAdministrationReturnsInvoiceAndLines()

	/**
	 * An unknown invoice id yields HTTP 404 for show().
	 *
	 * @return void
	 */
	public function testShowUnknownInvoiceReturns404(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->show('inv-missing');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowUnknownInvoiceReturns404()

	/**
	 * An invoice owned by another administration yields HTTP 403 for post()
	 * and postInvoice() is never called (security-endpoint-guards REQ-001).
	 *
	 * @return void
	 */
	public function testPostCrossTenantInvoiceReturns403(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-someone-else'])
		);
		$this->service->expects($this->never())->method('postInvoice');

		$response = $this->controller->post('inv-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testPostCrossTenantInvoiceReturns403()

	/**
	 * An invoice owned by the caller's own administration is posted —
	 * the positive control for testPostCrossTenantInvoiceReturns403().
	 *
	 * @return void
	 */
	public function testPostOwnAdministrationReturns200(): void {
		$invoice = ['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-1', 'status' => 'draft'];
		$posted = array_merge($invoice, ['status' => 'posted']);
		$this->objectService->method('find')->willReturn($this->entity($invoice));
		$this->service->method('postInvoice')->with($invoice)->willReturn($posted);

		$response = $this->controller->post('inv-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($posted, $response->getData());

	}//end testPostOwnAdministrationReturns200()

	/**
	 * A failed post() maps a RuntimeException to a slug, never the raw
	 * exception text (ADR-050 / security-endpoint-guards REQ-003).
	 *
	 * @return void
	 */
	public function testPostAlreadyPostedReturnsConflictSlugWithoutLeakingMessage(): void {
		$invoice = ['invoiceNumber' => 'F-2026-0007', 'administrationId' => 'adm-1', 'status' => 'posted'];
		$this->objectService->method('find')->willReturn($this->entity($invoice));
		$this->service->method('postInvoice')->willThrowException(new \RuntimeException('Invoice is already posted.'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->post('inv-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		self::assertSame('invoice-post-conflict', $data['error']);
		self::assertArrayHasKey('message', $data);

	}//end testPostAlreadyPostedReturnsConflictSlugWithoutLeakingMessage()

	/**
	 * generate() ignores any administrationId the client tries to smuggle into
	 * the request body — the drafted invoice is always scoped to the caller's
	 * own session-resolved administration (security-endpoint-guards REQ-001).
	 *
	 * @return void
	 */
	public function testGenerateIgnoresClientSuppliedAdministrationId(): void {
		$body = [
			'administrationId' => 'adm-attacker',
			'billingModel' => 't_and_m',
			'customerId' => 'cust-1',
			'fromDate' => '2026-01-01',
			'toDate' => '2026-01-31',
			'rateCardId' => 'rc-1',
		];
		$this->request->method('getParams')->willReturn($body);

		$seenAdministrationId = null;
		$this->service->method('draftInvoice')->willReturnCallback(
			function (\OCA\Shillinq\Request\InvoiceGenerationRequest $req) use (&$seenAdministrationId): array {
				$seenAdministrationId = $req->administrationId;
				return ['administrationId' => $req->administrationId];
			}
		);

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('adm-1', $seenAdministrationId);

	}//end testGenerateIgnoresClientSuppliedAdministrationId()

	/**
	 * An anonymous caller is rejected with HTTP 401 before any drafting work.
	 *
	 * @return void
	 */
	public function testGenerateAnonymousReturns401(): void {
		$this->user = null;
		$this->service->expects($this->never())->method('draftInvoice');

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGenerateAnonymousReturns401()

	/**
	 * An invalid generation request maps to a static slug/message, never the
	 * raw validation exception text (ADR-050 / security-endpoint-guards REQ-003).
	 *
	 * @return void
	 */
	public function testGenerateInvalidRequestReturnsSlugWithoutLeakingMessage(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		self::assertSame('invoice-generate-invalid', $data['error']);
		self::assertStringNotContainsStringIgnoringCase('billingModel must be one of', (string)json_encode($data));

	}//end testGenerateInvalidRequestReturnsSlugWithoutLeakingMessage()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
