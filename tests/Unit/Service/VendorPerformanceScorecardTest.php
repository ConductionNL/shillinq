<?php

/**
 * Characterisation of VendorPerformanceAggregation::calculateMonthlyScore():
 * the persisted scorecard's fields and key order, the order of the
 * OpenRegister reads and writes, the input guards and the re-run that
 * reuses an existing scorecard id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins calculateMonthlyScore() before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\VendorPerformanceAggregation
 * @uses   \OCA\Shillinq\Service\VendorScorecardRun
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VendorPerformanceScorecardTest extends TestCase {

	/**
	 * The reads and writes, in order.
	 *
	 * @var array<int, string>
	 */
	private const EXPECTED_CALLS_LOW = [
		'find PurchaseOrder {"supplierId":"vendor-002","administrationId":"adm-1"}',
		'find SupplierInvoice {"supplierId":"vendor-002","administrationId":"adm-1"}',
		'find GoodsReceiptNote {"administrationId":"adm-1"}',
		'find ThreeWayMatch {"administrationId":"adm-1"}',
		'find GoodsReceiptLine {"grnId":"grn-3","administrationId":"adm-1"}',
		'find PurchaseOrderLine {"id":"pol-3","administrationId":"adm-1"}',
		'find GoodsReceiptLine {"grnId":"grn-4","administrationId":"adm-1"}',
		'find PurchaseOrderLine {"id":"pol-4","administrationId":"adm-1"}',
		'find VendorPerformance {"supplierId":"vendor-002","period":"2026-04","administrationId":"adm-1"}',
		'find VendorPerformance {"supplierId":"vendor-002","period":"2026-05","administrationId":"adm-1"}',
		'save VendorPerformance',
	];

	/**
	 * The reads and writes, in order.
	 *
	 * @var array<int, string>
	 */
	private const EXPECTED_CALLS_HIGH = [
		'find PurchaseOrder {"supplierId":"vendor-001","administrationId":"adm-1"}',
		'find SupplierInvoice {"supplierId":"vendor-001","administrationId":"adm-1"}',
		'find GoodsReceiptNote {"administrationId":"adm-1"}',
		'find ThreeWayMatch {"administrationId":"adm-1"}',
		'find GoodsReceiptLine {"grnId":"grn-1","administrationId":"adm-1"}',
		'find PurchaseOrderLine {"id":"pol-1","administrationId":"adm-1"}',
		'find GoodsReceiptLine {"grnId":"grn-2","administrationId":"adm-1"}',
		'find PurchaseOrderLine {"id":"pol-2","administrationId":"adm-1"}',
		'find VendorPerformance {"supplierId":"vendor-001","period":"2026-04","administrationId":"adm-1"}',
		'find VendorPerformance {"supplierId":"vendor-001","period":"2026-05","administrationId":"adm-1"}',
		'find VendorPerformance {"supplierId":"vendor-001","administrationId":"adm-1"}',
		'save VendorPerformance',
		'find ToleranceProfile {"scope":"supplier","scopeReference":"vendor-001","status":"active","administrationId":"adm-1"}',
		'save ToleranceProfile',
	];

	/**
	 * Calls the service made on the stub, as "op schema".
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build a service on a recording in-memory stub.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return VendorPerformanceAggregation
	 */
	private function makeService(InMemoryObjectService $os): VendorPerformanceAggregation {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		return new VendorPerformanceAggregation(
			appConfig: $appConfig,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * A stub that records every find and save with its schema.
	 *
	 * @return InMemoryObjectService
	 */
	private function recordingStub(): InMemoryObjectService {
		$calls = &$this->calls;
		return new class($calls) extends InMemoryObjectService {
			/**
			 * @var array<int, string>
			 */
			private array $log;

			/**
			 * @var string
			 */
			private string $current = '';

			/**
			 * @var string The schema whose saves fail.
			 */
			public string $failOn = '';

			/**
			 * @param array<int, string> $log The shared call log.
			 */
			public function __construct(array &$log) {
				$this->log = &$log;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->current = $schema;
				return parent::setSchema($schema);
			}

			/**
			 * @param array<string, mixed> $args The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $args = []): array {
				$this->log[] = 'find ' . $this->current . ' ' . json_encode($args['filters'] ?? []);
				return parent::findAll($args);
			}

			/**
			 * @param array<string, mixed> $data The record.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $data): array {
				$this->log[] = 'save ' . $this->current;
				if ($this->current === $this->failOn) {
					throw new RuntimeException('store down');
				}

				return parent::saveObject($data);
			}
		};

	}//end recordingStub()

	private function seed(InMemoryObjectService $os): void {
		$os->seed(
			'PurchaseOrder',
			[
				['id' => 'po-1', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-10'],
				['id' => 'po-2', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-20'],
				['id' => 'po-3', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-12'],
				['id' => 'po-4', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'expectedDeliveryDate' => '2026-05-22'],
			]
		);
		$os->seed(
			'PurchaseOrderLine',
			[
				['id' => 'pol-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 10],
				['id' => 'pol-2', 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 20],
				['id' => 'pol-3', 'poId' => 'po-3', 'administrationId' => 'adm-1', 'quantityOrdered' => 30],
				['id' => 'pol-4', 'poId' => 'po-4', 'administrationId' => 'adm-1', 'quantityOrdered' => 40],
			]
		);
		$os->seed(
			'GoodsReceiptNote',
			[
				['id' => 'grn-1', 'poIds' => ['po-1'], 'receivedAt' => '2026-05-09T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-2', 'poIds' => ['po-2'], 'receivedAt' => '2026-05-20T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-3', 'poIds' => ['po-3'], 'receivedAt' => '2026-05-15T10:00:00Z', 'administrationId' => 'adm-1'],
				['id' => 'grn-4', 'poIds' => ['po-4'], 'receivedAt' => '2026-05-22T10:00:00Z', 'administrationId' => 'adm-1'],
			]
		);
		$os->seed(
			'GoodsReceiptLine',
			[
				['grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-2', 'poLineId' => 'pol-2', 'quantityReceived' => 20, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-3', 'poLineId' => 'pol-3', 'quantityReceived' => 28, 'administrationId' => 'adm-1'],
				['grnId' => 'grn-4', 'poLineId' => 'pol-4', 'quantityReceived' => 40, 'administrationId' => 'adm-1'],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				['id' => 'inv-1', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-11'],
				['id' => 'inv-2', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-21'],
				['id' => 'inv-3', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-16'],
				['id' => 'inv-4', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'invoiceDate' => '2026-05-23'],
			]
		);
		$os->seed(
			'ThreeWayMatch',
			[
				[
					'invoiceId' => 'inv-1',
					'matchStatus' => 'auto_approved',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-11T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-2',
					'matchStatus' => 'auto_approved',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-21T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-3',
					'matchStatus' => 'exception_price',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-16T12:00:00Z',
					'resolutionAction' => 'credit_note_requested',
					'resolvedAt' => '2026-05-20T12:00:00Z',
				],
				[
					'invoiceId' => 'inv-4',
					'matchStatus' => 'exception_price',
					'administrationId' => 'adm-1',
					'createdAt' => '2026-05-23T12:00:00Z',
					'resolutionAction' => 'supplier_contacted',
					'resolvedAt' => '2026-05-25T12:00:00Z',
				],
			]
		);
		// Seed an older scorecard for vendor-001 so the bootstrap window is met.
		$os->seed(
			'VendorPerformance',
			[
				[
					'id' => 'vp-vendor1-old',
					'supplierId' => 'vendor-001',
					'administrationId' => 'adm-1',
					'period' => '2020-01',
					'overallScore' => 9700,
				],
			]
		);
		// Seed a supplier-scoped tolerance profile for vendor-001 so the
		// auto-relax invocation has something to nudge.
		$os->seed(
			'ToleranceProfile',
			[
				[
					'id' => 'tp-vendor-001',
					'profileId' => 'TP-VENDOR-001',
					'scope' => 'supplier',
					'scopeReference' => 'vendor-001',
					'status' => 'active',
					'administrationId' => 'adm-1',
					'priceTolerancePercentage' => 250,
					'quantityTolerancePercentage' => 100,
					'dateToleranceDays' => 3,
				],
			]
		);

	}//end seed()

	/**
	 * The scorecard of the low-scoring supplier, field by field and in order,
	 * and the reads and writes that produced it.
	 *
	 * @return void
	 */
	public function testScorecardAndCallOrder(): void {
		$os = $this->recordingStub();
		$this->seed($os);
		$this->calls = [];

		$card = $this->makeService($os)->calculateMonthlyScore('adm-1', 'vendor-002', '2026-05');

		self::assertSame(
			[
				'supplierId' => 'vendor-002',
				'period' => '2026-05',
				'onTimeDeliveryRate' => 5000,
				'quantityAccuracyRate' => 5000,
				'priceAccuracyRate' => 0,
				'invoiceAccuracyRate' => 0,
				'disputeCount' => 2,
				'averageResolutionDays' => 3,
				'overallScore' => 3500,
				'scoreTrend' => 'stable',
				'automatedReviewEligible' => false,
				'administrationId' => 'adm-1',
				'id' => 'auto-9',
			],
			$card
		);
		self::assertSame(self::EXPECTED_CALLS_LOW, $this->calls);

	}//end testScorecardAndCallOrder()

	/**
	 * The eligible supplier: flagged, saved, and its tolerance profile relaxed
	 * after the save.
	 *
	 * @return void
	 */
	public function testEligibleScorecardRelaxesAfterSaving(): void {
		$os = $this->recordingStub();
		$this->seed($os);
		$this->calls = [];

		$card = $this->makeService($os)->calculateMonthlyScore('adm-1', 'vendor-001', '2026-05');

		self::assertTrue($card['automatedReviewEligible']);
		self::assertSame(10000, $card['overallScore']);
		self::assertSame(self::EXPECTED_CALLS_HIGH, $this->calls);

	}//end testEligibleScorecardRelaxesAfterSaving()

	/**
	 * A failing tolerance relaxation is logged and does not undo the saved
	 * scorecard.
	 *
	 * @return void
	 */
	public function testFailedRelaxationIsLoggedAndSwallowed(): void {
		$os = $this->recordingStub();
		$this->seed($os);
		$os->failOn = 'ToleranceProfile';

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'VendorPerformanceAggregation: failed to persist object',
			['schema' => 'ToleranceProfile', 'exception' => 'store down']
		);
		$logger->expects(self::once())->method('info')->with(
			'VendorPerformanceAggregation: tolerance relaxation skipped',
			['supplierId' => 'vendor-001', 'exception' => 'Failed to persist ToleranceProfile']
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$service = new VendorPerformanceAggregation(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

		$card = $service->calculateMonthlyScore('adm-1', 'vendor-001', '2026-05');

		self::assertTrue($card['automatedReviewEligible']);

	}//end testFailedRelaxationIsLoggedAndSwallowed()

	/**
	 * A re-run for the same tuple keeps the scorecard id and reads the trend
	 * against the prior period.
	 *
	 * @return void
	 */
	public function testRerunReusesTheScorecardIdAndTrendsAgainstPriorPeriod(): void {
		$os = $this->recordingStub();
		$this->seed($os);
		$os->seed(
			'VendorPerformance',
			[
				['id' => 'vp-april', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'period' => '2026-04', 'overallScore' => 6000],
				['id' => 'vp-may', 'supplierId' => 'vendor-002', 'administrationId' => 'adm-1', 'period' => '2026-05', 'overallScore' => 1],
			]
		);

		$card = $this->makeService($os)->calculateMonthlyScore('adm-1', 'vendor-002', '2026-05');

		self::assertSame('vp-may', $card['id']);
		self::assertSame('declining', $card['scoreTrend']);
		self::assertSame('id', array_key_last($card));

	}//end testRerunReusesTheScorecardIdAndTrendsAgainstPriorPeriod()

	/**
	 * Inputs, and the exception message each one is refused with.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no administration' => ['', 'vendor-001', '2026-05', 'administrationId is required'],
			'no supplier'       => ['adm-1', '', '2026-05', 'supplierId is required'],
			'both missing'      => ['', '', 'bad', 'administrationId is required'],
		];

	}//end refusals()

	/**
	 * The guards refuse before any read.
	 *
	 * @param string $administrationId The administration.
	 * @param string $supplierId       The supplier.
	 * @param string $period           The period.
	 * @param string $message          The expected message.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
	public function testGuardsRefuseBeforeReading(string $administrationId, string $supplierId, string $period, string $message): void {
		$os = $this->recordingStub();
		$this->calls = [];
		try {
			$this->makeService($os)->calculateMonthlyScore($administrationId, $supplierId, $period);
			self::fail('expected a refusal');
		} catch (RuntimeException $e) {
			self::assertSame($message, $e->getMessage());
		}

		self::assertSame([], $this->calls);

	}//end testGuardsRefuseBeforeReading()

	/**
	 * A malformed period is refused before any read.
	 *
	 * @return void
	 */
	public function testMalformedPeriodIsRefusedBeforeReading(): void {
		$os = $this->recordingStub();
		$this->calls = [];
		$this->expectException(RuntimeException::class);
		try {
			$this->makeService($os)->calculateMonthlyScore('adm-1', 'vendor-001', 'May 2026');
		} finally {
			self::assertSame([], $this->calls);
		}

	}//end testMalformedPeriodIsRefusedBeforeReading()

}//end class
