<?php

/**
 * Unit tests for SupplierInvoiceImportController.
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
 * @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\SupplierInvoiceImportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the dashboard supplier-invoice import controller.
 *
 * Covers REQ-BIM-001 (UBL + CSV ingest), REQ-BIM-002 (honest PDF-OCR
 * deferral 422), REQ-BIM-005 (duplicate 409), server-resolved admin id
 * (ADR-005) and the anon 401 path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierInvoiceImportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SupplierInvoiceService.
	 *
	 * @var SupplierInvoiceService&MockObject
	 */
	private SupplierInvoiceService&MockObject $service;

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
	private IUserSession&MockObject $session;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var SupplierInvoiceImportController
	 */
	private SupplierInvoiceImportController $controller;

	/**
	 * Captured ObjectService stub findAll filters (proves admin is
	 * server-resolved, never client-supplied).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $capturedFilters = [];

	/**
	 * Rows the ObjectService stub returns from findAll.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $stubRows = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SupplierInvoiceService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Authenticated session by default.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		// Server-resolved administration id (ADR-005).
		$this->administrationContext->method('buildContext')->willReturn(
			[
				'userId' => 'alice',
				'administrations' => [],
				'activeAdministrationId' => 'adm-1',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$this->controller = new SupplierInvoiceImportController(
			request: $this->request,
			supplierInvoiceService: $this->service,
			administrationContext: $this->administrationContext,
			session: $this->session,
			logger: $this->logger,
			objectService: $this->makeObjectServiceStub(),
		);

	}//end setUp()

	/**
	 * Build an ObjectService double over the real contract.
	 *
	 * @return ObjectServiceInterface
	 */
	private function makeObjectServiceStub(): ObjectServiceInterface {
		// ADR-084: this file's own duck-typed stub reached the controller through a
		// ContainerInterface mock, while `objectService:` got a bare createMock() —
		// so the controller read an EMPTY double, recorded no filters, and its
		// saveObject() helper returned its own input. createMock() of the real
		// interface is regenerated from the contract, so a signature that moves
		// upstream breaks this double instead of quietly answering the old shape.
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->recordFilters((array)($config['filters'] ?? []));
				return $this->stubRowsForTest();
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object): ObjectEntityInterface {
				$object['id'] = 'created-id';
				return new ObjectEntityStub(payload: $object);
			}
		);

		return $objectService;

	}//end makeObjectServiceStub()

	/**
	 * Record the filters passed to the ObjectService stub.
	 *
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return void
	 */
	public function recordFilters(array $filters): void {
		$this->capturedFilters[] = $filters;

	}//end recordFilters()

	/**
	 * Expose the stub rows to the anonymous ObjectService stub.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function stubRowsForTest(): array {
		return $this->stubRows;
	}//end stubRowsForTest()

	/**
	 * Configure the request to deliver a JSON body (no uploaded file).
	 *
	 * @param string $contents File contents.
	 * @param string $format Format hint.
	 *
	 * @return void
	 */
	private function withJsonBody(string $contents, string $format): void {
		$this->request->method('getUploadedFile')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($format): mixed {
				if ($key === 'format') {
					return $format;
				}

				return $default;
			}
		);
		$this->request->method('getParams')->willReturn(
			['contents' => $contents, 'format' => $format]
		);

	}//end withJsonBody()

	/**
	 * An anonymous request is rejected with HTTP 401.
	 *
	 * @return void
	 */
	public function testAnonymousReturns401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new SupplierInvoiceImportController(
			request: $this->request,
			supplierInvoiceService: $this->service,
			administrationContext: $this->administrationContext,
			session: $session,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$response = $controller->import();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousReturns401()

	/**
	 * A UBL upload ingests and returns the created record (REQ-BIM-001).
	 *
	 * @return void
	 */
	public function testUblImportReturnsRecord(): void {
		$this->withJsonBody('<Invoice/>', 'ubl');
		$this->stubRows = [];
		$this->service->method('parseUblInvoice')->willReturn(
			['invoiceNumber' => 'INV-1', 'supplierId' => 'SUP-1']
		);
		$this->service->expects(self::once())
			->method('ingestUBLInvoice')
			->with('adm-1', '<Invoice/>')
			->willReturn(['id' => 'si-1', 'invoiceNumber' => 'INV-1', 'supplierId' => 'SUP-1']);

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(1, $data['imported']);
		self::assertSame('si-1', $data['record']['id']);

		// ADR-005: the duplicate pre-check filtered on the server-resolved
		// adm-1, never a client value.
		self::assertNotEmpty($this->capturedFilters);
		self::assertSame('adm-1', $this->capturedFilters[0]['administrationId']);

	}//end testUblImportReturnsRecord()

	/**
	 * A duplicate UBL invoice returns HTTP 409 (REQ-BIM-005).
	 *
	 * @return void
	 */
	public function testDuplicateUblReturns409(): void {
		$this->withJsonBody('<Invoice/>', 'ubl');
		$this->service->method('parseUblInvoice')->willReturn(
			['invoiceNumber' => 'INV-DUP', 'supplierId' => 'SUP-1']
		);
		// The pre-check finds an existing record.
		$this->stubRows = [['id' => 'existing', 'invoiceNumber' => 'INV-DUP', 'supplierId' => 'SUP-1']];
		$this->service->expects(self::never())->method('ingestUBLInvoice');

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(
			'This invoice number already exists for this supplier',
			$response->getData()['error']
		);

	}//end testDuplicateUblReturns409()

	/**
	 * A service "already exists" RuntimeException maps to 409 (REQ-BIM-005).
	 *
	 * @return void
	 */
	public function testServiceAlreadyExistsMapsTo409(): void {
		$this->withJsonBody('<Invoice/>', 'ubl');
		$this->stubRows = [];
		$this->service->method('parseUblInvoice')->willReturn(
			['invoiceNumber' => 'INV-2', 'supplierId' => 'SUP-1']
		);
		$this->service->method('ingestUBLInvoice')->willThrowException(
			new RuntimeException('This invoice already exists')
		);

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testServiceAlreadyExistsMapsTo409()

	/**
	 * Malformed UBL maps to HTTP 422 (REQ-BIM-001).
	 *
	 * @return void
	 */
	public function testMalformedUblReturns422(): void {
		$this->withJsonBody('<bad', 'ubl');
		$this->service->method('parseUblInvoice')->willThrowException(
			new RuntimeException('UBL Invoice XML is malformed')
		);

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testMalformedUblReturns422()

	/**
	 * A PDF upload is honestly deferred with HTTP 422 + deferred marker
	 * (REQ-BIM-002) — never a fabricated extraction.
	 *
	 * @return void
	 */
	public function testPdfImportIsDeferred422(): void {
		$this->withJsonBody('%PDF-1.7 binary', 'pdf');

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

		$data = $response->getData();
		self::assertSame('pdf-ocr', $data['deferred']);
		self::assertStringContainsString('PDF OCR extraction is not yet available', $data['error']);

	}//end testPdfImportIsDeferred422()

	/**
	 * A CSV upload parses rows and creates one SupplierInvoice per row
	 * (REQ-BIM-001).
	 *
	 * @return void
	 */
	public function testCsvImportParsesRows(): void {
		$csv = "supplier,invoiceNumber,invoiceDate,amount,vatAmount\n"
			. "SUP-1,INV-100,2026-01-01,121.00,21.00\n"
			. "SUP-2,INV-101,2026-01-02,242.00,42.00\n";
		$this->withJsonBody($csv, 'csv');
		$this->stubRows = [];

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(2, $data['imported']);
		self::assertCount(2, $data['records']);
		// Cents conversion: 121.00 EUR -> 12100 cents.
		self::assertSame(12100, $data['records'][0]['totalInclVat']);
		self::assertSame('received', $data['records'][0]['statusCode']);
		self::assertSame('csv', $data['records'][0]['sourceFormat']);
		// ADR-005: rows carry the server-resolved admin id.
		self::assertSame('adm-1', $data['records'][0]['administrationId']);

	}//end testCsvImportParsesRows()

	/**
	 * A duplicate CSV row is skipped, not re-created (REQ-BIM-005).
	 *
	 * @return void
	 */
	public function testCsvImportSkipsDuplicateRow(): void {
		$csv = "supplier,invoiceNumber,invoiceDate,amount,vatAmount\n"
			. "SUP-1,INV-DUP,2026-01-01,121.00,21.00\n";
		$this->withJsonBody($csv, 'csv');
		// Pre-check finds the row already exists.
		$this->stubRows = [['id' => 'existing']];

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(0, $data['imported']);
		self::assertSame(1, $data['skipped']);

	}//end testCsvImportSkipsDuplicateRow()

	/**
	 * An empty upload returns HTTP 422 (REQ-BIM-001).
	 *
	 * @return void
	 */
	public function testEmptyUploadReturns422(): void {
		$this->withJsonBody('', 'ubl');

		$response = $this->controller->import();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testEmptyUploadReturns422()

}//end class
