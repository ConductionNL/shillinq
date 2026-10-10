<?php

/**
 * Integration tests for PurchaseOrderService (member 02 of bookkeeping-purchase-order-3way).
 *
 * Exercises the full create-and-send loop end-to-end:
 *  - createPurchaseOrder writes a draft order with totalExclVat in cents and
 *    no in-object chain;
 *  - markSent is refused until OpenRegister's approval chain has moved the
 *    order to approved (purchasing-approval-delegation REQ-PAD-001);
 *  - the manifest fragment that ships with this slice exposes the
 *    PurchaseOrderForm + PurchaseOrderDetail pages so the Vue layer can reach
 *    the API surface added here.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end PO create → approve → send flow over an in-memory ObjectService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderIntegrationTest extends TestCase {

	/**
	 * End-to-end: create a EUR 18,500 PO in draft, refuse the send, then send
	 * once the order is approved. The approval itself is OpenRegister's: its
	 * chain advances the `approve` transition, which the stub stands in for by
	 * setting statusCode, the only thing shillinq reads.
	 *
	 * @return void
	 */
	public function testCreateThenSendEndToEnd(): void {
		$data = [
			'PurchaseOrder' => [],
		];

		$saved = [];
		$stub = $this->buildObjectServiceStub($data, $saved);

		$service = $this->buildService($stub, $saved, 'inkoper-1', ['adm-1']);

		$po = $service->createPurchaseOrder(
			administrationId: 'adm-1',
			payload: [
				'supplierId' => 'sup-coffee',
				'costCenter' => 'FAC-2026',
				'currency' => 'EUR',
				'lines' => [
					['productCode' => 'COFFEE-PRO-1', 'quantity' => 1, 'unitPrice' => 18500.00, 'vatRate' => 0.21, 'glAccount' => '4400'],
				],
			]
		);

		self::assertSame(1850000, $po['totalExclVat']);
		self::assertSame('draft', $po['statusCode']);
		self::assertArrayNotHasKey('approvalChain', $po);

		$poId = (string)($po['id'] ?? '');
		self::assertNotEmpty($poId);

		try {
			$service->markSent(administrationId: 'adm-1', purchaseOrderId: $poId);
			self::fail('Expected markSent to refuse a draft order');
		} catch (\RuntimeException $e) {
			self::assertSame('Purchase order cannot be sent: it is not approved', $e->getMessage());
		}

		$stub->mutate(
			'PurchaseOrder',
			$poId,
			static function (array &$row) {
				$row['statusCode'] = 'approved';
			}
		);

		$updated = $service->markSent(administrationId: 'adm-1', purchaseOrderId: $poId);
		self::assertSame('sent', $updated['statusCode']);
		self::assertNotEmpty($updated['sentAt']);
	}//end testCreateThenSendEndToEnd()

	/**
	 * The standalone PurchaseOrderForm/PurchaseOrderDetail pages were retired into
	 * the unified Order workspace (order-workspace.json, abstract-order-primitive
	 * change). The slice-02 core fragment now ships an empty pages/menu (the schema +
	 * lifecycle remain). Verify that:
	 *   (a) the core fragment still exists and is valid JSON with pages + menu keys, and
	 *   (b) the order-workspace.json fragment exposes the unified Orders + OrderDetail
	 *       pages that replaced the retired standalone PO pages.
	 *
	 * @return void
	 */
	public function testManifestFragmentExposesPurchaseOrderPages(): void {
		// (a) Slice-02 core fragment still exists and has valid (possibly empty) structure.
		$fragmentPath = __DIR__ . '/../../../src/manifest.d/bookkeeping-purchase-order-3way-02-core.json';
		self::assertFileExists($fragmentPath);

		$json = json_decode((string)file_get_contents($fragmentPath), true);
		self::assertIsArray($json);
		self::assertArrayHasKey('pages', $json);
		self::assertArrayHasKey('menu', $json);

		// (b) Unified Order workspace exposes the Orders + OrderDetail pages (the
		//     successors to the retired PurchaseOrderForm/PurchaseOrderDetail pages).
		$workspacePath = __DIR__ . '/../../../src/manifest.d/order-workspace.json';
		self::assertFileExists($workspacePath, 'order-workspace.json must exist (unified Order pages)');

		$workspace = json_decode((string)file_get_contents($workspacePath), true);
		self::assertIsArray($workspace);

		$wsPageIds = array_column($workspace['pages'] ?? [], 'id');
		self::assertContains('Orders', $wsPageIds, 'Unified Orders index page must be present in order-workspace.json');
		self::assertContains('OrderDetail', $wsPageIds, 'Unified OrderDetail page must be present in order-workspace.json');

		$wsMenuIds = array_column($workspace['menu'] ?? [], 'id');
		self::assertContains('Orders', $wsMenuIds, 'Orders menu entry must be present in order-workspace.json');

	}//end testManifestFragmentExposesPurchaseOrderPages()

	/**
	 * Build a mutable in-memory ObjectService stub.
	 *
	 * Returns an object with setRegister/setSchema/findAll/saveObject plus an
	 * extra mutate() helper used by the integration test to simulate an approver
	 * signing a chain entry.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Captured saves (mutable ref).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Initial rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Resolve all rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Save (insert or update by id) into the active schema's bucket.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'obj-' . $this->idCounter;
				} else {
					// Upsert: remove existing row with the same id first.
					$this->data[$this->schema] = array_values(
						array_filter(
							($this->data[$this->schema] ?? []),
							static fn (array $row): bool => (($row['id'] ?? null) !== $object['id'])
						)
					);
				}

				$this->data[$this->schema][] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()

			/**
			 * Mutate a row in place — test-only helper.
			 *
			 * @param string $schema Schema slug.
			 * @param string $id Row id.
			 * @param callable $callback Callback receiving the row by reference.
			 *
			 * @return void
			 */
			public function mutate(string $schema, string $id, callable $callback): void {
				foreach ($this->data[$schema] ?? [] as $index => $row) {
					if (($row['id'] ?? null) === $id) {
						$callback($row);
						$this->data[$schema][$index] = $row;
						return;
					}
				}
			}//end mutate()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build a PurchaseOrderService over the given stub.
	 *
	 * @param object $stub ObjectService stub.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants the caller may access.
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(
		object $stub,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): PurchaseOrderService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new PurchaseOrderService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()
}//end class
