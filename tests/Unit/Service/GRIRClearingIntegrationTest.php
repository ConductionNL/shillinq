<?php

/**
 * Integration tests for GRIRClearingService (member 09 of
 * bookkeeping-purchase-order-3way).
 *
 * End-to-end exercise of the GR/IR clearing accounting backbone over an
 * in-memory ObjectService stub, asserting REQ-PO3W-009 acceptance scenarios:
 *
 *  - GRN accept fires the clearing posting and the GR/IR account is left
 *    with a non-zero credit-side saldo (goods received, not invoiced);
 *  - Invoice match approval fires the settlement posting and the GR/IR
 *    account saldo nets to zero;
 *  - At period-end, when every GRN has a matching approved invoice, the
 *    GR/IR control account reconciles to zero (no dangling
 *    goods-in-transit); when an invoice is still pending the saldo is
 *    non-zero and surfaces the gap to the operator.
 *
 * Hermetic — no Nextcloud bootstrap, no DB, no HTTP.
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
 * End-to-end GR/IR clearing flow.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GRIRClearingIntegrationTest extends TestCase {
	/**
	 * REQ-PO3W-009 acceptance scenario 1 — Clearing on GRN accept,
	 * settlement on approval.
	 *
	 * Fires the full happy-path:
	 *  1. GRN accept materialises DR 1200 / CR 2910 for the line value;
	 *  2. ThreeWayMatch approval materialises DR 2910 + DR 2100 / CR 4400;
	 *  3. After both postings the GR/IR clearing account saldo (sum
	 *     debit - sum credit) is exactly zero.
	 *
	 * @return void
	 */
	public function testGRNAcceptThenMatchApprovalNetsGRIRClearingToZero(): void {
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
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-1',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-ERS-2026-00445',
					'totalExclVat' => 1850040,
					'totalVat' => 388508,
					'totalInclVat' => 2238548,
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

		// Stage 1 — GRN accept fires the clearing posting.
		$clearing = $service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'grn-1', 'grnNumber' => 'GRN-2026-adm-1-000001', 'receivedAt' => '2026-06-01'],
			grnLine: ['id' => 'grnline-1', 'poLineId' => 'poline-1', 'quantityAccepted' => 180.0]
		);
		self::assertTrue($clearing['posted']);
		self::assertSame(1850040, $clearing['amountCents']);

		// Stage 2 — ThreeWayMatch approval fires the settlement posting.
		$settlement = $service->settleGRIRPosting(
			administrationId: 'adm-1',
			threeWayMatch: [
				'id' => 'match-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'matchedPoIds' => ['po-1'],
				'matchedPoLineIds' => ['poline-1'],
				'matchStatus' => 'auto_approved',
			]
		);
		self::assertTrue($settlement['posted']);

		// Two GLTransactions: GRIR clearing + GRIR-SETTLE.
		$txns = $os->setSchema('GLTransaction')->findAll();
		self::assertCount(2, $txns);

		// Clearing CR + settlement DR on the 2910 account must net to zero.
		$glLines = $os->setSchema('GLLine')->findAll();
		$clearingDebit = 0;
		$clearingCredit = 0;
		foreach ($glLines as $line) {
			if ($line['accountNumber'] !== '2910') {
				continue;
			}

			$amountCents = (int)round(((float)$line['amount']) * 100.0, 0, PHP_ROUND_HALF_UP);
			if ($line['side'] === 'debit') {
				$clearingDebit += $amountCents;
			} else {
				$clearingCredit += $amountCents;
			}
		}

		self::assertSame(1850040, $clearingDebit, 'Settlement should debit GR/IR clearing for the same amount the GRN credited.');
		self::assertSame(1850040, $clearingCredit, 'GRN-accept should credit GR/IR clearing for the line amount.');
		self::assertSame(0, ($clearingDebit - $clearingCredit), 'GR/IR account must net to zero after full settlement.');

	}//end testGRNAcceptThenMatchApprovalNetsGRIRClearingToZero()

	/**
	 * REQ-PO3W-009 acceptance scenario 2 — Period-end GR/IR saldo
	 * reconciliation.
	 *
	 * Variant a: every GRN in the period is matched + approved →
	 * `reconcileGRIRSaldoForPeriod()` returns saldoCents = 0 with
	 * balanced=true.
	 *
	 * Variant b: one GRN is accepted but its invoice has not yet been
	 * approved → the saldo equals the outstanding clearing balance and
	 * `balanced=false` flags it for operator follow-up.
	 *
	 * @return void
	 */
	public function testPeriodEndReconciliationFlagsDanglingGoodsInTransit(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-A',
					'poId' => 'po-A',
					'administrationId' => 'adm-1',
					'unitPrice' => 10000,
					'glAccount' => '1200',
				],
				[
					'id' => 'poline-B',
					'poId' => 'po-B',
					'administrationId' => 'adm-1',
					'unitPrice' => 5000,
					'glAccount' => '1200',
				],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-A',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-A',
					'totalExclVat' => 1000000,
					'totalVat' => 210000,
					'totalInclVat' => 1210000,
					'invoiceDate' => '2026-06-05',
				],
			],
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

		// GRN A — 100 units * 10000 = 1,000,000 cents — and its matching
		// approved invoice (fully settled).
		$service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'grn-A', 'grnNumber' => 'GRN-A', 'receivedAt' => '2026-06-01'],
			grnLine: ['id' => 'gl-A', 'poLineId' => 'poline-A', 'quantityAccepted' => 100.0]
		);
		$service->settleGRIRPosting(
			administrationId: 'adm-1',
			threeWayMatch: [
				'id' => 'match-A',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-A',
				'matchedPoIds' => ['po-A'],
				'matchedPoLineIds' => ['poline-A'],
				'matchStatus' => 'auto_approved',
			]
		);

		// GRN B — 20 units * 5000 = 100,000 cents — invoice not yet
		// approved → no settlement posting.
		$service->createGRIRPosting(
			administrationId: 'adm-1',
			grn: ['id' => 'grn-B', 'grnNumber' => 'GRN-B', 'receivedAt' => '2026-06-02'],
			grnLine: ['id' => 'gl-B', 'poLineId' => 'poline-B', 'quantityAccepted' => 20.0]
		);

		// Reconcile the 2026-Q2 GR/IR saldo. GRN A is settled, GRN B is not
		// → saldoCents should equal -100000 (CR 100000 still outstanding).
		$reconciliation = $service->reconcileGRIRSaldoForPeriod(administrationId: 'adm-1', periodId: '2026-Q2');

		self::assertSame('2026-Q2', $reconciliation['periodId']);
		self::assertSame('2910', $reconciliation['clearingAccount']);
		// Debit total: settlement of GRN A clears 1,000,000 cents.
		self::assertSame(1000000, $reconciliation['debitCents']);
		// Credit total: GRN A clearing (1,000,000) + GRN B clearing (100,000).
		self::assertSame(1100000, $reconciliation['creditCents']);
		// Saldo = -100,000 (net credit) — surfaces the dangling GRN B.
		self::assertSame(-100000, $reconciliation['saldoCents']);
		self::assertFalse($reconciliation['balanced']);

		// Now settle GRN B and re-reconcile — saldo must net to zero.
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-B',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-B',
					'totalExclVat' => 100000,
					'totalVat' => 21000,
					'totalInclVat' => 121000,
					'invoiceDate' => '2026-06-10',
				],
			]
		);
		$service->settleGRIRPosting(
			administrationId: 'adm-1',
			threeWayMatch: [
				'id' => 'match-B',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-B',
				'matchedPoIds' => ['po-B'],
				'matchedPoLineIds' => ['poline-B'],
				'matchStatus' => 'auto_approved',
			]
		);

		$finalReconciliation = $service->reconcileGRIRSaldoForPeriod(administrationId: 'adm-1', periodId: '2026-Q2');
		self::assertSame(1100000, $finalReconciliation['debitCents']);
		self::assertSame(1100000, $finalReconciliation['creditCents']);
		self::assertSame(0, $finalReconciliation['saldoCents']);
		self::assertTrue($finalReconciliation['balanced']);

	}//end testPeriodEndReconciliationFlagsDanglingGoodsInTransit()

	/**
	 * Spec scenario: when the ThreeWayMatch's matchedPoLineIds is empty,
	 * the service falls back to the first PO line of the first matched
	 * PO so costCenter/projectCode preservation still works. This mirrors
	 * slice 06's contract: the matching engine MUST populate matchedPoIds
	 * even when per-line links are not yet known.
	 *
	 * @return void
	 */
	public function testSettlementFallsBackToFirstPoLineOfFirstMatchedPo(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'PurchaseOrderLine',
			[
				[
					'id' => 'poline-fb',
					'poId' => 'po-fb',
					'administrationId' => 'adm-1',
					'glAccount' => '1200',
					'costCenter' => 'PROC-2026',
					'projectCode' => 'PRJ-MILK',
				],
			]
		);
		$os->seed(
			'SupplierInvoice',
			[
				[
					'id' => 'inv-fb',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-FB',
					'totalExclVat' => 50000,
					'totalVat' => 10500,
					'totalInclVat' => 60500,
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

		// No matchedPoLineIds — only matchedPoIds.
		$result = $service->settleGRIRPosting(
			administrationId: 'adm-1',
			threeWayMatch: [
				'id' => 'match-fb',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-fb',
				'matchedPoIds' => ['po-fb'],
				'matchStatus' => 'auto_approved',
			]
		);

		self::assertTrue($result['posted']);

		$lines = $os->setSchema('GLLine')->findAll();
		foreach ($lines as $line) {
			self::assertSame('PROC-2026', $line['costCenter']);
			self::assertSame('PRJ-MILK', $line['projectCode']);
		}

	}//end testSettlementFallsBackToFirstPoLineOfFirstMatchedPo()

	/**
	 * Build a service wired against the supplied stub + appConfig overrides.
	 *
	 * @param InMemoryObjectService $os In-memory OR stub.
	 * @param array<string,string> $config App-config overrides.
	 * @param array<int,string> $accessibleAdministrations Tenants the caller may access.
	 *
	 * @return GRIRClearingService
	 */
	private function makeService(
		InMemoryObjectService $os,
		array $config = [],
		array $accessibleAdministrations = ['adm-1'],
	): GRIRClearingService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		$logger = $this->createStub(LoggerInterface::class);

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
}//end class
