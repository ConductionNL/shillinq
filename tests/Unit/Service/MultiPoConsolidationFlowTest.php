<?php

/**
 * Characterisation tests for the MultiPoConsolidationService flows.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the refusal order of disambiguateAmbiguousMatches(), the full
 * ThreeWayMatch payload and invoice-link projection, the PO-only choice, the
 * missing-GRN consolidation status, the enumeration early returns and the
 * best-effort link failure.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class MultiPoConsolidationFlowTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock supplier invoice service (the link projection).
	 *
	 * @var SupplierInvoiceService&MockObject
	 */
	private SupplierInvoiceService&MockObject $invoices;

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test.
	 *
	 * @var MultiPoConsolidationService
	 */
	private MultiPoConsolidationService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$this->invoices = $this->createMock(SupplierInvoiceService::class);

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class {

			public string $schema = '';

			/** @var array<string,array<int,array<string,mixed>>> */
			public array $rows = [];

			/** @var array<int,array{0:string,1:array<string,mixed>}> */
			public array $saves = [];

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$filters = ($args['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			public function saveObject(array $object): array {
				$this->saves[] = [$this->schema, $object];
				$object['id'] = 'tw-' . count($this->saves);
				return $object;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->store->rows = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'supplierId' => 'sup-1',
					'invoiceDate' => '2026-07-31',
					'administrationId' => 'adm-1',
					'invoiceLines' => [
						['productCode' => 'P-1'],
						['lineNumber' => 5, 'productCode' => 'P-2'],
						['productCode' => ''],
					],
				],
			],
			'PurchaseOrder' => [
				['id' => 'po-1', 'supplierId' => 'sup-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'pl-1', 'poId' => 'po-1', 'productOrServiceCode' => 'P-1', 'expectedDeliveryDate' => '2026-07-20', 'administrationId' => 'adm-1'],
				['id' => 'pl-2', 'poId' => 'po-1', 'productOrServiceCode' => 'P-2', 'expectedDeliveryDate' => '', 'administrationId' => 'adm-1'],
				['id' => 'pl-3', 'poId' => 'po-1', 'productOrServiceCode' => 'P-2', 'expectedDeliveryDate' => '2025-01-01', 'administrationId' => 'adm-1'],
				['id' => 'pl-4', 'poId' => 'po-x', 'productOrServiceCode' => 'P-2', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'gl-1', 'grnId' => ' grn-1 ', 'poLineId' => 'pl-1', 'administrationId' => 'adm-1'],
				['id' => '', 'grnId' => 'grn-x', 'poLineId' => 'pl-1', 'administrationId' => 'adm-1'],
			],
		];

		$this->service = new MultiPoConsolidationService(
			appConfig: $appConfig,
			administrationContext: $context,
			userSession: $session,
			supplierInvoiceService: $this->invoices,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end setUp()

	/**
	 * Every refusal of disambiguateAmbiguousMatches(), in order.
	 *
	 * @return void
	 */
	public function testDisambiguationRefusals(): void {
		$cases = [
			'Supplier invoice not found' => ['', 0, ''],
			'Invalid invoiceLineNumber' => ['inv-1', 0, ''],
			'chosenPoLineId is required' => ['inv-1', 1, ''],
			'Supplier invoice not found ' => ['inv-404', 1, 'pl-1'],
			'Invoice line not found' => ['inv-1', 9, 'pl-1'],
			'Chosen tuple is not in the candidate set' => ['inv-1', 1, 'pl-2'],
		];
		foreach ($cases as $message => [$invoiceId, $lineNumber, $poLineId]) {
			try {
				$this->service->disambiguateAmbiguousMatches('adm-1', $invoiceId, $lineNumber, $poLineId, '');
				self::fail('expected refusal: ' . $message);
			} catch (RuntimeException $e) {
				self::assertSame(trim($message), $e->getMessage());
			}
		}

		self::assertSame([], $this->store->saves);

	}//end testDisambiguationRefusals()

	/**
	 * A PO-only choice persists the full ThreeWayMatch payload with a null
	 * GRN line and projects the links onto the invoice.
	 *
	 * @return void
	 */
	public function testPoOnlyChoicePayloadAndLink(): void {
		$this->invoices->expects(self::once())->method('linkInvoiceLineToPo')->with(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			invoiceLineNumber: 5,
			linkedPoLineId: 'pl-2',
			linkedPoId: 'po-1',
			linkedGrnLineId: null,
			linkedMatchId: 'tw-1'
		)->willReturn([]);

		$match = $this->service->disambiguateAmbiguousMatches('adm-1', 'inv-1', 5, 'pl-2', '');

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $match['createdAt']);
		$choice = $match['disambiguationChoice'];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $choice['chosenAt']);
		unset($match['createdAt'], $choice['chosenAt']);
		self::assertSame(
			['candidateCount' => 1, 'chosenPoLineId' => 'pl-2', 'chosenGrnLineId' => null, 'rejectedPoLineIds' => [], 'chosenBy' => 'alice'],
			$choice
		);
		unset($match['disambiguationChoice']);
		self::assertSame(
			[
				'invoiceId' => 'inv-1',
				'invoiceLineNumber' => 5,
				'productCode' => 'P-2',
				'poLineId' => 'pl-2',
				'grnLineId' => null,
				'matchedPoIds' => ['po-1'],
				'matchedGrnIds' => [],
				'matchStatus' => 'auto_approved',
				'divergenceDetails' => [],
				'administrationId' => 'adm-1',
				'id' => 'tw-1',
			],
			$match
		);

	}//end testPoOnlyChoicePayloadAndLink()

	/**
	 * Consolidation: a single PO+GRN candidate is auto, a single PO-only
	 * candidate is a missing-GRN exception, an empty product code is a
	 * missing-PO exception; a failing link projection only warns.
	 *
	 * @return void
	 */
	public function testConsolidationStatusesAndLinkFailure(): void {
		$this->invoices->method('linkInvoiceLineToPo')->willThrowException(new RuntimeException('doc locked'));
		$this->logger->expects(self::exactly(3))->method('warning')->with(
			'MultiPoConsolidationService: failed to link invoice line to PO',
			self::callback(static fn (array $context): bool => $context['invoiceId'] === 'inv-1' && $context['exception'] === 'doc locked')
		);

		$results = $this->service->consolidateInvoice('adm-1', 'inv-1');

		self::assertSame(
			[
				['invoiceLineNumber' => 1, 'status' => 'auto', 'matchId' => 'tw-1', 'candidateCount' => 1],
				['invoiceLineNumber' => 5, 'status' => 'exception', 'matchId' => 'tw-2', 'candidateCount' => 1],
				['invoiceLineNumber' => 3, 'status' => 'exception', 'matchId' => 'tw-3', 'candidateCount' => 0],
			],
			$results
		);
		self::assertSame(['grn-1'], $this->store->saves[0][1]['matchedGrnIds']);
		self::assertSame('gl-1', $this->store->saves[0][1]['grnLineId']);
		self::assertSame('exception_missing_grn', $this->store->saves[1][1]['matchStatus']);
		$missing = $this->store->saves[2][1];
		self::assertSame(
			['', null, null, [], []],
			[$missing['productCode'], $missing['poLineId'], $missing['grnLineId'], $missing['matchedPoIds'], $missing['matchedGrnIds']]
		);
		self::assertSame('exception_missing_po', $missing['matchStatus']);

	}//end testConsolidationStatusesAndLinkFailure()

	/**
	 * Enumeration returns nothing without a supplier, a product code or
	 * supplier POs.
	 *
	 * @return void
	 */
	public function testEnumerationEarlyReturns(): void {
		$line = ['productCode' => 'P-1'];
		self::assertSame([], $this->service->enumerateCandidateTuples('adm-1', [], $line));
		self::assertSame([], $this->service->enumerateCandidateTuples('adm-1', ['supplierId' => 'sup-1'], []));
		self::assertSame([], $this->service->enumerateCandidateTuples('adm-1', ['supplierId' => 'sup-9'], $line));
		self::assertCount(1, $this->service->enumerateCandidateTuples('adm-1', ['supplierId' => 'sup-1'], $line));

	}//end testEnumerationEarlyReturns()
}//end class
