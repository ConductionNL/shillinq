<?php

/**
 * Characterisation tests for the purchase-order receipt lifecycle update of
 * GoodsReceiptNoteService::acceptGRN() and
 * ServiceReceiptService::acceptServiceReceipt().
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
use OCA\Shillinq\Service\ServiceReceiptService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins when a referenced PurchaseOrder moves to partial_received or
 * fully_received, and when it is left untouched (unknown PO, no PO lines,
 * unchanged status), for both receipt services.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class ReceiptLifecycleTest extends TestCase {

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
	 * The PurchaseOrder saves made, in order.
	 *
	 * @return array<int,mixed> The saved ids and statusCodes.
	 */
	private function poSaves(): array {
		$out = [];
		foreach ($this->store->saves as $save) {
			if ($save[0] === 'PurchaseOrder') {
				$out[] = [$save[1]['id'], $save[1]['statusCode']];
			}
		}

		return $out;

	}//end poSaves()

	/**
	 * Seed purchase orders and their lines.
	 *
	 * @return void
	 */
	private function seedOrders(): void {
		$this->store->rows['PurchaseOrder'] = [
			['id' => 'po-1', 'administrationId' => 'adm-1', 'statusCode' => 'approved'],
			['id' => 'po-2', 'administrationId' => 'adm-1', 'statusCode' => 'partial_received'],
			['id' => 'po-3', 'administrationId' => 'adm-1', 'statusCode' => 'approved'],
			['id' => 'po-4', 'administrationId' => 'adm-1', 'statusCode' => 'approved'],
		];
		$this->store->rows['PurchaseOrderLine'] = [
			['id' => 'l-1a', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 2],
			['id' => 'l-1b', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 1.5],
			['@self' => ['id' => 'l-2a'], 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 4],
			['id' => 'l-4a', 'poId' => 'po-4', 'administrationId' => 'adm-1', 'quantityOrdered' => 3],
		];

	}//end seedOrders()

	/**
	 * Goods receipts: fully received, unchanged partial, no lines, unknown PO
	 * and a PO with nothing accepted.
	 *
	 * @return void
	 */
	public function testGoodsReceiptLifecycle(): void {
		$this->seedOrders();
		$this->store->rows['GoodsReceiptNote'] = [
			[
				'id' => 'grn-1',
				'administrationId' => 'adm-1',
				'statusCode' => 'received',
				'poIds' => ['po-1', 'po-2', 'po-3', 'po-404', 'po-4'],
			],
		];
		$this->store->rows['GoodsReceiptLine'] = [
			['grnId' => 'grn-1', 'administrationId' => 'adm-1', 'poLineId' => 'l-1a', 'quantityAccepted' => 0],
			['grnId' => 'other', 'administrationId' => 'adm-1', 'poLineId' => 'l-1a', 'quantityAccepted' => 0.0005],
			['grnId' => 'other', 'administrationId' => 'adm-1', 'poLineId' => 'l-1a', 'quantityAccepted' => 1.9995],
			['grnId' => 'other', 'administrationId' => 'adm-1', 'poLineId' => 'l-1b', 'quantityAccepted' => 1.5],
			['grnId' => 'other', 'administrationId' => 'adm-1', 'poLineId' => 'l-2a', 'quantityAccepted' => 1],
			['grnId' => 'other', 'administrationId' => 'adm-1', 'poLineId' => '', 'quantityAccepted' => 3],
		];

		$this->service(GoodsReceiptNoteService::class)->acceptGRN('adm-1', 'grn-1');

		self::assertSame([['po-1', 'fully_received']], $this->poSaves());

	}//end testGoodsReceiptLifecycle()

	/**
	 * Service receipts: only accepted receipts count; partial, unchanged,
	 * no lines, unknown PO and fully received.
	 *
	 * @return void
	 */
	public function testServiceReceiptLifecycle(): void {
		$this->seedOrders();
		$this->store->rows['SvcReceipt'] = [
			[
				'id' => 'r-1',
				'administrationId' => 'adm-1',
				'statusCode' => 'confirmed',
				'poIds' => ['po-1', 'po-2', 'po-3', 'po-404', 'po-4'],
			],
			['@self' => ['id' => 'r-2'], 'administrationId' => 'adm-1', 'statusCode' => 'accepted'],
			['id' => 'r-3', 'administrationId' => 'adm-1', 'statusCode' => 'confirmed'],
		];
		$this->store->rows['SvcReceiptLine'] = [
			['serviceReceiptId' => 'r-2', 'administrationId' => 'adm-1', 'poLineId' => 'l-1a', 'quantityAccepted' => 1],
			['serviceReceiptId' => 'r-3', 'administrationId' => 'adm-1', 'poLineId' => 'l-1b', 'quantityAccepted' => 9],
			['serviceReceiptId' => 'r-2', 'administrationId' => 'adm-1', 'poLineId' => 'l-2a', 'quantityAccepted' => 1],
			['serviceReceiptId' => 'r-2', 'administrationId' => 'adm-1', 'poLineId' => '', 'quantityAccepted' => 3],
			['serviceReceiptId' => 'r-2', 'administrationId' => 'adm-1', 'poLineId' => 'l-4a', 'quantityAccepted' => 3],
		];

		$this->service(ServiceReceiptService::class)->acceptServiceReceipt('adm-1', 'r-1');

		self::assertSame([['po-1', 'partial_received'], ['po-4', 'fully_received']], $this->poSaves());

	}//end testServiceReceiptLifecycle()
}//end class
