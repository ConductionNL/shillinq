<?php

/**
 * Unit tests for the stored-batch paths of ImportPipelineService.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Service\Import\AuditfileParser;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportPosting;
use OCA\Shillinq\Service\Import\ImportProfile\EBoekhoudenProfile;
use OCA\Shillinq\Service\Import\ImportProfile\ExactOnlineProfile;
use OCA\Shillinq\Service\Import\ImportProfile\MoneybirdProfile;
use OCA\Shillinq\Service\Import\ImportProfile\SnelstartProfile;
use OCA\Shillinq\Service\Import\ImportProfile\XafGenericProfile;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Staging and mapping a batch read from, and written back to, the register.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportPipelineService
 * @uses   \OCA\Shillinq\Service\Import\AuditfileParser
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\EBoekhoudenProfile
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\ExactOnlineProfile
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\MoneybirdProfile
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\SnelstartProfile
 * @uses   \OCA\Shillinq\Service\Import\ImportProfile\XafGenericProfile
 */
final class ImportPipelinePersistenceTest extends TestCase {

	/**
	 * Every object saved, in order.
	 *
	 * @var array<int, array{schema: string, object: array}>
	 */
	private array $saved = [];

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
		$this->saved = [];
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A batch the store cannot read stages nothing, says so, and logs why.
	 *
	 * @return void
	 */
	public function testStagingAnUnreadableBatchReportsIt(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('ImportPipelineService: loadBatch failed', ['batchId' => 'batch-x', 'exception' => 'db down']);

		$result = $this->pipeline(service: $this->failingService())->stage('batch-x');

		$this->assertSame(
			['findings' => [['severity' => 'error', 'code' => 'batch-not-found', 'message' => 'Import batch not found.', 'context' => ['batchId' => 'batch-x']]]],
			$result
		);
	}//end testStagingAnUnreadableBatchReportsIt()

	/**
	 * Without OpenRegister no batch can be read, so none is found.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceNoBatchIsFound(): void {
		$result = $this->pipeline(data: null)->resolveMappings('batch-1');

		$this->assertSame([], $result['mappings']);
		$this->assertTrue($result['blocking']);
		$this->assertSame('batch-not-found', $result['findings'][0]['code']);
	}//end testWithoutAnObjectServiceNoBatchIsFound()

	/**
	 * A stored batch without its source contents stages empty, warns, and is
	 * saved back with its staging payload and counts.
	 *
	 * @return void
	 */
	public function testAStoredBatchIsStagedAndSavedBack(): void {
		$batch = ['id' => 'batch-1', 'administrationId' => 'adm-1', 'sourceSystem' => 'moneybird'];

		$result = $this->pipeline(data: ['ImportBatch' => [$batch]])->stage('batch-1');

		$this->assertSame(['source-not-loaded'], array_column($result['findings'], 'code'));
		$this->assertSame(
			['ledgerAccounts' => 0, 'openingBalanceLines' => 0, 'arOpenItems' => 0, 'apOpenItems' => 0, 'relations' => 0],
			$result['stagedCounts']
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame('ImportBatch', $this->saved[0]['schema']);
		$this->assertSame('batch-1', $this->saved[0]['object']['id']);
		$this->assertSame($result['stagingPayload'], $this->saved[0]['object']['stagingPayload']);
		$this->assertSame($result['stagedCounts'], $this->saved[0]['object']['stagedCounts']);
	}//end testAStoredBatchIsStagedAndSavedBack()

	/**
	 * Each staged account is mapped by RGS, then by the saved profile, then by
	 * an identical account number, else left unmapped; every mapping is saved.
	 *
	 * @return void
	 */
	public function testMappingsResolveInPrecedenceOrderAndAreSaved(): void {
		$batch = [
			'id' => 'batch-1',
			'administrationId' => 'adm-1',
			'mappingProfile' => 'exact-default',
			'stagingPayload' => [
				'ledgerAccounts' => [
					['code' => '1000', 'name' => 'Kas', 'rgsCode' => 'BLimKas'],
					['code' => '1300', 'name' => 'Debiteuren'],
					['code' => '4000', 'name' => 'Huur'],
					['code' => '9999', 'name' => 'Onbekend'],
				],
			],
		];
		$data = [
			'ImportBatch' => [$batch],
			'Account' => [
				['id' => 'a1', 'administrationId' => 'adm-1', 'accountNumber' => '1010', 'rgsCode' => 'BLimKas'],
				['id' => 'a2', 'administrationId' => 'adm-1', 'code' => '4000'],
				['id' => 'a3', 'administrationId' => 'adm-1', 'accountNumber' => ''],
				['id' => 'a4', 'administrationId' => 'adm-2', 'accountNumber' => '9999'],
			],
			'ImportMapping' => [
				['id' => 'm1', 'mappingProfile' => 'exact-default', 'confirmed' => true, 'sourceCode' => '1300', 'targetAccount' => '1310'],
				['id' => 'm2', 'mappingProfile' => 'exact-default', 'confirmed' => false, 'sourceCode' => '9999', 'targetAccount' => '1000'],
				['id' => 'm3', 'mappingProfile' => 'other', 'confirmed' => true, 'sourceCode' => '4000', 'targetAccount' => '8000'],
			],
		];

		$result = $this->pipeline(data: $data)->resolveMappings('batch-1');

		$this->assertSame(
			[
				['1000', '1010', 'rgs-auto', true, 'BLimKas'],
				['1300', '1310', 'profile-default', true, null],
				['4000', '4000', 'manual', false, null],
				['9999', null, 'unmapped', false, null],
			],
			array_map(
				static fn (array $row): array => [$row['sourceCode'], $row['targetAccount'], $row['mappingSource'], $row['confirmed'], $row['sourceRgsCode']],
				$result['mappings']
			)
		);
		$this->assertTrue($result['blocking']);
		$this->assertSame('batch-1', $result['mappings'][0]['batchReference']);
		$this->assertSame('adm-1', $result['mappings'][0]['administrationId']);
		$this->assertSame(['ImportMapping', 'ImportMapping', 'ImportMapping', 'ImportMapping'], array_column($this->saved, 'schema'));
	}//end testMappingsResolveInPrecedenceOrderAndAreSaved()

	/**
	 * Store failures degrade: the batch is still mapped, unmapped, and the
	 * failures are logged as warnings.
	 *
	 * @return void
	 */
	public function testAFailingStoreDegradesToUnmapped(): void {
		$service = $this->failingService();
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$result = $this->pipeline(service: $service)->resolveBatchMappings(
			batch: ['administrationId' => 'adm-1', 'mappingProfile' => 'p', 'stagingPayload' => ['ledgerAccounts' => [['code' => '1000']]]],
			batchId: 'batch-1'
		);

		$this->assertSame('unmapped', $result['mappings'][0]['mappingSource']);
		$this->assertTrue($result['blocking']);
		$this->assertSame(
			[
				'ImportPipelineService: loadTargetChart degraded',
				'ImportPipelineService: loadMappingProfile degraded',
				'ImportPipelineService: persistMapping degraded',
			],
			$warnings
		);
	}//end testAFailingStoreDegradesToUnmapped()

	/**
	 * Each source system gets its own profile; anything else the generic one.
	 *
	 * @return void
	 */
	public function testEachSourceSystemGetsItsProfile(): void {
		$pipeline = $this->pipeline(data: null);

		$this->assertInstanceOf(EBoekhoudenProfile::class, $pipeline->profileFor('e-boekhouden'));
		$this->assertInstanceOf(ExactOnlineProfile::class, $pipeline->profileFor('exact-online'));
		$this->assertInstanceOf(MoneybirdProfile::class, $pipeline->profileFor('moneybird'));
		$this->assertInstanceOf(SnelstartProfile::class, $pipeline->profileFor('snelstart'));
		$this->assertInstanceOf(XafGenericProfile::class, $pipeline->profileFor('xaf-generic'));
		$this->assertInstanceOf(XafGenericProfile::class, $pipeline->profileFor('unknown'));
	}//end testEachSourceSystemGetsItsProfile()

	/**
	 * An object service whose every read and write fails.
	 *
	 * @return object
	 */
	private function failingService(): object {
		return new class {
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
				return $this;
			}

			/**
			 * Always fails.
			 *
			 * @param string $id The id.
			 *
			 * @return object
			 */
			public function find(string $id): object {
				throw new RuntimeException('db down');
			}

			/**
			 * Always fails.
			 *
			 * @param array $config The query.
			 *
			 * @return array
			 */
			public function findAll(array $config = []): array {
				throw new RuntimeException('db down');
			}

			/**
			 * Always fails.
			 *
			 * @param array $object The object.
			 *
			 * @return object
			 */
			public function saveObject(array $object): object {
				throw new RuntimeException('read only');
			}
		};
	}//end failingService()

	/**
	 * A pipeline whose container holds the given object service.
	 *
	 * @param array|null  $data    Rows per schema for an in-memory store, or null for none.
	 * @param object|null $service A service to use instead.
	 *
	 * @return ImportPipelineService
	 */
	private function pipeline(?array $data = null, ?object $service = null): ImportPipelineService {
		if ($service === null && $data !== null) {
			$service = new InMemoryObjectServiceStub(data: $data, saveSink: $this->saved);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id !== 'OCA\OpenRegister\Service\ObjectService' || $service === null) {
					throw new RuntimeException('not registered');
				}

				return $service;
			}
		);

		return new ImportPipelineService(
			$container,
			$this->logger,
			new AuditfileParser(),
			new ImportBatchGuard(),
			$this->createMock(ImportPosting::class)
		);
	}//end pipeline()
}//end class
