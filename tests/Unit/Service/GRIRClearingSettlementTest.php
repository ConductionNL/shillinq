<?php

/**
 * Characterisation tests for GRIRClearingService::settleGRIRPosting().
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
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
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins every early return and refusal of settleGRIRPosting(), plus the full
 * transaction header and line payloads of the tax-only and unmatched paths.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class GRIRClearingSettlementTest extends TestCase {

	/**
	 * In-memory object store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * The service under test.
	 *
	 * @var GRIRClearingService
	 */
	private GRIRClearingService $service;

	/**
	 * Set up the service on an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->os = new InMemoryObjectService();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);

		$this->service = new GRIRClearingService(
			appConfig: $appConfig,
			administrationContext: $context,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->os),
		);

	}//end setUp()

	/**
	 * Seed one supplier invoice.
	 *
	 * @param array<string,mixed> $invoice Invoice fields.
	 *
	 * @return void
	 */
	private function seedInvoice(array $invoice): void {
		$this->os->seed('SupplierInvoice', [array_merge(['id' => 'inv-9', 'administrationId' => 'adm-1'], $invoice)]);

	}//end seedInvoice()

	/**
	 * Settle against a match carrying the given fields.
	 *
	 * @param array<string,mixed> $match Match fields.
	 *
	 * @return array<string,mixed> The result envelope.
	 */
	private function settle(array $match): array {
		return $this->service->settleGRIRPosting('adm-1', $match);

	}//end settle()

	/**
	 * A match without invoiceId and an unknown invoice are refused.
	 *
	 * @return void
	 */
	public function testMissingInvoiceRefusals(): void {
		try {
			$this->settle([]);
			self::fail('expected refusal');
		} catch (RuntimeException $e) {
			self::assertSame('settleGRIRPosting requires invoiceId on the ThreeWayMatch', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Supplier invoice not found for settlement');
		$this->settle(['invoiceId' => 'inv-404']);

	}//end testMissingInvoiceRefusals()

	/**
	 * Invoices without value are not posted, with their own messages.
	 *
	 * @return void
	 */
	public function testValuelessInvoicesAreNotPosted(): void {
		$this->seedInvoice(['totalExclVat' => 0, 'totalVat' => 0]);
		self::assertSame(
			['posted' => false, 'message' => 'Invoice has no monetary value to settle'],
			$this->settle(['invoiceId' => 'inv-9'])
		);

		$this->os = new InMemoryObjectService();
		$this->setUp();
		$this->seedInvoice(['totalExclVat' => 0, 'totalVat' => 0, 'totalInclVat' => 500]);
		self::assertSame(
			['posted' => false, 'message' => 'Invoice carries no excl/vat amount to settle'],
			$this->settle(['invoiceId' => 'inv-9'])
		);
		self::assertSame([], $this->os->setSchema('GLTransaction')->findAll());

	}//end testValuelessInvoicesAreNotPosted()

	/**
	 * A tax-only invoice without a match id posts a 2-line VAT / AP entry; the
	 * invoice id stands in for the match id and invoice number.
	 *
	 * @return void
	 */
	public function testTaxOnlyUnmatchedSettlementPayload(): void {
		$this->seedInvoice(['totalExclVat' => 0, 'totalVat' => 2100, 'invoiceDate' => '2026-05-17']);
		$this->os->seed(
			'PurchaseOrderLine',
			[['id' => 'pl-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'costCenter' => ' CC ', 'projectCode' => 'PRJ']]
		);

		$result = $this->settle(['invoiceId' => 'inv-9', 'matchedPoIds' => ['po-1']]);

		self::assertTrue($result['posted']);
		self::assertSame(2100, $result['amountCents']);
		$txn = $this->os->setSchema('GLTransaction')->findAll()[0];
		$description = 'GR/IR settlement — invoice inv-9 — match n/a';
		foreach ([
			'transactionNumber' => 'GRIR-SETTLE-inv-9',
			'journalCode' => 'GRIR-SETTLE',
			'postingDate' => '2026-05-17',
			'periodId' => '2026-Q2',
			'currency' => 'EUR',
			'description' => $description,
			'sourceReference' => 'inv-9',
			'threeWayMatchId' => null,
			'state' => 'draft',
			'administrationId' => 'adm-1',
		] as $key => $value) {
			self::assertSame($value, $txn[$key], $key);
		}

		$lines = $this->os->setSchema('GLLine')->findAll();
		self::assertSame(['2100', '4400'], array_column($lines, 'accountNumber'));
		self::assertSame(['debit', 'credit'], array_column($lines, 'side'));
		self::assertSame([21.0, 21.0], array_map('floatval', array_column($lines, 'amount')));
		self::assertSame([$description, $description], array_column($lines, 'description'));
		self::assertSame(['CC', 'CC'], array_column($lines, 'costCenter'));
		self::assertSame(['PRJ', 'PRJ'], array_column($lines, 'projectCode'));

	}//end testTaxOnlyUnmatchedSettlementPayload()

	/**
	 * A matched invoice uses the @self match id and the invoice number, and
	 * derives totalInclVat when it is absent.
	 *
	 * @return void
	 */
	public function testMatchedSettlementUsesSelfIdAndInvoiceNumber(): void {
		$this->seedInvoice(['invoiceNumber' => 'INV-7', 'totalExclVat' => 1000, 'totalVat' => 210]);

		$result = $this->settle(['invoiceId' => 'inv-9', '@self' => ['id' => 'm-ab_12']]);

		self::assertSame(1210, $result['amountCents']);
		$txn = $this->os->setSchema('GLTransaction')->findAll()[0];
		self::assertSame('GRIR-SETTLE-INV-7-MAB12', $txn['transactionNumber']);
		self::assertSame('GR/IR settlement — invoice INV-7 — match m-ab_12', $txn['description']);
		self::assertSame('m-ab_12', $txn['sourceReference']);
		self::assertSame('m-ab_12', $txn['threeWayMatchId']);
		$lines = $this->os->setSchema('GLLine')->findAll();
		self::assertSame(['2910', '2100', '4400'], array_column($lines, 'accountNumber'));
		self::assertSame(['', '', ''], array_column($lines, 'costCenter'));

	}//end testMatchedSettlementUsesSelfIdAndInvoiceNumber()
}//end class
