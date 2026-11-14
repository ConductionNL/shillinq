<?php

/**
 * Unit tests for StampJournalTypeOnGlTransactions.
 *
 * Pins the journalType stamp: each ClosingEntry and IntercompanyTransaction
 * marks the GLTransaction it points at with its journal type and carries its
 * own fields over, an already stamped or missing transaction is left alone,
 * a failing row is warned about without stopping the others, and a failure
 * of the whole step is a warning rather than a broken upgrade.
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

use OCA\Shillinq\Repair\StampJournalTypeOnGlTransactions;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for StampJournalTypeOnGlTransactions.
 */
#[CoversClass(StampJournalTypeOnGlTransactions::class)]
#[UsesClass(ReadsSourceRowsInBatches::class)]
#[UsesClass(RunsUnderSystemIdentity::class)]
#[UsesClass(ObjectIdentifier::class)]
class StampJournalTypeOnGlTransactionsTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Info lines.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * Warning lines.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->info = [];
		$this->warnings = [];
	}//end setUp()

	/**
	 * A fake ObjectService: rows per schema, GLTransactions by id, recorded saves.
	 *
	 * @param array<string, array<int, mixed>> $rows         Source rows per schema.
	 * @param array<string, mixed>             $transactions GLTransaction data by id; a Throwable is thrown.
	 * @param array<int, string>               $failSaves    Transaction ids whose save throws.
	 *
	 * @return object
	 */
	private function objects(array $rows, array $transactions, array $failSaves = []): object {
		return new class ($rows, $transactions, $failSaves) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * The saves.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, mixed>> $rows         Rows.
			 * @param array<string, mixed>             $transactions Transactions.
			 * @param array<int, string>               $failSaves    Failing ids.
			 */
			public function __construct(private array $rows, private array $transactions, private array $failSaves) {
			}

			/**
			 * Set the register.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Set the schema.
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
			 * One page of the current schema.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				return array_slice(($this->rows[$this->schema] ?? []), (int)$config['offset'], (int)$config['limit']);
			}

			/**
			 * Find a GLTransaction.
			 *
			 * @param string $id    The id.
			 * @param mixed  ...$named Ignored flags.
			 *
			 * @return object|null
			 */
			public function find(string $id, mixed ...$named): ?object {
				$data = ($this->transactions[$id] ?? null);
				if ($data instanceof \Throwable) {
					throw $data;
				}

				if ($data === null) {
					return null;
				}

				return new class ($data) {
					/**
					 * Constructor.
					 *
					 * @param mixed $data The data.
					 */
					public function __construct(private mixed $data) {
					}

					/**
					 * The data.
					 *
					 * @return mixed
					 */
					public function getObject(): mixed {
						return $this->data;
					}
				};
			}

			/**
			 * Save an object.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac = true, bool $_multitenancy = true): array {
				if (in_array((string)($object['id'] ?? ''), $this->failSaves, true) === true) {
					throw new RuntimeException('locked '.$object['id']);
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $object;
			}
		};
	}//end objects()

	/**
	 * Run the step.
	 *
	 * @param object|null $objects The object service, or null when the container has none.
	 *
	 * @return void
	 */
	private function runStep(?object $objects): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('fin');
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		}

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		(new StampJournalTypeOnGlTransactions($location, $this->logger, $container))->run($output);
	}//end runStep()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new StampJournalTypeOnGlTransactions(
			$this->createMock(RegisterLocation::class),
			$this->logger,
			$this->createMock(ContainerInterface::class)
		);

		$this->assertSame('Shillinq: stamp journalType (closing/intercompany) onto GLTransaction records', $step->getName());
	}//end testName()

	/**
	 * A closing entry stamps its transaction and carries its type, fiscal year and approval over.
	 *
	 * @return void
	 */
	public function testAClosingEntryStampsItsTransaction(): void {
		$objects = $this->objects(
			[
				'ClosingEntry' => [
					['glTransactionId' => 'gl-1', 'entryType' => 'resultaat', 'fiscalYearId' => 'fy-2025', 'approvalStatus' => 'approved'],
					['glTransactionId' => 'gl-2'],
				],
			],
			[
				'gl-1' => ['id' => 'gl-1', 'amount' => 10],
				'gl-2' => ['id' => 'gl-2', 'closingEntryType' => 'keep'],
			]
		);

		$this->runStep($objects);

		$this->assertSame(
			[
				'object' => ['id' => 'gl-1', 'amount' => 10, 'journalType' => 'closing', 'closingEntryType' => 'resultaat', 'fiscalYearId' => 'fy-2025', 'approvalStatus' => 'approved'],
				'register' => 'fin',
				'schema' => 'GLTransaction',
				'rbac' => false,
				'multitenancy' => false,
			],
			$objects->saves[0]
		);
		$this->assertSame(['id' => 'gl-2', 'closingEntryType' => 'keep', 'journalType' => 'closing'], $objects->saves[1]['object']);
		$this->assertSame(['Shillinq: journalType stamp complete — 2 closing, 0 intercompany GLTransaction(s) stamped.'], $this->info);
	}//end testAClosingEntryStampsItsTransaction()

	/**
	 * An intercompany transaction stamps its journal entry and carries every set field over.
	 *
	 * @return void
	 */
	public function testAnIntercompanyTransactionStampsItsJournalEntry(): void {
		$objects = $this->objects(
			[
				'IntercompanyTransaction' => [
					[
						'sourceJournalEntryId' => 'gl-9',
						'sourceAdministrationId' => 'adm-1',
						'counterpartyEntityId' => 'adm-2',
						'detectionMethod' => 'rule',
						'detectionConfidence' => 'high',
						'isMatched' => 1,
						'matchId' => 'm-1',
					],
					['sourceJournalEntryId' => 'gl-8', 'isMatched' => 0],
				],
			],
			['gl-9' => ['id' => 'gl-9'], 'gl-8' => ['id' => 'gl-8']]
		);

		$this->runStep($objects);

		$this->assertSame(
			[
				'id' => 'gl-9',
				'journalType' => 'intercompany',
				'sourceAdministrationId' => 'adm-1',
				'counterpartyEntityId' => 'adm-2',
				'detectionMethod' => 'rule',
				'detectionConfidence' => 'high',
				'isMatched' => true,
				'matchId' => 'm-1',
			],
			$objects->saves[0]['object']
		);
		$this->assertSame(['id' => 'gl-8', 'journalType' => 'intercompany', 'isMatched' => false], $objects->saves[1]['object']);
		$this->assertSame(['Shillinq: journalType stamp complete — 0 closing, 2 intercompany GLTransaction(s) stamped.'], $this->info);
	}//end testAnIntercompanyTransactionStampsItsJournalEntry()

	/**
	 * Rows without a link, links to nothing or to an empty transaction, and
	 * transactions already stamped are left alone.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhereThereIsNothingToStamp(): void {
		$objects = $this->objects(
			[
				'ClosingEntry' => [
					['entryType' => 'resultaat'],
					['glTransactionId' => 'missing'],
					['glTransactionId' => 'empty'],
					['glTransactionId' => 'unreadable'],
					['glTransactionId' => 'closed'],
				],
				'IntercompanyTransaction' => [
					['sourceJournalEntryId' => ''],
					['sourceJournalEntryId' => 'missing'],
					['sourceJournalEntryId' => 'ic'],
				],
			],
			[
				'empty' => [],
				'unreadable' => new RuntimeException('db'),
				'closed' => ['id' => 'closed', 'journalType' => 'closing'],
				'ic' => ['id' => 'ic', 'journalType' => 'intercompany'],
			]
		);

		$this->runStep($objects);

		$this->assertSame([], $objects->saves);
		$this->assertSame([], $this->warnings);
		$this->assertSame(['Shillinq: journalType stamp complete — 0 closing, 0 intercompany GLTransaction(s) stamped.'], $this->info);
	}//end testNothingIsWrittenWhereThereIsNothingToStamp()

	/**
	 * A row whose save fails is warned about and the others are still stamped.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsWarnedAndTheRunGoesOn(): void {
		$objects = $this->objects(
			[
				'ClosingEntry' => [['glTransactionId' => 'bad'], ['glTransactionId' => 'good']],
				'IntercompanyTransaction' => [['sourceJournalEntryId' => 'bad-ic']],
			],
			['bad' => ['id' => 'bad'], 'good' => ['id' => 'good'], 'bad-ic' => ['id' => 'bad-ic']],
			failSaves: ['bad', 'bad-ic']
		);

		$this->runStep($objects);

		$this->assertSame(['good'], array_map(static fn (array $save): string => $save['object']['id'], $objects->saves));
		$this->assertSame(
			[
				'Shillinq: failed to stamp closing GLTransaction: locked bad',
				'Shillinq: failed to stamp intercompany GLTransaction: locked bad-ic',
			],
			$this->warnings
		);
		$this->assertSame(['Shillinq: journalType stamp complete — 1 closing, 0 intercompany GLTransaction(s) stamped.'], $this->info);
	}//end testAFailedSaveIsWarnedAndTheRunGoesOn()

	/**
	 * Rows are read in batches until a short page.
	 *
	 * @return void
	 */
	public function testClosingEntriesAreReadInBatches(): void {
		$entries = [];
		$transactions = [];
		for ($n = 0; $n < 201; $n++) {
			$entries[] = ['glTransactionId' => 'gl-'.$n];
			$transactions['gl-'.$n] = ['id' => 'gl-'.$n];
		}

		$objects = $this->objects(['ClosingEntry' => $entries], $transactions);

		$this->runStep($objects);

		$this->assertCount(201, $objects->saves);
	}//end testClosingEntriesAreReadInBatches()

	/**
	 * Without OpenRegister the step warns and logs instead of failing the upgrade.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepWarns(): void {
		$this->logger->expects($this->once())->method('warning')->with('Shillinq: journalType stamp failed', ['exception' => 'no OpenRegister']);

		$this->runStep(null);

		$this->assertSame(['Shillinq: journalType stamp failed: no OpenRegister'], $this->warnings);
		$this->assertSame([], $this->info);
	}//end testWithoutOpenRegisterTheStepWarns()
}//end class
