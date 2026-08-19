<?php

/**
 * Unit tests for StockLedgerController.
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
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\StockLedgerController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\StockLedgerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only stock-ledger drill-down endpoint (REQ-SM-005, REQ-SM-009).
 *
 * Covers the anonymous rejection, both parameter-validation rejections, the
 * masked cross-tenant 404, the arithmetic of the reconciled balance
 * (available = onHand - reserved) and the 500 fail path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StockLedgerControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock StockLedgerService.
	 *
	 * @var StockLedgerService&MockObject
	 */
	private StockLedgerService&MockObject $ledger;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The user id the context resolves to; null means anonymous.
	 *
	 * @var string|null
	 */
	private ?string $userId = 'alice';

	/**
	 * Whether the context grants access to the requested administration.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var StockLedgerController
	 */
	private StockLedgerController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->ledger = $this->createMock(StockLedgerService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->context->method('currentUserId')->willReturnCallback(
			function (): ?string {
				return $this->userId;
			}
		);
		$this->context->method('canAccess')->willReturnCallback(
			function (): bool {
				return $this->canAccess;
			}
		);

		$this->controller = new StockLedgerController(
			request: $this->request,
			ledger: $this->ledger,
			context: $this->context,
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
	 * A complete, well-formed parameter set.
	 *
	 * @return array<string,string>
	 */
	private function validParams(): array {
		return [
			'administration_id' => 'adm-1',
			'location_id' => 'LOC-A1',
			'sku' => 'SKU-100',
		];

	}//end validParams()

	/**
	 * An anonymous caller is rejected with HTTP 401 before any ledger read.
	 *
	 * @return void
	 */
	public function testTraceAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams($this->validParams());
		$this->ledger->expects($this->never())->method('traceLocation');

		$response = $this->controller->trace();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTraceAnonymousReturns401()

	/**
	 * A missing sku yields HTTP 400 (REQ-SM-005 requires the full triple).
	 *
	 * @return void
	 */
	public function testTraceMissingSkuReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1', 'location_id' => 'LOC-A1']);

		$response = $this->controller->trace();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testTraceMissingSkuReturns400()

	/**
	 * A path-traversal location_id is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testTraceMalformedLocationReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'location_id' => '../../etc',
				'sku' => 'SKU-100',
			]
		);
		$this->ledger->expects($this->never())->method('quantityForLocation');

		$response = $this->controller->trace();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testTraceMalformedLocationReturns400()

	/**
	 * A non-member sees a masked HTTP 404, not a 403 oracle (ADR-005).
	 *
	 * @return void
	 */
	public function testTraceForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams($this->validParams());
		$this->ledger->expects($this->never())->method('traceLocation');

		$response = $this->controller->trace();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTraceForeignAdministrationReturns404()

	/**
	 * A valid request returns HTTP 200 with the reconciled balance and the
	 * drill-down trace; `available` is onHand minus reserved (REQ-SM-009).
	 *
	 * @return void
	 */
	public function testTraceValidReturns200WithReconciledBalance(): void {
		$this->withParams($this->validParams());
		$trace = [
			[
				'movementNumber' => 'SM-0001',
				'postedAt' => '2026-05-01T09:00:00Z',
				'movementType' => 'receipt',
				'sign' => 1,
				'quantity' => 40.0,
				'runningTotal' => 40.0,
			],
			[
				'movementNumber' => 'SM-0002',
				'postedAt' => '2026-05-04T09:00:00Z',
				'movementType' => 'issue',
				'sign' => -1,
				'quantity' => 15.0,
				'runningTotal' => 25.0,
			],
		];
		$this->ledger->expects($this->once())->method('quantityForLocation')->willReturn(25.0);
		$this->ledger->expects($this->once())->method('reservedForLocation')->willReturn(4.0);
		$this->ledger->expects($this->once())->method('traceLocation')->willReturn($trace);

		$response = $this->controller->trace();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('adm-1', $data['administrationId']);
		self::assertSame('LOC-A1', $data['locationId']);
		self::assertSame('SKU-100', $data['sku']);
		self::assertSame(25.0, $data['onHand']);
		self::assertSame(4.0, $data['reserved']);
		self::assertSame(21.0, $data['available']);
		self::assertSame($trace, $data['trace']);

	}//end testTraceValidReturns200WithReconciledBalance()

	/**
	 * A ledger failure yields HTTP 500 and leaks no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testTraceLedgerFailureReturns500WithoutStackTrace(): void {
		$this->withParams($this->validParams());
		$this->ledger->method('quantityForLocation')->willThrowException(new \RuntimeException('ledger exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->trace();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'ledger exploded',
			(string)json_encode($response->getData())
		);

	}//end testTraceLedgerFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
