<?php

/**
 * Unit tests for GRIRClearingListener.
 *
 * The `testGoodsReceiptAcceptThenInvoiceMatchPostsBalancedGRIRAccrual` test
 * is the correctness proof required by tasks.md Task 3: on the pre-change
 * codebase, `GRIRClearingService::createGRIRPosting()`/`settleGRIRPosting()`
 * had zero callers — this test could not even be written
 * (`GRIRClearingListener` did not exist). Post-change, it demonstrates the
 * full connection end-to-end using REAL `GoodsReceiptNoteService`,
 * `ThreeWayMatchingEngine`, `ToleranceProfileService`,
 * `SupplierInvoiceService`, and `GRIRClearingService` instances (only
 * `ObjectService` is faked, in-memory) — proving a GRN accept posts the
 * GR/IR clearing entry and a subsequent matched invoice posts the
 * settlement entry, netting the GR/IR clearing account to zero.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grir-accrual-wiring/specs/grir-accrual-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\GRIRClearingListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for GRIRClearingListener dispatch + the full receipt-accept ->
 * clearing -> match -> settlement wiring proof.
 */
class GRIRClearingListenerTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Mock GRIRClearingService.
	 *
	 * @var GRIRClearingService&MockObject
	 */
	private GRIRClearingService&MockObject $grirClearingService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test (mocked-service tests).
	 *
	 * @var GRIRClearingListener
	 */
	private GRIRClearingListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->grirClearingService = $this->createMock(GRIRClearingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new GRIRClearingListener(
			grirClearingService: $this->grirClearingService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity stub for the given schema + payload.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $payload Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $payload): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * A GoodsReceiptNote reaching `accepted` forwards to
	 * postGRIRForGoodsReceiptAccept() (REQ-001).
	 *
	 * @return void
	 */
	public function testGoodsReceiptNoteAcceptedForwardsToClearingPosting(): void {
		$payload = ['id' => 'grn-1', 'administrationId' => 'adm-1'];
		$entity = $this->entity('GoodsReceiptNote', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'accepted', 'getSchema' => 'GoodsReceiptNote']
		);

		$this->grirClearingService->expects(self::once())
			->method('postGRIRForGoodsReceiptAccept')
			->with('adm-1', $payload);
		$this->grirClearingService->expects(self::never())->method('postGRIRForServiceReceiptAccept');
		$this->grirClearingService->expects(self::never())->method('settleGRIRForMatchedInvoice');

		$this->listener->handle($event);

	}//end testGoodsReceiptNoteAcceptedForwardsToClearingPosting()

	/**
	 * A SvcReceipt reaching `accepted` forwards to
	 * postGRIRForServiceReceiptAccept() (REQ-002).
	 *
	 * @return void
	 */
	public function testSvcReceiptAcceptedForwardsToClearingPosting(): void {
		$payload = ['id' => 'svr-1', 'administrationId' => 'adm-1'];
		$entity = $this->entity('SvcReceipt', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'accepted', 'getSchema' => 'SvcReceipt']
		);

		$this->grirClearingService->expects(self::once())
			->method('postGRIRForServiceReceiptAccept')
			->with('adm-1', $payload);
		$this->grirClearingService->expects(self::never())->method('postGRIRForGoodsReceiptAccept');

		$this->listener->handle($event);

	}//end testSvcReceiptAcceptedForwardsToClearingPosting()

	/**
	 * A SupplierInvoice reaching `matched` forwards to
	 * settleGRIRForMatchedInvoice() (REQ-003).
	 *
	 * @return void
	 */
	public function testSupplierInvoiceMatchedForwardsToSettlement(): void {
		$payload = ['id' => 'inv-1', 'administrationId' => 'adm-1'];
		$entity = $this->entity('SupplierInvoice', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'matched', 'getSchema' => 'SupplierInvoice']
		);

		$this->grirClearingService->expects(self::once())
			->method('settleGRIRForMatchedInvoice')
			->with('adm-1', 'inv-1');

		$this->listener->handle($event);

	}//end testSupplierInvoiceMatchedForwardsToSettlement()

	/**
	 * Other transitions on the same schemas (e.g. `rejected`, `exception`)
	 * are ignored.
	 *
	 * @return void
	 */
	public function testOtherTransitionsIgnored(): void {
		$entity = $this->entity('GoodsReceiptNote', ['id' => 'grn-1', 'administrationId' => 'adm-1']);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'rejected', 'getSchema' => 'GoodsReceiptNote']
		);

		$this->grirClearingService->expects(self::never())->method('postGRIRForGoodsReceiptAccept');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testOtherTransitionsIgnored()

	/**
	 * An unrelated schema is ignored without touching the service.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIgnored(): void {
		$entity = $this->entity('StockMove', ['id' => 'sm-1', 'administrationId' => 'adm-1']);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'accepted', 'getSchema' => 'StockMove']
		);

		$this->grirClearingService->expects(self::never())->method('postGRIRForGoodsReceiptAccept');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testUnrelatedSchemaIgnored()

	/**
	 * An object payload with no administrationId is ignored (nothing to
	 * scope the posting to).
	 *
	 * @return void
	 */
	public function testMissingAdministrationIdIgnored(): void {
		$entity = $this->entity('GoodsReceiptNote', ['id' => 'grn-1']);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'accepted', 'getSchema' => 'GoodsReceiptNote']
		);

		$this->grirClearingService->expects(self::never())->method('postGRIRForGoodsReceiptAccept');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testMissingAdministrationIdIgnored()

	/**
	 * A downstream exception is logged but never propagates (fail-soft,
	 * REQ-004, mirrors DeliveryDispatchListener's contract).
	 *
	 * @return void
	 */
	public function testDownstreamExceptionIsFailSoft(): void {
		$payload = ['id' => 'grn-1', 'administrationId' => 'adm-1'];
		$entity = $this->entity('GoodsReceiptNote', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'accepted', 'getSchema' => 'GoodsReceiptNote']
		);

		$this->grirClearingService->method('postGRIRForGoodsReceiptAccept')
			->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects(self::once())->method('error');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testDownstreamExceptionIsFailSoft()

	/**
	 * CORRECTNESS PROOF (tasks.md Task 3): a GoodsReceiptNote accepting one
	 * line posts a balanced GR/IR clearing GLTransaction, and a
	 * subsequently matched SupplierInvoice posts a balanced settlement
	 * GLTransaction that nets the GR/IR clearing account to zero. Only
	 * ObjectService is faked in-memory; every business-logic class under
	 * test is real.
	 *
	 * @return void
	 */
	public function testGoodsReceiptAcceptThenInvoiceMatchPostsBalancedGRIRAccrual(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrder',
			[
				[
					'id' => 'po-1',
					'poNumber' => 'PO-001',
					'supplierId' => 'vendor-001',
					'costCenter' => 'FAC-2026',
					'projectCode' => 'PRJ-CHAIR',
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-1',
					'poId' => 'po-1',
					'lineNumber' => 1,
					'productOrServiceCode' => 'CHAIR-ERG-1',
					'quantityOrdered' => 180.0,
					'unitPrice' => 10278,
					'glAccount' => '1200',
					'costCenter' => 'FAC-2026',
					'projectCode' => 'PRJ-CHAIR',
					'vatRate' => 2100,
					'vatAmount' => 388508,
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			'GoodsReceiptNote',
			[
				[
					'id' => 'grn-1',
					'grnNumber' => 'GRN-2026-adm-1-000001',
					'poIds' => ['po-1'],
					'receivedAt' => '2026-06-01',
					'statusCode' => 'quality_checked',
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			'GoodsReceiptLine',
			[
				[
					'id' => 'grnline-1',
					'grnId' => 'grn-1',
					'poLineId' => 'poline-1',
					'quantityReceived' => 180.0,
					'quantityAccepted' => 180.0,
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			'ToleranceProfile',
			[
				[
					'profileId' => 'TP-GLOBAL',
					'scope' => 'global',
					'priceToleranceAmount' => 1000,
					'priceTolerancePercentage' => 50,
					'quantityTolerancePercentage' => 100,
					'dateToleranceDays' => 3,
					'status' => 'active',
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-1',
					'invoiceNumber' => 'INV-ERS-2026-00445',
					'supplierId' => 'vendor-001',
					'statusCode' => 'received',
					'totalExclVat' => 1850040,
					'totalVat' => 388508,
					'totalInclVat' => 2238548,
					'administrationId' => 'adm-1',
					'matchedPoIds' => ['po-1'],
					'lines' => [
						[
							'lineNumber' => 1,
							'productCode' => 'CHAIR-ERG-1',
							'quantity' => 180.0,
							'unitPrice' => 10278,
							'lineExtension' => 1850040,
							'vatRate' => 0.21,
						],
					],
				],
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return match ($key) {
					GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
					GRIRClearingService::CFG_ACCOUNTS_PAYABLE_ACCOUNT => '4400',
					GRIRClearingService::CFG_VAT_PAYABLE_ACCOUNT => '2100',
					default => 'shillinq',
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);
		$administrationContext->method('currentUserId')->willReturn('receiver-bot');

		// Real business-logic classes — nothing about receipt acceptance,
		// matching, or GR/IR posting is mocked or reimplemented.
		$grnService = new GoodsReceiptNoteService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

		$grirService = new GRIRClearingService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

		$grirListener = new GRIRClearingListener(grirClearingService: $grirService, logger: $logger);

		// Step 1: accept the GRN -> expect zero GLTransactions before, one
		// balanced clearing GLTransaction after (REQ-001).
		self::assertCount(0, $os->dump('GLTransaction'), 'no GL entries exist before the GRN is accepted');

		$acceptedGrn = $grnService->acceptGRN(administrationId: 'adm-1', grnId: 'grn-1');
		self::assertSame('accepted', $acceptedGrn['statusCode']);

		$grnEvent = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			[
				'getObject' => $this->entity('GoodsReceiptNote', $acceptedGrn),
				'getTo' => 'accepted',
				'getSchema' => 'GoodsReceiptNote',
			]
		);
		$grirListener->handle($grnEvent);

		$txnsAfterClearing = $os->dump('GLTransaction');
		self::assertCount(1, $txnsAfterClearing, 'GRN accept posted exactly one clearing GLTransaction');

		$clearingLines = $os->dump('GLLine');
		self::assertCount(2, $clearingLines, 'the clearing GLTransaction has a debit + credit line');

		$clearingDebit = 0;
		$clearingCredit = 0;
		foreach ($clearingLines as $line) {
			$cents = (int)round(((float)$line['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
			if ($line['side'] === 'debit') {
				$clearingDebit += $cents;
			} else {
				$clearingCredit += $cents;
			}
		}

		self::assertSame(1850040, $clearingDebit, 'GRN accept debits the PO-line account (1200) for the line value');
		self::assertSame(1850040, $clearingCredit, 'GRN accept credits GR/IR clearing (2910) for the line value');

		// Step 2: evaluate the 3-way match (REAL ThreeWayMatchingEngine) ->
		// auto_approved, invoice transitions received -> matching -> matched.
		$tolerance = new ToleranceProfileService(container: $container, appConfig: $appConfig, logger: $logger);
		$invoiceService = new SupplierInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);
		$matchingEngine = new ThreeWayMatchingEngine(
			appConfig: $appConfig,
			toleranceService: $tolerance,
			invoiceService: $invoiceService,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

		$matchResult = $matchingEngine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');
		self::assertSame(ThreeWayMatchingEngine::STATUS_AUTO_APPROVED, $matchResult['matchStatus']);

		$matchedInvoice = null;
		foreach ($os->dump('SupplierInvoice') as $row) {
			if (($row['id'] ?? null) === 'inv-1') {
				$matchedInvoice = $row;
			}
		}

		self::assertNotNull($matchedInvoice);
		self::assertSame('matched', $matchedInvoice['statusCode'], 'evaluateMatch transitioned the invoice to matched');

		// Step 3: feed the invoice's matching -> matched transition into the
		// SAME listener -> settleGRIRForMatchedInvoice() (REQ-003).
		$invoiceEvent = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			[
				'getObject' => $this->entity('SupplierInvoice', $matchedInvoice),
				'getTo' => 'matched',
				'getSchema' => 'SupplierInvoice',
			]
		);
		$grirListener->handle($invoiceEvent);

		$allTxns = $os->dump('GLTransaction');
		self::assertCount(2, $allTxns, 'clearing + settlement GLTransactions both exist');

		$allLines = $os->dump('GLLine');
		$clearingDebit = 0;
		$clearingCredit = 0;
		foreach ($allLines as $line) {
			if ($line['accountNumber'] !== '2910') {
				continue;
			}

			$cents = (int)round(((float)$line['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
			if ($line['side'] === 'debit') {
				$clearingDebit += $cents;
			} else {
				$clearingCredit += $cents;
			}
		}

		self::assertSame(1850040, $clearingDebit, 'settlement debits GR/IR clearing for the same amount the GRN credited');
		self::assertSame(1850040, $clearingCredit, 'GRN-accept credited GR/IR clearing for the line amount');
		self::assertSame(0, ($clearingDebit - $clearingCredit), 'GR/IR clearing account nets to zero after full settlement');

	}//end testGoodsReceiptAcceptThenInvoiceMatchPostsBalancedGRIRAccrual()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
