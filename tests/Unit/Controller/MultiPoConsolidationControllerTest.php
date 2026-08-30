<?php

/**
 * Unit tests for MultiPoConsolidationController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\MultiPoConsolidationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent stand-in for OCA\OpenRegister\Service\ObjectService — enough surface
 * for the controller's SupplierInvoice header lookup (setRegister + setSchema
 * + findAll on a filters map).
 */
final class FakeMultiPoObjectService {

	/**
	 * Seeded rows per schema.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $rows;

	/**
	 * Currently selected schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, list<array<string,mixed>>> $rows Seed rows per schema.
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;

	}//end __construct()

	/**
	 * Fluent register setter (single-register fixture — value ignored).
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;

	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * Filter the seeded rows of the active schema by an equality filters map.
	 *
	 * @param array<string,mixed> $query Query carrying a `filters` map.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function findAll(array $query): array {
		$filters = (array)($query['filters'] ?? []);
		$out = [];
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			$matches = true;
			foreach ($filters as $key => $value) {
				if ((string)($row[$key] ?? '') !== (string)$value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				$out[] = $row;
			}
		}

		return $out;

	}//end findAll()
}//end class

/**
 * Covers the three routed multi-PO consolidation endpoints: the fan-out
 * (consolidate), the candidate enumeration (candidates) and the operator's
 * chosen-tuple persistence (disambiguate).
 *
 * Asserts the anonymous 401 guard, the cross-tenant 404 mask (ADR-005), the
 * input-validation 400s, the RuntimeException → 404/400 mapping and the 500
 * path that leaks no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MultiPoConsolidationControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock MultiPoConsolidationService.
	 *
	 * @var MultiPoConsolidationService&MockObject
	 */
	private MultiPoConsolidationService&MockObject $consolidation;

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
	 * The OR fake handed back by the container.
	 *
	 * @var FakeMultiPoObjectService
	 */
	private FakeMultiPoObjectService $objectService;

	/**
	 * Set up shared fixtures — an authenticated session with an accessible
	 * administration is the default; individual tests override.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->consolidation = $this->createMock(MultiPoConsolidationService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = new FakeMultiPoObjectService([]);

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
	 * Build the controller over the current mocks.
	 *
	 * @return MultiPoConsolidationController
	 */
	private function controller(): MultiPoConsolidationController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($appConfig): object {
				if ($id === IAppConfig::class) {
					return $appConfig;
				}

				return $this->objectService;
			}
		);

		return new MultiPoConsolidationController(
			$this->request,
			$this->consolidation,
			$container,
			$this->administrationContext,
			$this->userSession,
			$this->logger,
		);

	}//end controller()

	/**
	 * consolidate() returns the per-line fan-out result with HTTP 200.
	 *
	 * @return void
	 */
	public function testConsolidateReturns200WithPerLineResults(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$results = [
			['invoiceLineNumber' => 1, 'status' => 'matched', 'poLineId' => 'pol-1'],
			['invoiceLineNumber' => 2, 'status' => 'ambiguous', 'candidateCount' => 3],
		];
		$this->consolidation->expects($this->once())
			->method('consolidateInvoice')
			->willReturn($results);

		$response = $this->controller()->consolidate();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['lines' => $results], $response->getData());

	}//end testConsolidateReturns200WithPerLineResults()

	/**
	 * consolidate() rejects an anonymous caller with HTTP 401 before touching
	 * the service.
	 *
	 * @return void
	 */
	public function testConsolidateAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->consolidation->expects($this->never())->method('consolidateInvoice');

		$response = $this->controller()->consolidate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testConsolidateAnonymousReturns401()

	/**
	 * consolidate() requires an invoiceId — a blank one is HTTP 400.
	 *
	 * @return void
	 */
	public function testConsolidateMissingInvoiceIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);

		$response = $this->controller()->consolidate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invoiceId is required', $response->getData()['error']);

	}//end testConsolidateMissingInvoiceIdReturns400()

	/**
	 * consolidate() masks a cross-tenant administration as HTTP 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testConsolidateCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other', 'invoiceId' => 'inv-9']);
		$this->consolidation->expects($this->never())->method('consolidateInvoice');

		$response = $this->controller()->consolidate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testConsolidateCrossTenantReturns404()

	/**
	 * A service RuntimeException carrying "not found" maps to HTTP 404.
	 *
	 * @return void
	 */
	public function testConsolidateNotFoundRuntimeExceptionMapsTo404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->consolidation->method('consolidateInvoice')
			->willThrowException(new \RuntimeException('Supplier invoice not found'));

		$response = $this->controller()->consolidate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Supplier invoice not found', $response->getData()['error']);

	}//end testConsolidateNotFoundRuntimeExceptionMapsTo404()

	/**
	 * An unexpected Throwable yields HTTP 500 and leaks no stack trace.
	 *
	 * @return void
	 */
	public function testConsolidateUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->consolidation->method('consolidateInvoice')
			->willThrowException(new \LogicException('db credentials rejected'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->consolidate();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'credentials',
			(string)json_encode($response->getData())
		);

	}//end testConsolidateUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * candidates() loads the invoice header, locates the line and returns the
	 * ordered candidate tuples with HTTP 200.
	 *
	 * @return void
	 */
	public function testCandidatesReturns200WithOrderedTuples(): void {
		$this->objectService = new FakeMultiPoObjectService(
			[
				'SupplierInvoice' => [
					[
						'id' => 'inv-9',
						'administrationId' => 'adm-1',
						'supplierId' => 'sup-1',
						'lines' => [
							['lineNumber' => 1, 'productCode' => 'A', 'quantity' => 4],
							['lineNumber' => 2, 'productCode' => 'B', 'quantity' => 7],
						],
					],
				],
			]
		);
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 2,
			]
		);
		$tuples = [
			['poLineId' => 'pol-7', 'grnLineId' => 'grn-7', 'score' => 0.91],
			['poLineId' => 'pol-8', 'grnLineId' => null, 'score' => 0.62],
		];
		$this->consolidation->expects($this->once())
			->method('enumerateCandidateTuples')
			->willReturnCallback(
				static function (string $administrationId, array $invoice, array $invoiceLine) use ($tuples): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('sup-1', $invoice['supplierId']);
					self::assertSame('B', $invoiceLine['productCode']);
					return $tuples;
				}
			);

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('inv-9', $data['invoiceId']);
		self::assertSame(2, $data['invoiceLineNumber']);
		self::assertSame($tuples, $data['candidates']);

	}//end testCandidatesReturns200WithOrderedTuples()

	/**
	 * candidates() requires a 1-based invoiceLineNumber — 0 is HTTP 400.
	 *
	 * @return void
	 */
	public function testCandidatesMissingLineNumberReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->consolidation->expects($this->never())->method('enumerateCandidateTuples');

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invoiceLineNumber is required', $response->getData()['error']);

	}//end testCandidatesMissingLineNumberReturns400()

	/**
	 * An invoice that does not exist in the caller's administration answers
	 * HTTP 404 rather than confirming its absence differently.
	 *
	 * @return void
	 */
	public function testCandidatesUnknownInvoiceReturns404(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-absent',
				'invoiceLineNumber' => 1,
			]
		);
		$this->consolidation->expects($this->never())->method('enumerateCandidateTuples');

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Supplier invoice not found', $response->getData()['error']);

	}//end testCandidatesUnknownInvoiceReturns404()

	/**
	 * A line number past the end of the invoice answers HTTP 404.
	 *
	 * @return void
	 */
	public function testCandidatesUnknownLineReturns404(): void {
		$this->objectService = new FakeMultiPoObjectService(
			[
				'SupplierInvoice' => [
					[
						'id' => 'inv-9',
						'administrationId' => 'adm-1',
						'supplierId' => 'sup-1',
						'lines' => [['lineNumber' => 1, 'productCode' => 'A']],
					],
				],
			]
		);
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 42,
			]
		);

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Invoice line not found', $response->getData()['error']);

	}//end testCandidatesUnknownLineReturns404()

	/**
	 * candidates() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testCandidatesAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'invoiceLineNumber' => 1]);

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCandidatesAnonymousReturns401()

	/**
	 * candidates() masks a cross-tenant administration as HTTP 404 (ADR-005 /
	 * security-endpoint-guards REQ-001) — a non-member of the requested
	 * administration is rejected before the invoice lookup runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testCandidatesCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other', 'invoiceId' => 'inv-9', 'invoiceLineNumber' => 1]);

		$response = $this->controller()->candidates();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Administration not found', $response->getData()['error']);

	}//end testCandidatesCrossTenantReturns404()

	/**
	 * disambiguate() persists the chosen tuple and answers HTTP 201 with the
	 * ThreeWayMatch.
	 *
	 * @return void
	 */
	public function testDisambiguateReturns201WithPersistedMatch(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 2,
				'chosenPoLineId' => 'pol-7',
				'chosenGrnLineId' => 'grn-7',
			]
		);
		$match = ['id' => 'twm-1', 'poLineId' => 'pol-7', 'grnLineId' => 'grn-7', 'status' => 'matched'];
		$this->consolidation->expects($this->once())
			->method('disambiguateAmbiguousMatches')
			->willReturn($match);

		$response = $this->controller()->disambiguate();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame($match, $response->getData());

	}//end testDisambiguateReturns201WithPersistedMatch()

	/**
	 * A malformed chosenPoLineId (path traversal) is rejected with HTTP 400
	 * before the service is reached.
	 *
	 * @return void
	 */
	public function testDisambiguateRejectsMalformedPoLineId(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 2,
				'chosenPoLineId' => '../../etc/passwd',
			]
		);
		$this->consolidation->expects($this->never())->method('disambiguateAmbiguousMatches');

		$response = $this->controller()->disambiguate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('chosenPoLineId is required', $response->getData()['error']);

	}//end testDisambiguateRejectsMalformedPoLineId()

	/**
	 * A forged tuple the server cannot re-enumerate maps to HTTP 400, not 201.
	 *
	 * @return void
	 */
	public function testDisambiguateForgedTupleMapsTo400(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 2,
				'chosenPoLineId' => 'pol-foreign',
			]
		);
		$this->consolidation->method('disambiguateAmbiguousMatches')
			->willThrowException(new \RuntimeException('Chosen tuple is not in the candidate set'));

		$response = $this->controller()->disambiguate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Chosen tuple is not in the candidate set', $response->getData()['error']);

	}//end testDisambiguateForgedTupleMapsTo400()

	/**
	 * disambiguate() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testDisambiguateAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['administrationId' => 'adm-1']);
		$this->consolidation->expects($this->never())->method('disambiguateAmbiguousMatches');

		$response = $this->controller()->disambiguate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testDisambiguateAnonymousReturns401()

	/**
	 * disambiguate() masks a cross-tenant administration as HTTP 404 (ADR-005 /
	 * security-endpoint-guards REQ-001) — a non-member of the requested
	 * administration is rejected before the chosen tuple is persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function testDisambiguateCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(
			[
				'administrationId' => 'adm-other',
				'invoiceId' => 'inv-9',
				'invoiceLineNumber' => 1,
				'chosenPoLineId' => 'pol-7',
			]
		);
		$this->consolidation->expects($this->never())->method('disambiguateAmbiguousMatches');

		$response = $this->controller()->disambiguate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Administration not found', $response->getData()['error']);

	}//end testDisambiguateCrossTenantReturns404()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
