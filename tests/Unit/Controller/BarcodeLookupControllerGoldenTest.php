<?php

/**
 * Golden test for BarcodeLookupController::lookup.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BarcodeLookupController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the lookup, allowed and refused, with every config read, store call and log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BarcodeLookupControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup key (string), header (string), user (bool), barcodes, products, register, fail (bool).
	 *
	 * @return BarcodeLookupController
	 */
	private function controller(array $setup=[]): BarcodeLookupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			function (string $name) use ($setup): string {
				$this->calls[] = ['getHeader', $name];
				return ($setup['header'] ?? 'Bearer secret');
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default='') use ($setup): string {
				$this->calls[] = ['config', $app, $key, $default];
				if ($key === 'register') {
					return ($setup['register'] ?? '');
				}

				return ($setup['key'] ?? 'secret');
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($setup): ?IUser {
				$this->calls[] = ['getUser'];
				if (($setup['user'] ?? false) === true) {
					return $this->createMock(IUser::class);
				}

				return null;
			}
		);
		$store     = $this->store(setup: $setup);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store, $setup): object {
				$this->calls[] = ['container', $id];
				if (($setup['fail'] ?? false) === true) {
					throw new RuntimeException('no openregister');
				}

				return $store;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['debug', $message, $context];
			}
		);

		return new BarcodeLookupController($request, $container, $config, $session, $logger);
	}//end controller()

	/**
	 * A recording object store.
	 *
	 * @param array<string,mixed> $setup barcodes and products (what findAll answers per schema).
	 *
	 * @return object
	 */
	private function store(array $setup): object {
		return new class ($this, $setup) {
			/**
			 * The current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param BarcodeLookupControllerGoldenTest $test  The test, for its call log.
			 * @param array<string,mixed>               $setup The answers.
			 */
			public function __construct(private BarcodeLookupControllerGoldenTest $test, private array $setup) {
			}

			/**
			 * Record the register.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}

			/**
			 * Record the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				$this->schema        = $schema;
				return $this;
			}

			/**
			 * The rows for the current schema.
			 *
			 * @param array<string,mixed> $params The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $params): mixed {
				$this->test->calls[] = ['findAll', $params];
				if ($this->schema === 'Barcode') {
					return ($this->setup['barcodes'] ?? []);
				}

				return ($this->setup['products'] ?? []);
			}
		};
	}//end store()

	/**
	 * Status and body.
	 *
	 * @param mixed $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(mixed $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * A configured key needs a matching Bearer token; without one only a logged-in user passes.
	 *
	 * @return void
	 */
	public function testAuthorization(): void {
		$denied = [401, ['error' => 'Unauthorized']];
		self::assertSame(expected: $denied, actual: self::answer($this->controller(setup: ['header' => ''])->lookup('1')));
		self::assertSame(expected: $denied, actual: self::answer($this->controller(setup: ['header' => 'Bearer wrong'])->lookup('1')));
		self::assertSame(expected: $denied, actual: self::answer($this->controller(setup: ['key' => ''])->lookup('1')));
		$notFound = [404, ['error' => 'Barcode not found']];
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: ['header' => 'bearer   secret '])->lookup(' ')));
		self::assertSame(expected: $notFound, actual: self::answer($this->controller(setup: ['key' => '', 'user' => true])->lookup('')));
		$key      = ['config', 'shillinq', 'barcode_lookup_api_key', ''];
		$expected = [
			$key,
			['getHeader', 'Authorization'],
			$key,
			['getHeader', 'Authorization'],
			$key,
			['getUser'],
			$key,
			['getHeader', 'Authorization'],
			$key,
			['getUser'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testAuthorization()

	/**
	 * The first active barcode for the code and UoM is answered with its product; inactive ones are skipped.
	 *
	 * @return void
	 */
	public function testLookup(): void {
		$barcodes = [
			['id' => 'b0', 'isActive' => false, 'productSku' => 'S0'],
			['id' => 'b1', 'barcode' => '871', 'productSku' => 'S1', 'quantity' => 6, 'internal' => 'x'],
			['id' => 'b2', 'productSku' => 'S2'],
		];
		$products = [5 => ['sku' => 'S1', 'name' => 'Box'], 6 => ['sku' => 'S9']];
		$actual   = self::answer($this->controller(setup: ['barcodes' => $barcodes, 'products' => $products])->lookup(' 871 ', 'CA'));
		$barcode  = [
			'id' => 'b1',
			'barcode' => '871',
			'barcodeType' => null,
			'format' => null,
			'productSku' => 'S1',
			'uomCode' => null,
			'quantity' => 6,
			'isDefault' => false,
			'isActive' => true,
		];
		self::assertSame(expected: [200, ['barcode' => $barcode, 'product' => ['sku' => 'S1', 'name' => 'Box']]], actual: $actual);
		$register = ['config', 'shillinq', 'register', 'shillinq'];
		$expected = [
			['config', 'shillinq', 'barcode_lookup_api_key', ''],
			['getHeader', 'Authorization'],
			['container', 'OCA\OpenRegister\Service\ObjectService'],
			$register,
			['setRegister', 'shillinq'],
			['setSchema', 'Barcode'],
			['findAll', ['filters' => ['barcode' => '871', 'uomCode' => 'CA']]],
			['container', 'OCA\OpenRegister\Service\ObjectService'],
			$register,
			['setRegister', 'shillinq'],
			['setSchema', 'Product'],
			['findAll', ['filters' => ['sku' => 'S1'], 'limit' => 1]],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testLookup()

	/**
	 * No active match is a 404; a missing SKU, an empty or non-array result and a store failure give no product.
	 *
	 * @return void
	 */
	public function testMisses(): void {
		$inactive = ['barcodes' => [['id' => 'b0', 'isActive' => false]]];
		self::assertSame(expected: [404, ['error' => 'Barcode not found']], actual: self::answer($this->controller(setup: $inactive)->lookup('1', '')));
		self::assertSame(expected: ['findAll', ['filters' => ['barcode' => '1']]], actual: $this->calls[6]);
		$noSku = self::answer($this->controller(setup: ['barcodes' => [['id' => 'b1']], 'register' => 'reg'])->lookup('1'));
		self::assertSame(expected: null, actual: $noSku[1]['product']);
		$this->calls = [];
		$scalar      = self::answer($this->controller(setup: ['barcodes' => [['id' => 'b1', 'productSku' => 'S1']], 'products' => 'x'])->lookup('1'));
		self::assertSame(expected: null, actual: $scalar[1]['product']);
		self::assertCount(12, $this->calls);
		$this->calls = [];
		self::assertSame(expected: [404, ['error' => 'Barcode not found']], actual: self::answer($this->controller(setup: ['fail' => true])->lookup('1')));
		$log      = [
			'debug',
			'BarcodeLookupController: schema lookup unavailable — treating as empty',
			['schema' => 'Barcode', 'exception' => 'no openregister'],
		];
		$expected = [
			['config', 'shillinq', 'barcode_lookup_api_key', ''],
			['getHeader', 'Authorization'],
			['container', 'OCA\OpenRegister\Service\ObjectService'],
			$log,
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testMisses()
}//end class
