<?php

/**
 * Behaviour tests for what BackfillGlLineFiscalYear reports and writes.
 *
 * The sibling BackfillGlLineFiscalYearTest pins the register wiring. This file
 * pins the outcomes: a migrator that aborts, lines that disagree with their
 * parent, lines that stay unresolved, rows without an id and saves that fail.
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

use OCA\Shillinq\Kernel\Contracts\GlLineFiscalYearBackfillMigratorInterface;
use OCA\Shillinq\Repair\BackfillGlLineFiscalYear;
use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins BackfillGlLineFiscalYear's outcomes.
 *
 * @covers \OCA\Shillinq\Repair\BackfillGlLineFiscalYear
 *
 * @uses \OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator
 */
final class BackfillGlLineFiscalYearOutcomesTest extends TestCase {

	/**
	 * Output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Logger warnings.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A store over GLLine and GLTransaction rows; saving an id in $failSaves throws.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows      Rows per schema.
	 * @param array<int, string>                              $failSaves Ids whose save throws.
	 *
	 * @return object The store.
	 */
	private function store(array $rows, array $failSaves = []): object {
		return new class($rows, $failSaves) {

			/**
			 * Saved uuids, in order.
			 *
			 * @var array<int, string>
			 */
			public array $saved = [];

			/**
			 * The current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Build it.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows      Rows.
			 * @param array<int, string>                              $failSaves Failing ids.
			 */
			public function __construct(public array $rows, private array $failSaves) {
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * One page of the current schema.
			 *
			 * @param array<string, mixed> $config The options.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = []): array {
				if ((int)($config['offset'] ?? 0) > 0) {
					return [];
				}

				return array_values($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * Save one line in place.
			 *
			 * @param array<string, mixed> $object        The payload.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if (in_array($uuid, $this->failSaves, true) === true) {
					throw new RuntimeException('locked');
				}

				$this->saved[] = (string)$uuid;
				foreach ($this->rows[$schema] as $i => $row) {
					if (($row['id'] ?? null) === $uuid) {
						$this->rows[$schema][$i] = $object;
					}
				}

				return $object;
			}//end saveObject()
		};
	}//end store()

	/**
	 * Run the step over a store.
	 *
	 * @param object                                         $store    The store.
	 * @param GlLineFiscalYearBackfillMigratorInterface|null $migrator The migrator; the real one by default.
	 *
	 * @return void
	 */
	private function runStep(object $store, ?GlLineFiscalYearBackfillMigratorInterface $migrator = null): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('finance');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

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

		$step = new BackfillGlLineFiscalYear(
			registerLocation: $location,
			migrator: ($migrator ?? new GlLineFiscalYearBackfillMigrator()),
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
		);
		self::assertSame('Shillinq: backfill GLLine.fiscalYearId from the parent GLTransaction', $step->getName());
		$step->run($output);
	}//end runStep()

	/**
	 * A migrator that throws aborts the backfill before anything is written.
	 *
	 * @return void
	 */
	public function testAThrowingMigratorAbortsTheBackfill(): void {
		$migrator = $this->createMock(GlLineFiscalYearBackfillMigratorInterface::class);
		$migrator->method('backfillBatch')->willThrowException(new RuntimeException('bad index'));
		$migrator->expects($this->never())->method('countMissingFiscalYearId');
		$store = $this->store(['GLLine' => [['id' => 'l1', 'transactionId' => 'tx-1']]]);

		$this->runStep(store: $store, migrator: $migrator);

		self::assertSame([], $store->saved);
		self::assertSame(['warning: Shillinq: GLLine fiscal-year backfill aborted: bad index'], $this->messages);
		self::assertSame(['Shillinq: GLLine fiscal-year backfill aborted'], $this->warnings);
	}//end testAThrowingMigratorAbortsTheBackfill()

	/**
	 * A stamped line that disagrees with its parent is reported and left as-is.
	 *
	 * @return void
	 */
	public function testADisagreeingLineIsReportedNotRewritten(): void {
		$store = $this->store(
			[
				'GLLine' => [['id' => 'l1', 'transactionId' => 'tx-1', 'fiscalYearId' => 'fy-2025']],
				'GLTransaction' => [['id' => 'tx-1', 'fiscalYearId' => 'fy-2026']],
			]
		);

		$this->runStep(store: $store);

		self::assertSame([], $store->saved);
		self::assertSame(
			[
				'info: Shillinq: GLLine fiscal-year backfill — 1 seen, 0 resolved (0 written), 1 already stamped, 0 unresolvable.',
				'warning: Shillinq: GLLine fiscal-year disagrees with its parent — line at offset 0 carries "fy-2025" while its '
				. 'transaction says "fy-2026". Left as-is; re-pointing a posted line is not a backfill decision.',
				'info: Shillinq: every one of 1 GLLine row(s) now carries a fiscalYearId.',
			],
			$this->messages
		);
	}//end testADisagreeingLineIsReportedNotRewritten()

	/**
	 * Lines whose parent has no year stay unstamped and are counted afterwards.
	 *
	 * @return void
	 */
	public function testUnresolvableLinesAreCountedAfterwards(): void {
		$store = $this->store(
			[
				'GLLine' => [
					['id' => 'l1', 'transactionId' => 'tx-1'],
					['id' => 'l2', 'transactionId' => 'tx-2'],
				],
				'GLTransaction' => [['id' => 'tx-1', 'fiscalYearId' => 'fy-2026'], ['id' => 'tx-2']],
			]
		);

		$this->runStep(store: $store);

		self::assertSame(['l1'], $store->saved);
		self::assertSame(
			'info: Shillinq: GLLine fiscal-year backfill — 2 seen, 1 resolved (1 written), 0 already stamped, 1 unresolvable.',
			$this->messages[0]
		);
		self::assertStringStartsWith('warning: Shillinq: 1 of 2 GLLine row(s) still carry no fiscalYearId', end($this->messages));
	}//end testUnresolvableLinesAreCountedAfterwards()

	/**
	 * A resolved line without an id is skipped, and a failing save is reported per object.
	 *
	 * @return void
	 */
	public function testIdlessRowsAndFailingSavesAreNotWritten(): void {
		$store = $this->store(
			[
				'GLLine' => [
					['id' => ' ', 'transactionId' => 'tx-1'],
					['id' => 'l2', 'transactionId' => 'tx-1'],
					['id' => 'l3', 'transactionId' => 'tx-1'],
				],
				'GLTransaction' => [['id' => 'tx-1', 'fiscalYearId' => 'fy-2026']],
			],
			failSaves: ['l2']
		);

		$this->runStep(store: $store);

		self::assertSame(['l3'], $store->saved);
		self::assertSame(
			[
				'warning: Shillinq: GLLine fiscal-year backfill skipped a row with no object id.',
				'warning: Shillinq: GLLine fiscal-year backfill failed for object l2: locked',
				'info: Shillinq: GLLine fiscal-year backfill — 3 seen, 3 resolved (1 written), 0 already stamped, 0 unresolvable.',
			],
			array_slice($this->messages, 0, 3)
		);
		self::assertStringStartsWith('warning: Shillinq: 2 of 3 GLLine row(s) still carry no fiscalYearId', end($this->messages));
	}//end testIdlessRowsAndFailingSavesAreNotWritten()

	/**
	 * A read that fails is reported as a failed backfill, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingReadIsReported(): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('finance');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Shillinq: GLLine fiscal-year backfill failed', ['exception' => 'store down']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Shillinq: GLLine fiscal-year backfill failed: store down');

		$store = new class {
			/**
			 * Fluent register setter that fails.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				throw new RuntimeException('store down');
			}//end setRegister()
		};

		(new BackfillGlLineFiscalYear(
			registerLocation: $location,
			migrator: new GlLineFiscalYearBackfillMigrator(),
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
		))->run($output);
	}//end testAFailingReadIsReported()
}//end class
