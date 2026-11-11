<?php

/**
 * Characterisation tests for the OpenRegister read/write helpers of the
 * receipt services (GoodsReceiptNoteService, ServiceReceiptService,
 * GRIRClearingService).
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCA\Shillinq\Service\ServiceReceiptService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins persist failures (logged and rethrown as "Failed to persist <schema>"),
 * query failures (logged, read as no rows), the dropping of non-array rows and
 * the saved payload read back from the store, for each receipt service.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class ReceiptRecordStorageTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock administration context.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturn(true);
		$this->context->method('currentUserId')->willReturn('alice');

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class {

			/** @var string current schema */
			public string $schema = '';

			/** @var array<int,string> registers set, in order */
			public array $registers = [];

			/** @var array<string,array<int,mixed>> rows per schema */
			public array $rows = [];

			/** @var array<int,string> schemas whose findAll throws */
			public array $failFind = [];

			/** @var array<int,string> schemas whose saveObject throws */
			public array $failSave = [];

			/** @var array<int,array{0:string,1:array<string,mixed>}> saves */
			public array $saves = [];

			public function setRegister(string $register): self {
				$this->registers[] = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				if (in_array($this->schema, $this->failFind, true) === true) {
					throw new RuntimeException('query down');
				}

				return ($this->rows[$this->schema] ?? []);
			}

			public function find(array $args = []): array {
				return [];
			}

			public function saveObject(array $obj): array {
				if (in_array($this->schema, $this->failSave, true) === true) {
					throw new RuntimeException('store down');
				}

				$this->saves[] = [$this->schema, $obj];
				$obj['id'] = 'saved-' . count($this->saves);
				return $obj;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end setUp()

	/**
	 * Build a receipt service of the given class.
	 *
	 * @param class-string $class The service class.
	 *
	 * @return object The service.
	 */
	private function service(string $class): object {
		return new $class(
			appConfig: $this->appConfig,
			administrationContext: $this->context,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end service()

	/**
	 * Expect exactly one logged error.
	 *
	 * @param string              $message The message.
	 * @param array<string,mixed> $context The context.
	 *
	 * @return void
	 */
	private function expectError(string $message, array $context): void {
		$this->logger->expects(self::once())->method('error')->with($message, $context);

	}//end expectError()

	/**
	 * A failing save is logged and rethrown (service receipt).
	 *
	 * @return void
	 */
	public function testServiceReceiptPersistFailure(): void {
		$this->store->rows['PurchaseOrder'] = [['id' => 'po-1']];
		$this->store->failSave = ['SvcReceipt'];
		$this->expectError(
			'ServiceReceiptService: failed to persist object',
			['schema' => 'SvcReceipt', 'exception' => 'store down']
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist SvcReceipt');

		$this->service(ServiceReceiptService::class)->createServiceReceipt('adm-1', ['poIds' => ['po-1']]);

	}//end testServiceReceiptPersistFailure()

	/**
	 * A failing query is logged and reads as "not found" (service receipt).
	 *
	 * @return void
	 */
	public function testServiceReceiptQueryFailure(): void {
		$this->store->failFind = ['PurchaseOrder'];
		$this->expectError(
			'ServiceReceiptService: failed to query OpenRegister',
			['schema' => 'PurchaseOrder', 'exception' => 'query down']
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');

		$this->service(ServiceReceiptService::class)->createServiceReceipt('adm-1', ['poIds' => ['po-1']]);

	}//end testServiceReceiptQueryFailure()

	/**
	 * Non-array rows are dropped and the saved payload is read back with its
	 * id (service receipt).
	 *
	 * @return void
	 */
	public function testServiceReceiptRowsAndSavedPayload(): void {
		$this->store->rows['PurchaseOrder'] = ['not-a-row', ['id' => 'po-1']];
		$this->logger->expects(self::never())->method('error');

		$saved = $this->service(ServiceReceiptService::class)->createServiceReceipt(
			'adm-1',
			['poIds' => ['po-1'], 'periodStart' => '2026-01-01']
		);

		self::assertSame('saved-1', $saved['id']);
		self::assertSame('SVR-' . date('Y') . '-adm-1-000001', $saved['receiptNumber']);
		self::assertSame(['shillinq', 'shillinq', 'shillinq'], $this->store->registers);

		$this->store->rows['PurchaseOrder'] = ['not-a-row'];
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');
		$this->service(ServiceReceiptService::class)->createServiceReceipt('adm-1', ['poIds' => ['po-1']]);

	}//end testServiceReceiptRowsAndSavedPayload()

	/**
	 * A failing save is logged and rethrown (goods receipt note).
	 *
	 * @return void
	 */
	public function testGoodsReceiptPersistFailure(): void {
		$this->store->rows['PurchaseOrder'] = [['id' => 'po-1']];
		$this->store->failSave = ['GoodsReceiptNote'];
		$this->expectError(
			'GoodsReceiptNoteService: failed to persist object',
			['schema' => 'GoodsReceiptNote', 'exception' => 'store down']
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist GoodsReceiptNote');

		$this->service(GoodsReceiptNoteService::class)->createGRN('adm-1', ['poIds' => ['po-1']]);

	}//end testGoodsReceiptPersistFailure()

	/**
	 * A failing query is logged and reads as "not found" (goods receipt note).
	 *
	 * @return void
	 */
	public function testGoodsReceiptQueryFailure(): void {
		$this->store->failFind = ['PurchaseOrder'];
		$this->expectError(
			'GoodsReceiptNoteService: failed to query OpenRegister',
			['schema' => 'PurchaseOrder', 'exception' => 'query down']
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');

		$this->service(GoodsReceiptNoteService::class)->createGRN('adm-1', ['poIds' => ['po-1']]);

	}//end testGoodsReceiptQueryFailure()

	/**
	 * The saved payload is read back with its id (goods receipt note).
	 *
	 * @return void
	 */
	public function testGoodsReceiptSavedPayload(): void {
		$this->store->rows['PurchaseOrder'] = ['not-a-row', ['id' => 'po-1']];

		$saved = $this->service(GoodsReceiptNoteService::class)->createGRN(
			'adm-1',
			['poIds' => ['po-1'], 'receivedAt' => '2026-01-01T00:00:00+00:00']
		);

		self::assertSame('saved-1', $saved['id']);
		self::assertSame('GoodsReceiptNote', $this->store->saves[0][0]);

	}//end testGoodsReceiptSavedPayload()

	/**
	 * A failing query is logged and reads as no lines; non-array rows are
	 * dropped (GR/IR clearing).
	 *
	 * @return void
	 */
	public function testClearingSaldoQueryFailureAndRows(): void {
		$service = $this->service(GRIRClearingService::class);
		$this->store->rows['GLLine'] = ['not-a-row', ['side' => 'debit', 'amount' => 12.5], ['side' => 'credit', 'amount' => 2.5]];
		$result = $service->reconcileGRIRSaldoForPeriod('adm-1', '2026-Q1');
		self::assertSame(1000, $result['saldoCents']);

		$this->store->failFind = ['GLLine'];
		$this->expectError(
			'GRIRClearingService: failed to query OpenRegister',
			['schema' => 'GLLine', 'exception' => 'query down']
		);
		$result = $service->reconcileGRIRSaldoForPeriod('adm-1', '2026-Q1');
		self::assertSame(0, $result['saldoCents']);
		self::assertTrue($result['balanced']);

	}//end testClearingSaldoQueryFailureAndRows()
}//end class
