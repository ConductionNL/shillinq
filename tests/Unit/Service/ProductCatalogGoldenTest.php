<?php

/**
 * Golden characterisation of ProductCatalogService.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ProductCatalogService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins both envelopes, every query and the membership refusal.
 */
final class ProductCatalogGoldenTest extends TestCase {

	/**
	 * Recorded queries and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the subject over rows keyed by "register/schema".
	 *
	 * @param array<string,mixed> $rows       Rows per register/schema (a Throwable throws).
	 * @param array<int,string>   $accessible Administrations the caller may read.
	 *
	 * @return ProductCatalogService
	 */
	private function subject(array $rows, array $accessible): ProductCatalogService {
		$events = &$this->events;
		$state = ['register' => '', 'schema' => ''];
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnCallback(
			function (string $register) use (&$state, $store): ObjectServiceInterface {
				$state['register'] = $register;
				return $store;
			}
		);
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$state, $store): ObjectServiceInterface {
				$state['schema'] = $schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$state, &$events, $rows): array {
				$key = $state['register'] . '/' . $state['schema'];
				$events[] = ['find', $key, $config];
				$found = ($rows[$key] ?? []);
				if ($found instanceof \Throwable) {
					throw $found;
				}
				$filters = ($config['filters'] ?? []);
				return array_values(
					array_filter(
						$found,
						static fn ($row): bool => is_array($row) === false || array_intersect_assoc($filters, $row) === $filters
					)
				);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')->willReturn($accessible);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []) use (&$events): void {
				$events[] = ['debug', $message, $context];
			}
		);

		return new ProductCatalogService($config, $logger, $store, $context);

	}//end subject()

	/**
	 * A wrapped record answering getObject().
	 *
	 * @param mixed $payload The object payload.
	 *
	 * @return object
	 */
	private static function wrapped(mixed $payload): object {
		return new class($payload) {
			public function __construct(private mixed $payload) {
			}

			public function getObject(): mixed {
				return $this->payload;
			}
		};

	}//end wrapped()

	/**
	 * The master rows.
	 *
	 * @return array<string,mixed>
	 */
	private static function master(): array {
		return [
			'pipelinq/product' => [
				['id' => 'p2', 'sku' => ' SKU-2 ', 'name' => 'Beta', 'category' => 'cat-1', 'unitPrice' => '12.50', 'taxRate' => 21, 'unit' => 'C62', 'barcode' => 8712, 'status' => 'active', 'cost' => 'n/a',
					'variants' => [['attributes' => ['size' => 'M', 'colour' => 'red', 7 => 'x']], 'junk', ['attributes' => 'none'], ['attributes' => ['size' => 'L']]],
					'modifierGroups' => [['name' => 'colour'], ['name' => ' '], 'junk', ['name' => 'Engraving']],
				],
				self::wrapped(['uuid' => 'p1', 'name' => '', 'category' => 'cat-x', 'variants' => 'none', 'modifierGroups' => [['name' => 'Wrap']]]),
				self::wrapped('not-an-array'),
				'scalar',
			],
			'pipelinq/productCategory' => [['id' => 'cat-1', 'name' => 'Tools'], ['uuid' => 'cat-2', 'name' => ''], ['name' => 'Nameless']],
		];

	}//end master()

	/**
	 * The local rows across two administrations.
	 *
	 * @return array<string,mixed>
	 */
	private static function local(): array {
		return [
			'shillinq/InventoryStock' => [
				['administrationId' => 'adm-1', 'productId' => 'p-b', 'productSku' => 'B', 'status' => 'active', 'quantityOnHand' => 3, 'unitCost' => '1.5'],
				['administrationId' => 'adm-1', 'productId' => 'p-b', 'productSku' => 'B2', 'quantityOnHand' => '2.5', 'unitCost' => 'x'],
				['administrationId' => 'adm-1', 'productId' => ' ', 'quantityOnHand' => 9],
				['administrationId' => 'adm-2', 'productId' => 'p-a', 'quantityOnHand' => 1, 'unitCost' => 4],
				['administrationId' => 'adm-3', 'productId' => 'p-secret', 'quantityOnHand' => 99],
			],
			'shillinq/Barcode' => [
				['productId' => 'p-b', 'barcode' => '111'],
				['productId' => 'p-b', 'barcode' => '222', 'isDefault' => true],
				['productId' => 'p-b', 'barcode' => '333'],
				['productId' => 'p-c', 'productSku' => 'C', 'barcode' => ' '],
				['barcode' => '444'],
			],
		];

	}//end local()

	/**
	 * Every envelope and the events behind it.
	 *
	 * @return array<string,mixed>
	 */
	private function payloads(): array {
		$cases = [
			'master' => [self::master() + self::local(), ['adm-1']],
			'local' => [self::local(), ['adm-1', 'adm-2']],
			'masterThrows' => [['pipelinq/product' => new \RuntimeException('no pipelinq')] + self::local(), ['adm-2']],
			'masterWithoutAttributes' => [['pipelinq/product' => [['id' => 'p9', 'variants' => []]]], ['adm-1']],
			'refused' => [self::master() + self::local(), []],
		];
		$out = [];
		foreach ($cases as $label => [$rows, $accessible]) {
			foreach (['listProducts', 'listAttributes'] as $method) {
				$this->events = [];
				$out[$label][$method] = ['result' => $this->subject($rows, $accessible)->$method(), 'events' => $this->events];
			}
		}

		return $out;

	}//end payloads()

	/**
	 * Pin every envelope against the golden fixture.
	 *
	 * @return void
	 */
	public function testEnvelopesMatchTheGolden(): void {
		$golden = (string)file_get_contents(__DIR__ . '/fixtures/product-catalog-golden.json');
		self::assertSame($golden, json_encode($this->payloads(), (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testEnvelopesMatchTheGolden()

	/**
	 * Allowed and refused: no membership answers null before any query, and
	 * the local projection only reads the caller's administrations.
	 *
	 * @return void
	 */
	public function testMembershipRefusalAndScoping(): void {
		$this->events = [];
		self::assertNull($this->subject(self::master(), [])->listProducts());
		self::assertNull($this->subject(self::master(), [])->listAttributes());
		self::assertSame([], $this->events);

		$products = $this->subject(self::local(), ['adm-1'])->listProducts()['products'];
		self::assertSame(['p-b', 'p-c'], array_column($products, 'productId'));

	}//end testMembershipRefusalAndScoping()
}//end class
