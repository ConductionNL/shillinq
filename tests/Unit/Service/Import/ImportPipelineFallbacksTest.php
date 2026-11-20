<?php

/**
 * ImportPipelineService without, or with an unreliable, register: mapping
 * without a register leaves every account unmapped, a batch stored as an
 * entity is read through its object, an unreadable stored value stages an
 * empty batch, and a failed save is logged instead of thrown.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportPosting;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pipeline's register fallbacks.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportPipelineService
 * @uses   \OCA\Shillinq\Service\Import\AuditfileParser
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\XafGenericProfile
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ImportPipelineFallbacksTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh logger per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A pipeline over this object service, or over none.
	 *
	 * @param object|null $service The object service, or null for no register.
	 *
	 * @return ImportPipelineService
	 */
	private function pipeline(?object $service): ImportPipelineService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($service === null) {
					throw new RuntimeException('not registered');
				}

				return $service;
			}
		);

		return new ImportPipelineService($container, $this->logger, new AuditfileParser(), new ImportBatchGuard(), $this->createMock(ImportPosting::class));

	}//end pipeline()

	/**
	 * An object service whose find answers this value and whose save throws.
	 *
	 * @param mixed $found The stored value find() answers.
	 *
	 * @return object
	 */
	private function store(mixed $found): object {
		return new class($found) {
			/**
			 * The saves asked for, by schema.
			 *
			 * @var list<string>
			 */
			public array $saves = [];

			/**
			 * The schema in scope.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param mixed $found What find() answers.
			 */
			public function __construct(private readonly mixed $found) {
			}

			/**
			 * Scope.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Scope.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * The stored value.
			 *
			 * @param string $id The id.
			 *
			 * @return mixed
			 */
			public function find(string $id): mixed {
				return $this->found;
			}

			/**
			 * Always fails.
			 *
			 * @param array $object The object.
			 *
			 * @return never
			 */
			public function saveObject(array $object): never {
				$this->saves[] = $this->schema;
				throw new RuntimeException('disk full');
			}
		};

	}//end store()

	/**
	 * Without a register no chart and no profile can be read: every account,
	 * one without a code among them, is left unmapped and the mappings block.
	 *
	 * @return void
	 */
	public function testWithoutARegisterEveryAccountIsLeftUnmapped(): void {
		$batch = [
			'administrationId' => 'adm-1',
			'mappingProfile'   => 'previous-import',
			'stagingPayload'   => ['ledgerAccounts' => [['code' => '', 'name' => 'Kas'], ['code' => '1000', 'name' => 'Bank', 'rgsCode' => 'BLimKas']]],
		];

		$result = $this->pipeline(service: null)->resolveBatchMappings($batch, 'batch-1');

		$this->assertTrue($result['blocking']);
		$this->assertSame(['unmapped', 'unmapped'], array_column($result['mappings'], 'mappingSource'));
		$this->assertSame([null, null], array_column($result['mappings'], 'targetAccount'));
		$this->assertSame([null, 'BLimKas'], array_column($result['mappings'], 'sourceRgsCode'));

	}//end testWithoutARegisterEveryAccountIsLeftUnmapped()

	/**
	 * A batch stored as an entity is read through its object, and the save
	 * that fails afterwards is logged while the staging result still returns.
	 *
	 * @return void
	 */
	public function testAnEntityBatchIsReadThroughItsObjectAndAFailedSaveIsLogged(): void {
		$entity = new class {
			/**
			 * The stored batch.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['id' => 'batch-1', 'administrationId' => 'adm-1', 'sourceSystem' => 'xaf-generic'];
			}
		};
		$store = $this->store(found: $entity);
		$this->logger->expects($this->once())->method('warning')
			->with('ImportPipelineService: persistBatch degraded', ['exception' => 'disk full']);

		$result = $this->pipeline(service: $store)->stage('batch-1');

		$this->assertSame(['source-not-loaded'], array_column($result['findings'], 'code'));
		$this->assertSame(0, $result['stagedCounts']['ledgerAccounts']);
		$this->assertSame(['ImportBatch'], $store->saves);

	}//end testAnEntityBatchIsReadThroughItsObjectAndAFailedSaveIsLogged()

	/**
	 * A stored value that is neither an array nor an entity with an array
	 * reads as an empty batch, which is still staged and saved.
	 *
	 * @return void
	 */
	public function testAStoredValueWithoutAnArrayReadsAsAnEmptyBatch(): void {
		$found = new class implements \JsonSerializable {
			/**
			 * Not an array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'opaque';
			}
		};
		$store = $this->store(found: $found);

		$result = $this->pipeline(service: $store)->stage('batch-1');

		$this->assertArrayHasKey('stagingPayload', $result);
		$this->assertSame(0, $result['stagedCounts']['relations']);
		$this->assertSame(['ImportBatch'], $store->saves);

	}//end testAStoredValueWithoutAnArrayReadsAsAnEmptyBatch()
}//end class
