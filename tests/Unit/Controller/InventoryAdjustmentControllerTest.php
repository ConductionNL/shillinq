<?php

/**
 * Unit tests for InventoryAdjustmentController.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryAdjustmentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\LandedCostAllocationService;
use OCA\Shillinq\Service\NrvWriteDownService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the two inventory balance-sheet-correction write endpoints.
 *
 * Covers the shared authentication guard, per-endpoint body validation
 * (including the nrv_by_sku map element check), the masked cross-tenant 404
 * and the two 500 fail paths that leak no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryAdjustmentControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock LandedCostAllocationService.
	 *
	 * @var LandedCostAllocationService&MockObject
	 */
	private LandedCostAllocationService&MockObject $landedCost;

	/**
	 * Mock NrvWriteDownService.
	 *
	 * @var NrvWriteDownService&MockObject
	 */
	private NrvWriteDownService&MockObject $nrv;

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
	 * @var InventoryAdjustmentController
	 */
	private InventoryAdjustmentController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->landedCost = $this->createMock(LandedCostAllocationService::class);
		$this->nrv = $this->createMock(NrvWriteDownService::class);
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

		$this->controller = new InventoryAdjustmentController(
			request: $this->request,
			landedCost: $this->landedCost,
			nrv: $this->nrv,
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
	 * An anonymous caller is rejected with HTTP 401 on landedCost.
	 *
	 * @return void
	 */
	public function testLandedCostAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1', 'receipt_reference' => 'GRN-1', 'landed_cost_cents' => 5000]);
		$this->landedCost->expects($this->never())->method('allocate');

		$response = $this->controller->landedCost();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testLandedCostAnonymousReturns401()

	/**
	 * A missing receipt_reference yields HTTP 400.
	 *
	 * @return void
	 */
	public function testLandedCostMissingReceiptReferenceReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testLandedCostMissingReceiptReferenceReturns400()

	/**
	 * A non-positive landed_cost_cents is rejected with HTTP 400 — a zero or
	 * negative capitalisation would post an unbalanced or reversing entry.
	 *
	 * @return void
	 */
	public function testLandedCostZeroAmountReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'receipt_reference' => 'GRN-1',
				'landed_cost_cents' => 0,
			]
		);
		$this->landedCost->expects($this->never())->method('allocate');

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLandedCostZeroAmountReturns400()

	/**
	 * An unsupported allocation basis is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testLandedCostUnknownBasisReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'receipt_reference' => 'GRN-1',
				'landed_cost_cents' => 5000,
				'basis' => 'weight',
			]
		);
		$this->landedCost->expects($this->never())->method('allocate');

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLandedCostUnknownBasisReturns400()

	/**
	 * A non-member sees a masked HTTP 404 (ADR-005, no IDOR).
	 *
	 * @return void
	 */
	public function testLandedCostForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(
			[
				'administration_id' => 'adm-other',
				'receipt_reference' => 'GRN-1',
				'landed_cost_cents' => 5000,
			]
		);
		$this->landedCost->expects($this->never())->method('allocate');

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testLandedCostForeignAdministrationReturns404()

	/**
	 * A valid landed-cost request returns HTTP 200 with the service result.
	 *
	 * @return void
	 */
	public function testLandedCostValidReturns200(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'receipt_reference' => 'GRN-1',
				'landed_cost_cents' => 12500,
				'basis' => 'quantity',
			]
		);
		$result = [
			'allocated' => true,
			'transactionId' => 'GL-777',
			'allocatedCents' => 12500,
			'lines' => [['sku' => 'SKU-1', 'cents' => 12500]],
		];
		$this->landedCost->expects($this->once())->method('allocate')->willReturn($result);

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());

	}//end testLandedCostValidReturns200()

	/**
	 * A landed-cost service failure yields HTTP 500 without a stack trace.
	 *
	 * @return void
	 */
	public function testLandedCostServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'receipt_reference' => 'GRN-1',
				'landed_cost_cents' => 12500,
			]
		);
		$this->landedCost->method('allocate')->willThrowException(new \RuntimeException('posting exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->landedCost();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'posting exploded',
			(string)json_encode($response->getData())
		);

	}//end testLandedCostServiceFailureReturns500WithoutStackTrace()

	/**
	 * An anonymous caller is rejected with HTTP 401 on nrvWriteDown.
	 *
	 * @return void
	 */
	public function testNrvWriteDownAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '2026-06',
				'nrv_by_sku' => ['SKU-1' => 4.5],
			]
		);
		$this->nrv->expects($this->never())->method('runForAdministration');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testNrvWriteDownAnonymousReturns401()

	/**
	 * An empty nrv_by_sku map yields HTTP 400 — there is nothing to write down.
	 *
	 * @return void
	 */
	public function testNrvWriteDownEmptyMapReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '2026-06',
				'nrv_by_sku' => [],
			]
		);
		$this->nrv->expects($this->never())->method('runForAdministration');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testNrvWriteDownEmptyMapReturns400()

	/**
	 * A non-numeric NRV value inside the map yields HTTP 400 — the element
	 * check must reject a poisoned entry, not silently cast it to 0.0.
	 *
	 * @return void
	 */
	public function testNrvWriteDownNonNumericValueReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '2026-06',
				'nrv_by_sku' => ['SKU-1' => 4.5, 'SKU-2' => 'cheap'],
			]
		);
		$this->nrv->expects($this->never())->method('runForAdministration');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testNrvWriteDownNonNumericValueReturns400()

	/**
	 * A malformed period_id is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testNrvWriteDownMalformedPeriodReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '../../etc',
				'nrv_by_sku' => ['SKU-1' => 4.5],
			]
		);
		$this->nrv->expects($this->never())->method('runForAdministration');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testNrvWriteDownMalformedPeriodReturns400()

	/**
	 * A non-member sees a masked HTTP 404 (ADR-005, no IDOR).
	 *
	 * @return void
	 */
	public function testNrvWriteDownForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(
			[
				'administration_id' => 'adm-other',
				'period_id' => '2026-06',
				'nrv_by_sku' => ['SKU-1' => 4.5],
			]
		);
		$this->nrv->expects($this->never())->method('runForAdministration');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testNrvWriteDownForeignAdministrationReturns404()

	/**
	 * A valid write-down run returns HTTP 200 and the SKU map reaches the
	 * service cast to floats.
	 *
	 * @return void
	 */
	public function testNrvWriteDownValidReturns200(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '2026-06',
				'nrv_by_sku' => ['SKU-1' => '4.5', 'SKU-2' => 9],
			]
		);
		$seen = null;
		$this->nrv->method('runForAdministration')->willReturnCallback(
			static function (string $administrationId, string $periodId, array $nrvBySku) use (&$seen): array {
				$seen = $nrvBySku;
				return ['writeDownCount' => 2, 'totalCents' => -3400, 'results' => []];
			}
		);

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(2, $response->getData()['writeDownCount']);
		self::assertSame(['SKU-1' => 4.5, 'SKU-2' => 9.0], $seen);

	}//end testNrvWriteDownValidReturns200()

	/**
	 * An NRV service failure yields HTTP 500 without a stack trace.
	 *
	 * @return void
	 */
	public function testNrvWriteDownServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'period_id' => '2026-06',
				'nrv_by_sku' => ['SKU-1' => 4.5],
			]
		);
		$this->nrv->method('runForAdministration')->willThrowException(new \RuntimeException('writedown exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->nrvWriteDown();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'writedown exploded',
			(string)json_encode($response->getData())
		);

	}//end testNrvWriteDownServiceFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
