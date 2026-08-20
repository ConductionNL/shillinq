<?php

/**
 * Unit tests for ThreeWayMatchAuditController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ThreeWayMatchAuditController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the two read-only audit-trail endpoints (ledger, export) for: the
 * anonymous refusal, the required administration scope, the cross-tenant
 * 404 mask (ADR-005) — with the downstream service proven unreached — the
 * required invoiceId, the missing-invoice 404, the service-refusal mapping
 * and the no-stack-trace 500.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchAuditControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock AuditExportService.
	 *
	 * @var AuditExportService&MockObject
	 */
	private AuditExportService&MockObject $auditExportService;

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
	 * Mock DI container (lazy OR ObjectService).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

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
	 * @var ThreeWayMatchAuditController
	 */
	private ThreeWayMatchAuditController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->auditExportService = $this->createMock(AuditExportService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->currentUser = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->currentUser;
			}
		);

		$this->controller = new ThreeWayMatchAuditController(
			request: $this->request,
			auditExportService: $this->auditExportService,
			administrationContext: $this->administrationContext,
			userSession: $this->userSession,
			container: $this->container,
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
	 * A schema-aware fake OCA\OpenRegister\Service\ObjectService: findAll
	 * returns the canned records for the last setSchema().
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema.
	 *
	 * @return object
	 */
	private function makeObjectService(array $bySchema): object {
		return new class($bySchema) {
			/**
			 * Canned records keyed by schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $bySchema;

			/**
			 * Current schema selected by setSchema().
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema.
			 */
			public function __construct(array $bySchema) {
				$this->bySchema = $bySchema;
			}//end __construct()

			/**
			 * @param string $_register Ignored.
			 *
			 * @return self
			 */
			public function setRegister(string $_register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Selected schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $_filters Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $_filters = []): array {
				return ($this->bySchema[$this->schema] ?? []);
			}//end findAll()
		};

	}//end makeObjectService()

	/**
	 * A valid ledger read returns 200 with the built ledger payload.
	 *
	 * @return void
	 */
	public function testLedgerReturns200WithLedgerPayload(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$objectService = $this->makeObjectService(
			[
				'SupplierInvoice' => [['id' => 'inv-9', 'administrationId' => 'adm-1', 'invoiceNumber' => 'INV-9']],
				'ThreeWayMatch' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$this->auditExportService->expects($this->once())
			->method('buildLedger')
			->willReturnCallback(
				static function (array $bundle): array {
					return [
						'events' => [],
						'summary' => ['invoiceId' => (string)$bundle['invoice']['id']],
					];
				}
			);

		$response = $this->controller->ledger();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('inv-9', $response->getData()['summary']['invoiceId']);

	}//end testLedgerReturns200WithLedgerPayload()

	/**
	 * An anonymous ledger read is refused with 401 and never reaches the
	 * export service.
	 *
	 * @return void
	 */
	public function testLedgerRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->auditExportService->expects($this->never())->method('buildLedger');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testLedgerRejectsAnonymousCaller()

	/**
	 * A missing administrationId is a 400 — the read is never unscoped.
	 *
	 * @return void
	 */
	public function testLedgerRequiresAdministrationId(): void {
		$this->withParams(['invoiceId' => 'inv-9']);
		$this->auditExportService->expects($this->never())->method('buildLedger');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testLedgerRequiresAdministrationId()

	/**
	 * Reading the ledger inside another tenant's administration is masked
	 * as 404 (ADR-005) and the export service is never reached — proving
	 * the guard short-circuits rather than merely matching the status code.
	 *
	 * @return void
	 */
	public function testLedgerForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(false);

		// The invoice DOES exist and IS otherwise loadable — isolates the
		// assertion to the administration guard rather than a coincidental
		// "invoice not found" from an unconfigured container/ObjectService.
		$objectService = $this->makeObjectService(
			[
				'SupplierInvoice' => [['id' => 'inv-9', 'administrationId' => 'adm-other', 'invoiceNumber' => 'INV-9']],
				'ThreeWayMatch' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);
		$this->auditExportService->expects($this->never())->method('buildLedger');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Supplier invoice not found'], $response->getData());

	}//end testLedgerForeignAdministrationReturns404()

	/**
	 * A missing invoiceId is a 400.
	 *
	 * @return void
	 */
	public function testLedgerRequiresInvoiceId(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->auditExportService->expects($this->never())->method('buildLedger');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'invoiceId is required'], $response->getData());

	}//end testLedgerRequiresInvoiceId()

	/**
	 * A ledger read for an invoice that does not exist (in-scope but
	 * absent) is masked as 404, matching the cross-tenant response so a
	 * probe cannot distinguish "wrong tenant" from "no such invoice".
	 *
	 * @return void
	 */
	public function testLedgerUnknownInvoiceReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-missing']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$objectService = $this->makeObjectService(['SupplierInvoice' => []]);
		$this->container->method('get')->willReturn($objectService);
		$this->auditExportService->expects($this->never())->method('buildLedger');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Supplier invoice not found'], $response->getData());

	}//end testLedgerUnknownInvoiceReturns404()

	/**
	 * An unexpected failure while building the ledger is logged and returns
	 * a generic 500 with no trace.
	 *
	 * @return void
	 */
	public function testLedgerUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$objectService = $this->makeObjectService(
			[
				'SupplierInvoice' => [['id' => 'inv-9', 'administrationId' => 'adm-1']],
				'ThreeWayMatch' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);
		$this->auditExportService->method('buildLedger')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_match missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->ledger();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not load audit trail'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testLedgerUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * A valid export returns 200 with the package envelope, and the
	 * server-only zipPath is stripped from the response.
	 *
	 * @return void
	 */
	public function testExportReturns200WithEnvelopeStrippingZipPath(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->auditExportService->expects($this->once())
			->method('generateAuditPackage')
			->willReturnCallback(
				static function (string $administrationId, string $invoiceId) use (&$seen): array {
					$seen = [$administrationId, $invoiceId];
					return [
						'packageId' => 'audit-INV-9-abc123',
						'invoiceId' => $invoiceId,
						'sha256' => 'deadbeef',
						'zipPath' => '/tmp/audit-INV-9-abc123.zip',
						'archived' => true,
					];
				}
			);

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'inv-9'], $seen);
		$data = $response->getData();
		self::assertSame('audit-INV-9-abc123', $data['packageId']);
		self::assertArrayNotHasKey('zipPath', $data);

	}//end testExportReturns200WithEnvelopeStrippingZipPath()

	/**
	 * An anonymous export is refused with 401 and never reaches the export
	 * service.
	 *
	 * @return void
	 */
	public function testExportRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->auditExportService->expects($this->never())->method('generateAuditPackage');

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testExportRejectsAnonymousCaller()

	/**
	 * A missing administrationId is a 400 — the export is never unscoped.
	 *
	 * @return void
	 */
	public function testExportRequiresAdministrationId(): void {
		$this->withParams(['invoiceId' => 'inv-9']);
		$this->auditExportService->expects($this->never())->method('generateAuditPackage');

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testExportRequiresAdministrationId()

	/**
	 * Exporting inside another tenant's administration is masked as 404
	 * (ADR-005) and the export service is never reached — proving the
	 * guard short-circuits rather than merely matching the status code.
	 *
	 * @return void
	 */
	public function testExportForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->auditExportService->expects($this->never())->method('generateAuditPackage');

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Supplier invoice not found'], $response->getData());

	}//end testExportForeignAdministrationReturns404()

	/**
	 * A missing invoiceId is a 400.
	 *
	 * @return void
	 */
	public function testExportRequiresInvoiceId(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->auditExportService->expects($this->never())->method('generateAuditPackage');

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'invoiceId is required'], $response->getData());

	}//end testExportRequiresInvoiceId()

	/**
	 * A "not found" refusal from the export service maps to 404, not 400.
	 *
	 * @return void
	 */
	public function testExportUnknownInvoiceReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->auditExportService->method('generateAuditPackage')
			->willThrowException(new \RuntimeException('Supplier invoice not found'));

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Supplier invoice not found'], $response->getData());

	}//end testExportUnknownInvoiceReturns404()

	/**
	 * Any other export-service refusal maps to 400 (validation).
	 *
	 * @return void
	 */
	public function testExportServiceRefusalReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->auditExportService->method('generateAuditPackage')
			->willThrowException(new \RuntimeException('ZIP write failed'));

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testExportServiceRefusalReturns400()

	/**
	 * An unexpected failure on export is logged and returns a generic 500
	 * with no trace.
	 *
	 * @return void
	 */
	public function testExportUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->auditExportService->method('generateAuditPackage')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_match missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->export();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not export audit package'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testExportUnexpectedFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
