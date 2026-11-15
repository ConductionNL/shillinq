<?php

/**
 * Unit tests for SeedImporter's import paths: existing rows are skipped,
 * new rows are saved under the register and schema with the administration
 * stamped, the counts are logged and reported, a failure is logged and
 * reported, the system path runs through runAsSystem when the service has it,
 * and a valid seed file is read.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Seed
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

namespace OCA\Shillinq\Tests\Unit\Service\Seed;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for importing seed rows and reporting the outcome.
 *
 * @covers \OCA\Shillinq\Service\Seed\SeedImporter
 * @covers \OCA\Shillinq\Service\Seed\SeedImport
 * @covers \OCA\Shillinq\Service\Seed\SeedMessages
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SeedImporterImportTest extends TestCase {

	private const FIXTURES = '../../../tests/Unit/Service/Seed/fixtures/';

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * Build an importer whose container hands out $objectService.
	 *
	 * @param object $objectService The object-service double.
	 *
	 * @return SeedImporter
	 */
	private function importer(object $objectService): SeedImporter {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('books');
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id !== 'OCA\OpenRegister\Service\ObjectService') {
					throw new RuntimeException('unexpected service ' . $id);
				}

				return $objectService;
			}
		);

		return new SeedImporter(settings: $settings, container: $this->container, logger: $this->logger);
	}//end importer()

	/**
	 * An object-service fake that knows the codes in $existing.
	 *
	 * @param array<int, string> $existing Codes already stored.
	 * @param bool               $failSave Whether saveObject throws.
	 *
	 * @return object
	 */
	private function objects(array $existing = [], bool $failSave = false): object {
		return new class($existing, $failSave) {

			/** @var array<int, string> */
			public array $calls = [];

			/** @var array<int, array{0: array<string, mixed>, 1: mixed, 2: mixed}> */
			public array $saved = [];

			/**
			 * The register last set.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema last set.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int, string> $existing Codes already stored.
			 * @param bool               $failSave Whether saveObject throws.
			 */
			public function __construct(private array $existing, private bool $failSave) {
			}

			/**
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister(mixed $register): self {
				$this->register = (string)$register;
				return $this;
			}

			/**
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(mixed $schema): self {
				$this->schema = (string)$schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = 'findAll ' . $this->register . '/' . $this->schema . ' ' . json_encode($config);
				if (in_array($config['filters']['code'], $this->existing, true) === true) {
					return [['code' => $config['filters']['code']]];
				}

				return [];
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register, mixed $schema): array {
				if ($this->failSave === true) {
					throw new RuntimeException('storage is read-only');
				}

				$this->saved[] = [$object, $register, $schema];
				return $object;
			}
		};
	}//end objects()

	/**
	 * A seed import over $rows matched by code.
	 *
	 * @param array<int, array<string, mixed>> $rows             The rows.
	 * @param string|null                      $administrationId The administration to stamp.
	 *
	 * @return SeedImport
	 */
	private static function import(array $rows, ?string $administrationId = null): SeedImport {
		return new SeedImport(
			schema: 'Ledger',
			rows: $rows,
			match: static fn (array $row): array => ['code' => $row['code']],
			administrationId: $administrationId,
		);
	}//end import()

	/**
	 * Messages with a log context.
	 *
	 * @return SeedMessages
	 */
	private static function messages(): SeedMessages {
		return new SeedMessages(
			seededLog: 'Ledgers seeded',
			seededMessage: 'Ledgers imported',
			failedLog: 'Ledger seed failed',
			logContext: ['file' => 'ledgers.json'],
		);
	}//end messages()

	/**
	 * Existing rows are skipped and the rest saved under the register and schema.
	 *
	 * @return void
	 */
	public function testExistingRowsAreSkippedAndNewRowsSaved(): void {
		$objects = $this->objects(existing: ['b']);

		$result = $this->importer($objects)->import(
			import: self::import([['code' => 'a'], ['code' => 'b'], ['code' => 'c']]),
			messages: self::messages(),
		);

		self::assertSame(['success' => true, 'message' => 'Ledgers imported', 'seeded' => 2, 'skipped' => 1], $result);
		self::assertSame(
			[
				'findAll books/Ledger {"filters":{"code":"a"},"limit":1}',
				'findAll books/Ledger {"filters":{"code":"b"},"limit":1}',
				'findAll books/Ledger {"filters":{"code":"c"},"limit":1}',
			],
			$objects->calls
		);
		self::assertSame([[['code' => 'a'], 'books', 'Ledger'], [['code' => 'c'], 'books', 'Ledger']], $objects->saved);
	}//end testExistingRowsAreSkippedAndNewRowsSaved()

	/**
	 * The administration id is stamped onto every saved row.
	 *
	 * @return void
	 */
	public function testTheAdministrationIsStampedOntoSavedRows(): void {
		$objects = $this->objects();

		$this->importer($objects)->import(
			import: self::import([['code' => 'a', 'administrationId' => 'other']], administrationId: 'adm-7'),
			messages: self::messages(),
		);

		self::assertSame([[['code' => 'a', 'administrationId' => 'adm-7'], 'books', 'Ledger']], $objects->saved);
	}//end testTheAdministrationIsStampedOntoSavedRows()

	/**
	 * The counts are logged with the messages' context.
	 *
	 * @return void
	 */
	public function testTheCountsAreLoggedWithTheContext(): void {
		$this->logger->expects($this->once())->method('info')
			->with('Ledgers seeded', ['file' => 'ledgers.json', 'seeded' => 1, 'skipped' => 0]);
		$this->logger->expects($this->never())->method('error');

		$this->importer($this->objects())->import(import: self::import([['code' => 'a']]), messages: self::messages());
	}//end testTheCountsAreLoggedWithTheContext()

	/**
	 * A failing save is logged and reported as a failure carrying its message.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndReported(): void {
		$this->logger->expects($this->once())->method('error')
			->with('Ledger seed failed', ['exception' => 'storage is read-only']);
		$this->logger->expects($this->never())->method('info');

		$result = $this->importer($this->objects(failSave: true))->import(
			import: self::import([['code' => 'a']]),
			messages: self::messages(),
		);

		self::assertSame(['success' => false, 'message' => 'storage is read-only'], $result);
	}//end testAFailingSaveIsLoggedAndReported()

	/**
	 * Without OpenRegister the container's refusal is reported as the failure.
	 *
	 * @return void
	 */
	public function testAnUnavailableObjectServiceIsReported(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$importer = new SeedImporter(
			settings: $this->createStub(RegisterLocation::class),
			container: $this->container,
			logger: $this->logger,
		);

		self::assertSame(
			['success' => false, 'message' => 'no OpenRegister'],
			$importer->import(import: self::import([['code' => 'a']]), messages: self::messages())
		);
	}//end testAnUnavailableObjectServiceIsReported()

	/**
	 * The system path runs the save through runAsSystem when the service offers it.
	 *
	 * @return void
	 */
	public function testTheSystemImportRunsThroughRunAsSystem(): void {
		$objects = new class {

			/** @var array<int, string> */
			public array $calls = [];

			/**
			 * @param \Closure $work The work to run as the system user.
			 *
			 * @return mixed
			 */
			public function runAsSystem(\Closure $work): mixed {
				$this->calls[] = 'enter system';
				$result = $work();
				$this->calls[] = 'leave system';
				return $result;
			}

			/**
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister(mixed $register): self {
				return $this;
			}

			/**
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(mixed $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register, mixed $schema): array {
				$this->calls[] = 'save ' . $object['code'];
				return $object;
			}
		};

		$result = $this->importer($objects)->importAsSystem(import: self::import([['code' => 'a']]), messages: self::messages());

		self::assertSame(['success' => true, 'message' => 'Ledgers imported', 'seeded' => 1, 'skipped' => 0], $result);
		self::assertSame(['enter system', 'save a', 'leave system'], $objects->calls);
	}//end testTheSystemImportRunsThroughRunAsSystem()

	/**
	 * Without runAsSystem the system path saves directly.
	 *
	 * @return void
	 */
	public function testTheSystemImportSavesDirectlyWithoutRunAsSystem(): void {
		$objects = $this->objects(existing: ['a']);

		$result = $this->importer($objects)->importAsSystem(
			import: self::import([['code' => 'a'], ['code' => 'b']]),
			messages: self::messages(),
		);

		self::assertSame(['success' => true, 'message' => 'Ledgers imported', 'seeded' => 1, 'skipped' => 1], $result);
		self::assertSame([[['code' => 'b'], 'books', 'Ledger']], $objects->saved);
	}//end testTheSystemImportSavesDirectlyWithoutRunAsSystem()

	/**
	 * A valid seed file is decoded.
	 *
	 * @return void
	 */
	public function testAValidSeedFileIsDecoded(): void {
		self::assertSame(
			['data' => [['code' => 'a', 'name' => 'Één'], ['code' => 'b']]],
			$this->importer(new \stdClass())->read(fileName: self::FIXTURES . 'valid-seed.json')
		);
	}//end testAValidSeedFileIsDecoded()

	/**
	 * The failure and nothing-seeded shapes.
	 *
	 * @return void
	 */
	public function testTheFailureAndNothingSeededShapes(): void {
		$importer = $this->importer(new \stdClass());

		self::assertSame(['success' => false, 'message' => 'broken'], $importer->failed(message: 'broken'));
		self::assertSame(
			['success' => true, 'message' => 'nothing to do', 'seeded' => 0, 'skipped' => 0],
			$importer->nothingSeeded(message: 'nothing to do')
		);
	}//end testTheFailureAndNothingSeededShapes()

	/**
	 * The object service is fetched from the container by its OpenRegister id.
	 *
	 * @return void
	 */
	public function testTheObjectServiceComesFromTheContainer(): void {
		$objects = $this->objects();

		self::assertSame($objects, $this->importer($objects)->objectService());
	}//end testTheObjectServiceComesFromTheContainer()
}//end class
