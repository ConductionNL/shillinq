<?php

/**
 * Golden test for the delivery stock check behind canConfirmDelivery.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the decision and every store call per delivery.
 *
 * Regenerate with DELIVERY_STOCK_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class QuoteOrderInvoiceGuardStockGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/quote-order-invoice-delivery-stock.golden.json';

	public function testDecisionsMatchGolden(): void {
		$stock = [
			['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 4, 'reservedQuantity' => 1],
			['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-2', 'quantity' => 9, 'reservedQuantity' => 0],
			['sku' => 'sku-a', 'administrationId' => 'adm-2', 'locationId' => 'loc-1', 'quantity' => 100, 'reservedQuantity' => 0],
			['sku' => 'sku-b', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 2, 'reservedQuantity' => 0],
		];
		$base = ['SalesOrder' => [['id' => 'so-1', 'customerReference' => 'cust-1']], 'InventoryStock' => $stock];
		$allow = $base + ['InventoryGLConfig' => [['administrationId' => 'adm-1', 'allowNegativeStockOnDispatch' => true]]];
		$scenarios = [
			'bestOfLocations' => [$this->delivery([['sku-a', 9]]), $base],
			'bestOfShort' => [$this->delivery([['sku-a', 9.5]]), $base],
			'lineLocation' => [$this->delivery([['sku-a', 3, 'loc-1']]), $base],
			'lineLocationShort' => [$this->delivery([['sku-a', 4, 'loc-1']]), $base],
			'deliveryLocation' => [$this->delivery([['sku-a', 5]], 'loc-1'), $base],
			'unknownLocation' => [$this->delivery([['sku-b', 1, 'loc-9']]), $base],
			'serviceLine' => [$this->delivery([['svc-1', 50], ['sku-b', 2]]), $base],
			'secondLineShort' => [$this->delivery([['sku-b', 2], ['sku-a', 20]]), $base],
			'negativeAllowed' => [$this->delivery([['sku-a', 20]]), $allow],
			'zeroQuantity' => [$this->delivery([['sku-b', 0]]), $base],
			'otherAdministration' => [['administrationId' => 'adm-2'] + $this->delivery([['sku-b', 1]]), $base],
		];

		$actual = [];
		foreach ($scenarios as $name => [$delivery, $records]) {
			$actual[$name] = $this->scenario($delivery, $records);
		}

		if (getenv('DELIVERY_STOCK_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * A complete delivery with the given [sku, quantity, location?] lines.
	 *
	 * @param array<int,array<int,mixed>> $lines The lines.
	 * @param string|null $location The delivery's source location.
	 *
	 * @return array<string,mixed>
	 */
	private function delivery(array $lines, string|null $location = null): array {
		$delivery = ['administrationId' => 'adm-1', 'sourceOrderReference' => 'so-1', 'lines' => []];
		foreach ($lines as $index => $line) {
			$row = ['orderLineReference' => 'sol-'.$index, 'productReference' => $line[0], 'quantityShipped' => $line[1]];
			if (isset($line[2]) === true) {
				$row['sourceLocationId'] = $line[2];
			}

			$delivery['lines'][] = $row;
		}

		if ($location !== null) {
			$delivery['sourceLocationId'] = $location;
		}

		return $delivery;
	}

	/**
	 * Run one delivery confirmation and capture the result and store calls.
	 *
	 * @param array<string,mixed> $delivery The delivery.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $delivery, array $records): array {
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->calls[] = [$this->schema, ($params['filters'] ?? [])];
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->records[$this->schema] ?? []),
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$guard = new QuoteOrderInvoiceGuard(appConfig: $appConfig, logger: new NullLogger(), objectService: new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canConfirmDelivery(deliveryId: 'dn-1', object: $delivery),
			'calls' => $store->calls,
		];
	}
}
