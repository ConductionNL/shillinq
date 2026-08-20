<?php

/**
 * Unit tests for GRIRClearingService (member 09 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-009:
 *  - createGRIRPosting() materialises a balanced 2-line GLTransaction
 *    on GRN accept (DR PO-line gl_account / CR GR/IR clearing);
 *  - settleGRIRPosting() materialises a balanced 3-line GLTransaction
 *    on ThreeWayMatch approval (DR GR/IR / CR AP + VAT);
 *  - both postings preserve costCenter and projectCode from the PO
 *    line and stamp the ThreeWayMatch id on the header;
 *  - the per-ToleranceProfile grIrClearingAccount override is honoured;
 *  - missing GL accounts log a WARNING and return posted=false (fail-soft).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * GRIRClearingService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GRIRClearingServiceTest extends TestCase {
	/**
	 * Build a service wired against the supplied stub + appConfig overrides.
	 *
	 * @param InMemoryObjectService $os In-memory OR stub.
	 * @param array<string,string> $config App-config overrides.
	 * @param LoggerInterface|null $logger Optional logger to assert against.
	 * @param array<int,string> $accessibleAdministrations Tenants the caller may access.
	 *
	 * @return GRIRClearingService
	 */
	private function makeService(
		InMemoryObjectService $os,
		array $config = [],
		?LoggerInterface $logger = null,
		array $accessibleAdministrations = ['adm-1'],
	): GRIRClearingService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				static $captured = [];
				$captured[$key] = $default;
				return $default;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		$logger ??= $this->createStub(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new GRIRClearingService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * REQ-PO3W-009 acceptance scenario, clearing leg: a GRN accepting 180
	 * units at PO-line glAccount=1200 + unitPrice=10278 cents materialises
	 * DR 1200 / CR 2910 for the exact accepted line amount (rounded HALF-UP)
	 * and preserves costCenter + projectCode.
	 *
	 * @return void
	 */
	public function testCreateGRIRPostingMaterialisesBalancedClearingLines(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-1',
					'poId' => 'po-1',
					'administrationId' => 'adm-1',
					'unitPrice' => 10278,
					'glAccount' => '1200',
					'costCenter' => 'FAC-2026',
					'projectCode' => 'PRJ-CHAIR',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
			]
		);

		$grn = ['id' => 'grn-1', 'grnNumber' => 'GRN-2026-adm-1-000001', 'receivedAt' => '2026-06-01T09:00:00Z'];
		$grnLine = ['id' => 'grnline-1', 'poLineId' => 'poline-1', 'quantityAccepted' => 180.0];

		$result = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: $grn,
			grnLine: $grnLine,
			threeWayMatchId: null
		);

		self::assertTrue($result['posted']);
		// 180 * 10278 cents = 1850040 cents (no rounding noise).
		self::assertSame(1850040, $result['amountCents']);

		$txns = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(1, $txns);
		self::assertSame('GRIR', $txns[0]['journalCode']);
		self::assertSame('adm-1', $txns[0]['administrationId']);
		self::assertSame('2026-Q2', $txns[0]['periodId']);
		self::assertSame('EUR', $txns[0]['currency']);

		$lines = $os->setSchema('GLLine')->findAll();
		self::assertCount(2, $lines);

		$debits = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'debit'));
		$credits = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertCount(1, $debits);
		self::assertCount(1, $credits);

		// Inventory DR PO-line glAccount (1200), CR 2910 GR/IR clearing.
		self::assertSame('1200', $debits[0]['accountNumber']);
		self::assertSame('2910', $credits[0]['accountNumber']);
		self::assertSame(18500.40, (float)$debits[0]['amount']);
		self::assertSame(18500.40, (float)$credits[0]['amount']);

		// CostCenter + projectCode are preserved on BOTH lines.
		foreach ($lines as $line) {
			self::assertSame('FAC-2026', $line['costCenter']);
			self::assertSame('FAC-2026', $line['costCenterCode']);
			self::assertSame('PRJ-CHAIR', $line['projectCode']);
		}

		// Balance invariant: sum of debits == sum of credits.
		$debitCents = (int)round((((float)$debits[0]['amount']) * 100.0), 0, PHP_ROUND_HALF_UP);
		$creditCents = (int)round((((float)$credits[0]['amount']) * 100.0), 0, PHP_ROUND_HALF_UP);
		self::assertSame($debitCents, $creditCents);

	}//end testCreateGRIRPostingMaterialisesBalancedClearingLines()

	/**
	 * REQ-PO3W-009: when a ToleranceProfile carrying a `grIrClearingAccount`
	 * override is referenced by the PO line, the clearing posting credits the
	 * override account rather than the global default 2910.
	 *
	 * @return void
	 */
	public function testToleranceProfileOverridesGRIRAccount(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-2',
					'poId' => 'po-2',
					'administrationId' => 'adm-1',
					'unitPrice' => 5000,
					'glAccount' => '1300',
					'costCenter' => 'IT-2026',
					'projectCode' => 'PRJ-IT',
					'toleranceProfileId' => 'tp-strict',
				],
			]
		);
		$os->seed(
			'ToleranceProfile',
			[
				[
					'id' => 'tp-strict',
					'administrationId' => 'adm-1',
					'grIrClearingAccount' => '2911',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
			]
		);

		$result = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'grn-2', 'grnNumber' => 'GRN-Z', 'receivedAt' => '2026-04-01'],
			grnLine: ['id' => 'gl-2', 'poLineId' => 'poline-2', 'quantityAccepted' => 4.0]
		);

		self::assertTrue($result['posted']);

		$lines = $os->setSchema('GLLine')->findAll();
		$credits = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertCount(1, $credits);
		// Per-profile override beats the global 2910.
		self::assertSame('2911', $credits[0]['accountNumber']);

	}//end testToleranceProfileOverridesGRIRAccount()

	/**
	 * REQ-PO3W-009 settlement leg: when the ThreeWayMatch is approved, the
	 * service materialises DR GR/IR / CR AP + VAT, balanced, with cost
	 * dimensions preserved from the first matched PO line.
	 *
	 * @return void
	 */
	public function testSettleGRIRPostingMaterialisesBalancedSettlementLines(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-3',
					'poId' => 'po-3',
					'administrationId' => 'adm-1',
					'glAccount' => '1200',
					'costCenter' => 'FAC-2026',
					'projectCode' => 'PRJ-CHAIR',
				],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-1',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-ERS-2026-00445',
					'totalExclVat' => 1850000,
					'totalVat' => 388500,
					'totalInclVat' => 2238500,
					'invoiceDate' => '2026-06-05',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
				GRIRClearingService::CFG_ACCOUNTS_PAYABLE_ACCOUNT => '4400',
				GRIRClearingService::CFG_VAT_PAYABLE_ACCOUNT => '2100',
			]
		);

		$match = [
			'id' => 'match-1',
			'administrationId' => 'adm-1',
			'invoiceId' => 'inv-1',
			'matchedPoIds' => ['po-3'],
			'matchedPoLineIds' => ['poline-3'],
			'matchStatus' => 'auto_approved',
		];

		$result = $service->settleGRIRPosting(administrationId: 'adm-1', threeWayMatch: $match);

		self::assertTrue($result['posted']);
		// Settlement amountCents reports the total debit (== total credit):
		// DR clearing 1850000 + DR VAT 388500 = CR AP 2238500.
		self::assertSame(2238500, $result['amountCents']);

		$txns = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(1, $txns);
		self::assertSame('GRIR-SETTLE', $txns[0]['journalCode']);
		self::assertSame('match-1', $txns[0]['threeWayMatchId']);
		self::assertSame('match-1', $txns[0]['sourceReference']);

		$lines = $os->setSchema('GLLine')->findAll();
		self::assertCount(3, $lines);

		$byAccount = [];
		foreach ($lines as $line) {
			$byAccount[$line['accountNumber']] = $line;
		}

		// DR GR/IR clearing for the excl-VAT base (closes the receipt-side
		// clearing accumulated at GRN time).
		self::assertSame('debit', $byAccount['2910']['side']);
		self::assertSame(18500.0, (float)$byAccount['2910']['amount']);

		// DR VAT for the VAT amount (input VAT we'll reclaim on the BTW
		// return).
		self::assertSame('debit', $byAccount['2100']['side']);
		self::assertSame(3885.0, (float)$byAccount['2100']['amount']);

		// CR Accounts Payable for the incl-VAT total (what we owe supplier).
		self::assertSame('credit', $byAccount['4400']['side']);
		self::assertSame(22385.0, (float)$byAccount['4400']['amount']);

		// All lines preserve cost dimensions from the first matched PO line.
		foreach ($lines as $line) {
			self::assertSame('FAC-2026', $line['costCenter']);
			self::assertSame('PRJ-CHAIR', $line['projectCode']);
		}

		// Balance invariant: sum of debits == sum of credits.
		$clearingCents = (int)round(((float)$byAccount['2910']['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
		$vatCents = (int)round(((float)$byAccount['2100']['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
		$debitCents = ($clearingCents + $vatCents);
		$creditCents = (int)round(((float)$byAccount['4400']['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
		self::assertSame($debitCents, $creditCents);

	}//end testSettleGRIRPostingMaterialisesBalancedSettlementLines()

	/**
	 * REQ-PO3W-009 fail-soft: when neither the PO-line glAccount nor the
	 * fallback inventory_account is set, the clearing posting is skipped,
	 * a WARNING is logged, and no GL rows are written.
	 *
	 * @return void
	 */
	public function testMissingDebitAccountLogsAndReturnsUnposted(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-4',
					'poId' => 'po-4',
					'administrationId' => 'adm-1',
					'unitPrice' => 1000,
				],
			]
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('GL accounts not configured'),
				$this->anything()
			);

		$service = $this->makeService(
			os: $os,
			config: ['register' => 'shillinq'],
			logger: $logger
		);

		$result = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'g', 'grnNumber' => 'GRN', 'receivedAt' => '2026-06-01'],
			grnLine: ['id' => 'gl', 'poLineId' => 'poline-4', 'quantityAccepted' => 10.0]
		);

		self::assertFalse($result['posted']);
		self::assertSame(0, count($os->setSchema('GLTransaction')->findAll()));
		self::assertSame(0, count($os->setSchema('GLLine')->findAll()));

	}//end testMissingDebitAccountLogsAndReturnsUnposted()

	/**
	 * Zero accepted quantity is a no-op (no GL posting).
	 *
	 * @return void
	 */
	public function testZeroAcceptedQuantityIsNoOp(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-zero',
					'poId' => 'po-zero',
					'administrationId' => 'adm-1',
					'unitPrice' => 5000,
					'glAccount' => '1200',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: ['register' => 'shillinq']
		);

		$result = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'g', 'grnNumber' => 'GRN', 'receivedAt' => '2026-06-01'],
			grnLine: ['id' => 'gl', 'poLineId' => 'poline-zero', 'quantityAccepted' => 0.0]
		);

		self::assertFalse($result['posted']);
		self::assertCount(0, $os->setSchema('GLTransaction')->findAll());

	}//end testZeroAcceptedQuantityIsNoOp()

	/**
	 * Cross-tenant access is masked as administration-not-found (ADR-005).
	 *
	 * @return void
	 */
	public function testCrossTenantAccessIsRejected(): void {
		$os = new InMemoryObjectService();

		$service = $this->makeService(
			os: $os,
			config: ['register' => 'shillinq'],
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');
		$service->createGRIRPosting(
			administrationId: 'adm-2',
			grn: ['id' => 'g'],
			grnLine: ['id' => 'gl', 'poLineId' => 'p', 'quantityAccepted' => 1.0]
		);

	}//end testCrossTenantAccessIsRejected()

	/**
	 * Quantity * unitPrice with sub-cent residue rounds HALF-UP to integer
	 * cents (regression: 3.333 * 9999 = 33326.667 cents -> 33327 cents).
	 *
	 * @return void
	 */
	public function testQuantityTimesUnitPriceRoundsHalfUp(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-r',
					'poId' => 'po-r',
					'administrationId' => 'adm-1',
					'unitPrice' => 9999,
					'glAccount' => '1200',
					'costCenter' => '',
					'projectCode' => '',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
			]
		);

		$result = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'g', 'grnNumber' => 'GRN', 'receivedAt' => '2026-06-01'],
			grnLine: ['id' => 'gl', 'poLineId' => 'poline-r', 'quantityAccepted' => 3.333]
		);

		self::assertTrue($result['posted']);
		// 3333 thousandths * 9999 cents = 33326667; +500 / 1000 = 33327 cents.
		self::assertSame(33327, $result['amountCents']);

	}//end testQuantityTimesUnitPriceRoundsHalfUp()

	/**
	 * Grir-accrual-wiring REQ-001: postGRIRForGoodsReceiptAccept() fans out
	 * over every accepted GoodsReceiptLine for the GRN and skips lines with
	 * quantityAccepted <= 0.
	 *
	 * @return void
	 */
	public function testPostGRIRForGoodsReceiptAcceptFansOutOverAcceptedLinesOnly(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-a',
					'poId' => 'po-a',
					'administrationId' => 'adm-1',
					'unitPrice' => 1000,
					'glAccount' => '1200',
				],
				[
					'id' => 'poline-b',
					'poId' => 'po-b',
					'administrationId' => 'adm-1',
					'unitPrice' => 2000,
					'glAccount' => '1200',
				],
			]
		);
		$os->seed(
			'GoodsReceiptLine',
			[
				[
					'id' => 'grnline-1',
					'grnId' => 'grn-1',
					'poLineId' => 'poline-a',
					'quantityAccepted' => 10.0,
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'grnline-2',
					'grnId' => 'grn-1',
					'poLineId' => 'poline-b',
					'quantityAccepted' => 0.0,
					'administrationId' => 'adm-1',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
			]
		);

		$result = $service->postGRIRForGoodsReceiptAccept(
			administrationId: 'adm-1',
			grn: ['id' => 'grn-1', 'grnNumber' => 'GRN-1', 'receivedAt' => '2026-07-01']
		);

		self::assertSame(1, $result['posted']);
		self::assertSame(1, $result['skipped']);

		$txns = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(1, $txns);

	}//end testPostGRIRForGoodsReceiptAcceptFansOutOverAcceptedLinesOnly()

	/**
	 * Grir-accrual-wiring REQ-002: postGRIRForServiceReceiptAccept()
	 * normalises SvcReceipt's receiptNumber/periodEnd to the
	 * grnNumber/receivedAt shape createGRIRPosting() expects, and fans out
	 * over SvcReceiptLine rows keyed by serviceReceiptId.
	 *
	 * @return void
	 */
	public function testPostGRIRForServiceReceiptAcceptNormalisesFieldsAndFansOut(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-svc',
					'poId' => 'po-svc',
					'administrationId' => 'adm-1',
					'unitPrice' => 5000,
					'glAccount' => '4200',
				],
			]
		);
		$os->seed(
			'SvcReceiptLine',
			[
				[
					'id' => 'svcline-1',
					'serviceReceiptId' => 'svr-1',
					'poLineId' => 'poline-svc',
					'quantityAccepted' => 2.0,
					'administrationId' => 'adm-1',
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT => '2910',
			]
		);

		$result = $service->postGRIRForServiceReceiptAccept(
			administrationId: 'adm-1',
			receipt: ['id' => 'svr-1', 'receiptNumber' => 'SVR-1', 'periodEnd' => '2026-07-05']
		);

		self::assertSame(1, $result['posted']);
		self::assertSame(0, $result['skipped']);

		$txns = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(1, $txns);
		// 2.0 units * 5000 cents = 10000 cents.
		self::assertSame(10000, $result['results'][0]['amountCents']);

	}//end testPostGRIRForServiceReceiptAcceptNormalisesFieldsAndFansOut()

	/**
	 * Grir-accrual-wiring REQ-003: settleGRIRForMatchedInvoice() resolves
	 * the auto_approved/within_tolerance ThreeWayMatch for the invoice and
	 * calls settleGRIRPosting(); it returns posted=false without throwing
	 * when no qualifying match exists.
	 *
	 * @return void
	 */
	public function testSettleGRIRForMatchedInvoiceResolvesApprovedMatch(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-m',
					'poId' => 'po-m',
					'administrationId' => 'adm-1',
					'glAccount' => '1200',
				],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-m',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-M',
					'totalExclVat' => 20000,
					'totalVat' => 4200,
					'totalInclVat' => 24200,
				],
			]
		);
		$os->seed(
			'ThreeWayMatch',
			[
				[
					'id' => 'match-exc',
					'invoiceId' => 'inv-m',
					'administrationId' => 'adm-1',
					'matchStatus' => 'exception_price',
					'createdAt' => '2026-07-01T00:00:00Z',
					'matchedPoIds' => ['po-m'],
					'matchedPoLineIds' => ['poline-m'],
				],
				[
					'id' => 'match-ok',
					'invoiceId' => 'inv-m',
					'administrationId' => 'adm-1',
					'matchStatus' => 'auto_approved',
					'createdAt' => '2026-07-02T00:00:00Z',
					'matchedPoIds' => ['po-m'],
					'matchedPoLineIds' => ['poline-m'],
				],
			]
		);

		$service = $this->makeService(
			os: $os,
			config: [
				'register' => 'shillinq',
				GRIRClearingService::CFG_ACCOUNTS_PAYABLE_ACCOUNT => '4400',
				GRIRClearingService::CFG_VAT_PAYABLE_ACCOUNT => '2100',
			]
		);

		$result = $service->settleGRIRForMatchedInvoice(administrationId: 'adm-1', invoiceId: 'inv-m');

		self::assertTrue($result['posted']);
		self::assertSame(24200, $result['amountCents']);

	}//end testSettleGRIRForMatchedInvoiceResolvesApprovedMatch()

	/**
	 * Grir-accrual-wiring REQ-003 fail-soft: no auto_approved/within_tolerance
	 * ThreeWayMatch exists for the invoice — settlement is skipped without
	 * throwing.
	 *
	 * @return void
	 */
	public function testSettleGRIRForMatchedInvoiceReturnsUnpostedWhenNoApprovedMatchExists(): void {
		$os = new InMemoryObjectService();
		$service = $this->makeService(os: $os);

		$result = $service->settleGRIRForMatchedInvoice(administrationId: 'adm-1', invoiceId: 'inv-none');

		self::assertFalse($result['posted']);

	}//end testSettleGRIRForMatchedInvoiceReturnsUnpostedWhenNoApprovedMatchExists()
}//end class
