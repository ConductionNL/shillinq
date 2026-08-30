<?php

/**
 * Unit tests for BbvSeedService — covers idempotent seed imports.
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
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md#seed-data
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BbvSeedService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers Task 5.12: the BBV seed repair step imports all catalogues
 * idempotently and re-runs detect existing rows and skip them.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md#seed-data
 */
class BbvSeedServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the test fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $appId, string $key, string $default): string {
				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			});

	}//end setUp()

	/**
	 * On a fresh install the seed service imports every catalogue and
	 * reports per-schema counts.
	 *
	 * @return void
	 */
	public function testFreshSeedImportsAllCatalogues(): void {
		$objectService = $this->buildObjectServiceStub(existingRows: []);
		$this->container->method('get')->willReturn($objectService);

		$service = new BbvSeedService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$result = $service->seedAll();

		self::assertTrue(condition: $result['success']);
		self::assertArrayHasKey(key: 'Taakveld', array: $result['counts']);
		self::assertArrayHasKey(key: 'EconomischeCategorie', array: $result['counts']);
		self::assertArrayHasKey(key: 'BeleidsIndicator', array: $result['counts']);
		self::assertArrayHasKey(key: 'BbvAccountMapping', array: $result['counts']);

	}//end testFreshSeedImportsAllCatalogues()

	/**
	 * Composite dedup on (code, overheidslaag) for Taakveld permits the same
	 * numeric code across overheidslagen (gemeente / provincie / waterschap).
	 *
	 * @return void
	 */
	public function testTaakveldCompositeDedupAcrossOverheidslagen(): void {
		// Pre-existing gemeente row should not skip the provincie + waterschap rows
		// for the same numeric code (0.10).
		$existingRows = [
			['code' => '0.10', 'overheidslaag' => 'municipality'],
		];

		$objectService = $this->buildObjectServiceStub(existingRows: $existingRows);
		$this->container->method('get')->willReturn($objectService);

		$service = new BbvSeedService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$result = $service->seedAll();

		self::assertTrue(condition: $result['success']);
		$taskFieldCounts = $result['counts']['Taakveld'];
		// Gemeente 0.10 must be skipped; provincie + waterschap 0.10 must be seeded.
		self::assertGreaterThan(0, $taskFieldCounts['seeded']);
		self::assertGreaterThan(0, $taskFieldCounts['skipped']);

	}//end testTaakveldCompositeDedupAcrossOverheidslagen()

	/**
	 * When ObjectService is unavailable the seed service fails gracefully.
	 *
	 * @return void
	 */
	public function testMissingObjectServiceFailsGracefully(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not available'));

		$service = new BbvSeedService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$result = $service->seedAll();

		self::assertFalse(condition: $result['success']);
		self::assertArrayHasKey(key: 'message', array: $result);

	}//end testMissingObjectServiceFailsGracefully()

	/**
	 * Build a fluent ObjectService stub matched by composite filters.
	 *
	 * @param array<int,array<string,mixed>> $existingRows Pre-existing rows used to test dedup.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $existingRows): object {
		return new class($existingRows) {
			/**
			 * Pre-existing rows used to test dedup.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $existingRows;

			/**
			 * Captured saveObject calls (for inspection by tests).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * The pending schema for the next findAll/saveObject call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Initialise the fluent stub.
			 *
			 * @param array<int,array<string,mixed>> $existingRows Pre-existing rows.
			 */
			public function __construct(array $existingRows) {
				$this->existingRows = $existingRows;
			}//end __construct()

			/**
			 * Fluent setter — register is ignored by the stub.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent setter capturing the schema for the next call.
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
			 * Filter pre-existing rows by the given filter map.
			 *
			 * @param array<string,mixed> $params Query params (filters + limit).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				foreach ($this->existingRows as $row) {
					$matches = true;
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						return [$row];
					}
				}

				return [];
			}//end findAll()

			/**
			 * Record the save call so it counts as a seed.
			 *
			 * @param array<string,mixed> $object The object to save.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return void
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				// Add to existingRows so a subsequent invocation in the same run dedupes.
				$this->existingRows[] = $object;
			}//end saveObject()
		};

	}//end buildObjectServiceStub()
}//end class
