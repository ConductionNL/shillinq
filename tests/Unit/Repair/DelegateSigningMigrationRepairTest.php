<?php

/**
 * Unit tests for DelegateSigningMigrationRepair.
 *
 * Verifies that legacy ACMReport objects carrying a signatureFingerprint are
 * backfilled with signingRequestRef and signingStatus=signed, that objects
 * already migrated are skipped (idempotency), and that per-record failures
 * are handled fail-softly (REQ-SIGN-009).
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
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\DelegateSigningMigrationRepair;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DelegateSigningMigrationRepair.
 *
 * Uses a fake ObjectService that captures all saveObject calls.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DelegateSigningMigrationRepairTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Output mock.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Saved objects captured per-test (wrapped in stdClass for pass-by-ref in anonymous class).
	 *
	 * @var \stdClass
	 */
	private \stdClass $savedBag;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->savedBag = new \stdClass();
		$this->savedBag->data = [];

		$this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build a fake ObjectService backed by the given source records.
	 *
	 * Captures saveObject calls into $this->saved.
	 *
	 * @param array<int,array<string,mixed>> $sourceRecords Records returned by findAll.
	 * @param bool $throwOnSave When true, saveObject throws.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $sourceRecords, bool $throwOnSave = false): object {
		// Pass the savedBag stdClass by value — objects are always passed by reference-to-handle.
		$savedBag = $this->savedBag;

		return new class($sourceRecords, $throwOnSave, $savedBag) {
			/** @var array<int,array<string,mixed>> */
			private array $records;
			private bool $throwOnSave;
			private \stdClass $savedBag;

			/**
			 * @param array<int,array<string,mixed>> $records
			 */
			public function __construct(array $records, bool $throwOnSave, \stdClass $savedBag) {
				$this->records = $records;
				$this->throwOnSave = $throwOnSave;
				$this->savedBag = $savedBag;

			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Mirrors OpenRegister's real findAll() paging semantics: `offset`
			 * skips rows and `limit` is a literal SQL LIMIT (limit=0 returns
			 * ZERO rows), both applied after any filtering — so a caller passing
			 * limit=0 is caught reading nothing here.
			 *
			 * @param array<string,mixed> $params
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params): array {
				$rows = $this->records;

				$filters = ($params['filters'] ?? []);
				if ($filters !== []) {
					$rows = array_values(
						array_filter(
							$rows,
							static function (array $row) use ($filters): bool {
								foreach ($filters as $path => $expected) {
									// OpenRegister does NOT support dot-path filters.
									if (str_contains((string)$path, '.') === true) {
										return false;
									}

									if (($row[$path] ?? null) !== $expected) {
										return false;
									}
								}

								return true;
							}
						)
					);
				}

				$offset = (int)($params['offset'] ?? 0);
				if ($offset > 0) {
					$rows = array_slice($rows, $offset);
				}

				$limit = ($params['limit'] ?? null);
				if ($limit !== null) {
					$rows = array_slice($rows, 0, (int)$limit);
				}

				return array_values($rows);
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): void {
				if ($this->throwOnSave === true) {
					throw new \RuntimeException('saveObject failed');
				}

				$this->savedBag->data[] = $object;

			}//end saveObject()
		};

	}//end fakeObjectService()

	/**
	 * getName returns the expected string.
	 */
	public function testGetName(): void {
		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);

		self::assertStringContainsString('backfill', $step->getName());
		self::assertStringContainsString('REQ-SIGN-009', $step->getName());

	}//end testGetName()

	/**
	 * Objects with a signatureFingerprint and no signingRequestRef are backfilled.
	 */
	public function testBackfillsLegacyObjects(): void {
		$records = [
			['id' => 'acm-1', 'signatureFingerprint' => 'fp-abc123'],
			['id' => 'acm-2', 'signatureFingerprint' => 'fp-def456'],
		];

		$fakeOs = $this->fakeObjectService($records);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(2, $this->savedBag->data);

		self::assertSame('legacy-local:fp-abc123', $this->savedBag->data[0]['signingRequestRef']);
		self::assertSame('signed', $this->savedBag->data[0]['signingStatus']);

		self::assertSame('legacy-local:fp-def456', $this->savedBag->data[1]['signingRequestRef']);
		self::assertSame('signed', $this->savedBag->data[1]['signingStatus']);

	}//end testBackfillsLegacyObjects()

	/**
	 * Objects that already have a signingRequestRef are skipped (idempotency).
	 */
	public function testSkipsAlreadyMigratedObjects(): void {
		$records = [
			['id' => 'acm-3', 'signatureFingerprint' => 'fp-ghi789', 'signingRequestRef' => 'legacy-local:fp-ghi789'],
		];

		$fakeOs = $this->fakeObjectService($records);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(0, $this->savedBag->data, 'Already-migrated object must not be saved again');

	}//end testSkipsAlreadyMigratedObjects()

	/**
	 * Objects without a signatureFingerprint are skipped.
	 */
	public function testSkipsObjectsWithoutFingerprint(): void {
		$records = [
			['id' => 'acm-4', 'status' => 'draft'],
		];

		$fakeOs = $this->fakeObjectService($records);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(0, $this->savedBag->data);

	}//end testSkipsObjectsWithoutFingerprint()

	/**
	 * Per-record saveObject failure is handled fail-softly.
	 */
	public function testSaveObjectFailureIsSoft(): void {
		$records = [
			['id' => 'acm-5', 'signatureFingerprint' => 'fp-jkl012'],
		];

		$fakeOs = $this->fakeObjectService($records, throwOnSave: true);
		$this->container->method('get')->willReturn($fakeOs);

		// Expect a warning on the output.
		$this->output->expects($this->atLeastOnce())->method('warning');

		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);

		// Must NOT throw.
		$step->run($this->output);

		self::assertCount(0, $this->savedBag->data);

	}//end testSaveObjectFailureIsSoft()

	/**
	 * Empty ACMReport set is handled gracefully.
	 */
	public function testEmptySourceHandledGracefully(): void {
		$fakeOs = $this->fakeObjectService([]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new DelegateSigningMigrationRepair(
			settingsService: $this->settingsService,
			logger: $this->logger,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(0, $this->savedBag->data);

	}//end testEmptySourceHandledGracefully()

}//end class
