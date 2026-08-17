<?php

/**
 * Unit tests for InventoryValuationReportController.
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

use OCA\Shillinq\Controller\InventoryValuationReportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryValuationReportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only inventory valuation report endpoint.
 *
 * Covers the anonymous rejection, the required/malformed parameter rejections,
 * the masked cross-tenant 404, the ageing opt-in (which is only honoured when
 * BOTH sku and warehouse are supplied) and the 500 fail path.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryValuationReportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock InventoryValuationReportService.
	 *
	 * @var InventoryValuationReportService&MockObject
	 */
	private InventoryValuationReportService&MockObject $report;

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
	 * @var InventoryValuationReportController
	 */
	private InventoryValuationReportController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->report = $this->createMock(InventoryValuationReportService::class);
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

		$this->controller = new InventoryValuationReportController(
			request: $this->request,
			report: $this->report,
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
	 * An anonymous caller is rejected with HTTP 401 before any read.
	 *
	 * @return void
	 */
	public function testReportAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1', 'as_of' => '2026-06-30']);
		$this->report->expects($this->never())->method('valuationAsOf');

		$response = $this->controller->report();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testReportAnonymousReturns401()

	/**
	 * A missing as_of yields HTTP 400.
	 *
	 * @return void
	 */
	public function testReportMissingAsOfReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testReportMissingAsOfReturns400()

	/**
	 * A non ISO-date as_of is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testReportMalformedAsOfReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1', 'as_of' => '30-06-2026']);
		$this->report->expects($this->never())->method('valuationAsOf');

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testReportMalformedAsOfReturns400()

	/**
	 * A non-member sees a masked HTTP 404, not a 403 oracle (ADR-005).
	 *
	 * @return void
	 */
	public function testReportForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administration_id' => 'adm-other', 'as_of' => '2026-06-30']);
		$this->report->expects($this->never())->method('valuationAsOf');

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testReportForeignAdministrationReturns404()

	/**
	 * A valid request returns HTTP 200 with the valuation and, absent the
	 * ageing opt-in, no ageing breakdown.
	 *
	 * @return void
	 */
	public function testReportValidReturns200WithoutAgeing(): void {
		$this->withParams(['administration_id' => 'adm-1', 'as_of' => '2026-06-30']);
		$valuation = [
			'asOf' => '2026-06-30',
			'totalCents' => 1250000,
			'totalQuantity' => 430.0,
			'lines' => [],
		];
		$this->report->expects($this->once())->method('valuationAsOf')->willReturn($valuation);
		$this->report->expects($this->never())->method('ageing');

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame($valuation, $data['valuation']);
		self::assertArrayNotHasKey('ageing', $data);

	}//end testReportValidReturns200WithoutAgeing()

	/**
	 * ageing=1 with both sku and warehouse adds the ageing bucket breakdown.
	 *
	 * @return void
	 */
	public function testReportWithAgeingIncludesBuckets(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'as_of' => '2026-06-30',
				'sku' => 'SKU-100',
				'warehouse' => 'WH-MAIN',
				'ageing' => '1',
			]
		);
		$buckets = ['0-30' => 12, '31-60' => 4, '61-90' => 0, '90+' => 1];
		$this->report->method('valuationAsOf')->willReturn(['totalCents' => 500000]);
		$this->report->expects($this->once())->method('ageing')->willReturn($buckets);

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($buckets, $response->getData()['ageing']);

	}//end testReportWithAgeingIncludesBuckets()

	/**
	 * ageing=1 WITHOUT a warehouse does not call the ageing service — the
	 * breakdown is only defined for a single (sku, warehouse) pair.
	 *
	 * @return void
	 */
	public function testReportAgeingIgnoredWithoutWarehouse(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'as_of' => '2026-06-30',
				'sku' => 'SKU-100',
				'ageing' => '1',
			]
		);
		$this->report->method('valuationAsOf')->willReturn(['totalCents' => 1]);
		$this->report->expects($this->never())->method('ageing');

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertArrayNotHasKey('ageing', $response->getData());

	}//end testReportAgeingIgnoredWithoutWarehouse()

	/**
	 * A service failure yields HTTP 500 and leaks no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testReportServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administration_id' => 'adm-1', 'as_of' => '2026-06-30']);
		$this->report->method('valuationAsOf')->willThrowException(new \RuntimeException('replay exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->report();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'replay exploded',
			(string)json_encode($response->getData())
		);

	}//end testReportServiceFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
