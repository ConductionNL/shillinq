<?php

/**
 * Behaviour tests for the export payload MigrateProductVendorMasterToPipelinq writes.
 *
 * Covers the idempotency guard, the deferral when OpenRegister is absent, the
 * sku-to-id map, schema reads that fail soft, and the vendor-to-contact
 * matching chain (KvK, BTW, name+IBAN, fuzzy name, placeholder).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\MigrateProductVendorMasterToPipelinq;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the export payload and the vendor contact matching.
 *
 * @covers \OCA\Shillinq\Repair\MigrateProductVendorMasterToPipelinq
 */
final class MigrateProductVendorMasterToPipelinqExportTest extends TestCase {

	/**
	 * App values written through IConfig::setAppValue(), by key.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Collected output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Logger warnings, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The contacts manager double.
	 *
	 * @var IContactsManager&MockObject
	 */
	private IContactsManager&MockObject $contacts;

	/**
	 * A store answering findAll per schema; a schema mapped to null throws.
	 *
	 * @param array<string, array<int, mixed>|null> $rows Rows per schema.
	 *
	 * @return object The ObjectService double.
	 */
	private function store(array $rows): object {
		return new class($rows) {

			/**
			 * The schema the next findAll reads.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Build it.
			 *
			 * @param array<string, array<int, mixed>|null> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * The rows of the current schema.
			 *
			 * @param array<string, mixed> $config The findAll options.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = []): array {
				if (array_key_exists($this->schema, $this->rows) === true && $this->rows[$this->schema] === null) {
					throw new RuntimeException('schema ' . $this->schema . ' missing');
				}

				if (($config['offset'] ?? 0) !== 0) {
					return [];
				}

				return ($this->rows[$this->schema] ?? []);
			}//end findAll()
		};
	}//end store()

	/**
	 * Build the step.
	 *
	 * @param object|null $store  The ObjectService, or null when OpenRegister is absent.
	 * @param string      $status The stored migration status.
	 *
	 * @return MigrateProductVendorMasterToPipelinq The step under test.
	 */
	private function makeStep(?object $store, string $status = ''): MigrateProductVendorMasterToPipelinq {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('shillinq', 'shillinq_pvm_migration_status', '')->willReturn($status);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->written[$key] = $value;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('finance');

		$this->contacts = $this->createMock(IContactsManager::class);

		return new MigrateProductVendorMasterToPipelinq(
			config: $config,
			logger: $logger,
			registerLocation: $location,
			container: $container,
			contactsManager: $this->contacts,
		);
	}//end makeStep()

	/**
	 * An output double that records every message with its level.
	 *
	 * @return IOutput The output double.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'warning: ' . $message;
			}
		);

		return $output;
	}//end recordingOutput()

	/**
	 * The export written to app-config, decoded.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function export(): array {
		self::assertArrayHasKey('shillinq_pvm_export', $this->written);
		$payload = json_decode($this->written['shillinq_pvm_export'], true);
		self::assertIsArray($payload);
		return $payload;
	}//end export()

	/**
	 * A completed migration is a no-op.
	 *
	 * @return void
	 */
	public function testACompletedMigrationIsSkipped(): void {
		$this->makeStep(store: $this->store([]), status: 'completed')->run($this->recordingOutput());

		self::assertSame([], $this->written);
		self::assertSame(['info: MigrateProductVendorMasterToPipelinq: already completed — skipping.'], $this->messages);
	}//end testACompletedMigrationIsSkipped()

	/**
	 * Without OpenRegister the migration is deferred and not marked completed.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheMigrationIsDeferred(): void {
		$this->makeStep(store: null)->run($this->recordingOutput());

		self::assertSame([], $this->written);
		self::assertStringStartsWith('warning: MigrateProductVendorMasterToPipelinq: OpenRegister not available', end($this->messages));
		self::assertSame(
			['MigrateProductVendorMasterToPipelinq: OpenRegister ObjectService not available: no OpenRegister'],
			$this->warnings
		);
	}//end testWithoutOpenRegisterTheMigrationIsDeferred()

	/**
	 * Products and attributes are exported, and the sku map uses sku, then code, and id, then uuid.
	 *
	 * @return void
	 */
	public function testProductsAreExportedWithTheirSkuMap(): void {
		$entity = new class {
			/**
			 * The OR payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['code' => 'C-2', 'uuid' => 'u-2', 'name' => 'Bolt'];
			}//end getObject()
		};

		$store = $this->store(
			[
				'Product' => [
					['sku' => 'S-1', 'id' => 'p-1', 'name' => 'Nut'],
					$entity,
					['name' => 'No sku', 'id' => 'p-3'],
				],
				'ProductAttribute' => [['name' => 'Colour']],
				'VendorMaster' => [],
			]
		);

		$this->makeStep(store: $store)->run($this->recordingOutput());

		$payload = $this->export();
		self::assertSame('1.0', $payload['version']);
		self::assertSame(['S-1' => 'p-1', 'C-2' => 'u-2'], $payload['skuToProductIdMap']);
		self::assertSame('Bolt', $payload['products'][1]['name']);
		self::assertSame([['name' => 'Colour']], $payload['productAttributes']);
		self::assertSame([], $payload['vendorMasterData']);
		self::assertSame('completed', $this->written['shillinq_pvm_migration_status']);
		self::assertContains(
			'info: MigrateProductVendorMasterToPipelinq: export written to app-config key shillinq_pvm_export. '
			. 'Products: 3, ProductAttributes: 1, VendorMasterData: 0',
			$this->messages
		);
		self::assertContains('info: MigrateProductVendorMasterToPipelinq: read 3 Product records.', $this->messages);
		self::assertContains('info: MigrateProductVendorMasterToPipelinq: no VendorMaster records found — exporting empty set.', $this->messages);
	}//end testProductsAreExportedWithTheirSkuMap()

	/**
	 * A schema that cannot be read exports an empty set and the step still completes.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaExportsAnEmptySet(): void {
		$store = $this->store(['Product' => null, 'ProductAttribute' => [], 'VendorMaster' => []]);

		$this->makeStep(store: $store)->run($this->recordingOutput());

		self::assertSame([], $this->export()['products']);
		self::assertSame('completed', $this->written['shillinq_pvm_migration_status']);
		self::assertContains(
			'info: MigrateProductVendorMasterToPipelinq: Product schema not available (schema Product missing) — exporting empty set.',
			$this->messages
		);
		self::assertSame(['MigrateProductVendorMasterToPipelinq: could not read Product objects: schema Product missing'], $this->warnings);
	}//end testAnUnreadableSchemaExportsAnEmptySet()

	/**
	 * Run the step over one vendor and return its exported contact uid.
	 *
	 * @param array<string, mixed> $vendor  The VendorMaster row.
	 * @param callable             $answers Maps (pattern, properties) to search results.
	 *
	 * @return string The resolvedContactsUid.
	 */
	private function resolve(array $vendor, callable $answers): string {
		$step = $this->makeStep(store: $this->store(['VendorMaster' => [$vendor]]));
		$this->contacts->method('search')->willReturnCallback(
			fn (string $pattern, array $properties) => $answers($pattern, $properties)
		);

		$step->run($this->recordingOutput());

		return $this->export()['vendorMasterData'][0]['resolvedContactsUid'];
	}//end resolve()

	/**
	 * A unique KvK match wins first.
	 *
	 * @return void
	 */
	public function testAKvkMatchWins(): void {
		$uid = $this->resolve(
			vendor: ['slug' => 'acme', 'kvkNumber' => '123', 'vatNumber' => 'NL1'],
			answers: fn (string $p, array $props): array => match ($props) {
				['X-CUSTOM-KVK'] => [['URI' => 'c-kvk']],
				default => [['URI' => 'c-other']],
			}
		);

		self::assertSame('c-kvk', $uid);
		self::assertContains('info: MigrateProductVendorMasterToPipelinq: matched vendor "acme" to contact via KvK 123.', $this->messages);
	}//end testAKvkMatchWins()

	/**
	 * An ambiguous KvK result falls through to the BTW number, read from UID.
	 *
	 * @return void
	 */
	public function testAnAmbiguousKvkFallsThroughToBtw(): void {
		$uid = $this->resolve(
			vendor: ['slug' => 'acme', 'kvkNumber' => '123', 'vatNumber' => 'NL1'],
			answers: fn (string $p, array $props): array => match ($props) {
				['X-CUSTOM-KVK'] => [['URI' => 'a'], ['URI' => 'b']],
				['X-CUSTOM-BTW'] => [['UID' => 'c-btw']],
				default => [],
			}
		);

		self::assertSame('c-btw', $uid);
		self::assertContains('info: MigrateProductVendorMasterToPipelinq: matched vendor "acme" to contact via BTW NL1.', $this->messages);
	}//end testAnAmbiguousKvkFallsThroughToBtw()

	/**
	 * Name plus IBAN matches only the contact whose IBAN equals, ignoring spaces and case.
	 *
	 * @return void
	 */
	public function testNameAndIbanMatchTheContactWithThatIban(): void {
		$uid = $this->resolve(
			vendor: ['slug' => 'acme', 'name' => 'Acme', 'iban' => 'nl91 abna 0417 1643 00'],
			answers: fn (string $p, array $props): array => [
				['URI' => 'c-wrong', 'IBAN' => 'NL00BANK0000000000'],
				['URI' => 'c-right', 'X-CUSTOM-IBAN' => 'NL91ABNA0417164300'],
			]
		);

		self::assertSame('c-right', $uid);
		self::assertContains('info: MigrateProductVendorMasterToPipelinq: matched vendor "acme" to contact via name+IBAN.', $this->messages);
	}//end testNameAndIbanMatchTheContactWithThatIban()

	/**
	 * A name-only match is logged for review but never applied; the vendor gets a placeholder.
	 *
	 * @return void
	 */
	public function testANameOnlyMatchIsNotApplied(): void {
		$uid = $this->resolve(
			vendor: ['id' => 'v-9', 'tradingName' => 'Acme'],
			answers: fn (string $p, array $props): array => match ([$p, $props]) {
				['Acme', ['FN']] => [['URI' => 'c-fuzzy']],
				default => [],
			}
		);

		self::assertSame('placeholder-contactsuid-v-9-' . date('Y'), $uid);
		self::assertStringContainsString('fuzzy name-only match for vendor "v-9" → contact UID "c-fuzzy"; NOT auto-applied.', $this->warnings[0]);
		self::assertStringContainsString('no NC Contact match for vendor "v-9"', $this->warnings[1]);
	}//end testANameOnlyMatchIsNotApplied()

	/**
	 * A failing contacts search, or a match without an id, leaves the placeholder.
	 *
	 * @return void
	 */
	public function testFailingOrIdlessSearchesLeaveThePlaceholder(): void {
		$uid = $this->resolve(
			vendor: ['kvkNumber' => '123', 'name' => 'Acme', 'iban' => 'NL1'],
			answers: function (string $p, array $props): array {
				if ($props === ['X-CUSTOM-KVK']) {
					throw new RuntimeException('dav down');
				}

				return [['IBAN' => 'NL1']];
			}
		);

		self::assertSame('placeholder-contactsuid-unknown-' . date('Y'), $uid);
		self::assertSame(
			'MigrateProductVendorMasterToPipelinq: Contacts search for property=X-CUSTOM-KVK value=123 failed: dav down',
			$this->warnings[0]
		);
	}//end testFailingOrIdlessSearchesLeaveThePlaceholder()

	/**
	 * A failing name+IBAN search is logged and treated as no match.
	 *
	 * @return void
	 */
	public function testAFailingNameAndIbanSearchIsNoMatch(): void {
		$uid = $this->resolve(
			vendor: ['slug' => 's', 'name' => 'Acme', 'iban' => 'NL1'],
			answers: function (): array {
				throw new RuntimeException('dav down');
			}
		);

		self::assertSame('placeholder-contactsuid-s-' . date('Y'), $uid);
		self::assertSame('MigrateProductVendorMasterToPipelinq: Contacts name+IBAN search failed: dav down', $this->warnings[0]);
	}//end testAFailingNameAndIbanSearchIsNoMatch()
}//end class
