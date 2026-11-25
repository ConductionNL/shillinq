<?php

/**
 * HumaniqHoursReader Unit Tests
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\HrmqCostRateAdapter;
use OCA\Shillinq\Service\HumaniqHoursReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The ledger's read of humaniq hours: one scoped read per run, absent is not zero.
 *
 * @covers \OCA\Shillinq\Service\HumaniqHoursReader
 *
 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class HumaniqHoursReaderTest extends TestCase {
	/**
	 * The query the last findAll() received.
	 *
	 * @var array<string, mixed>
	 */
	private array $lastQuery = [];

	/**
	 * How many reads the stub answered.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Build a reader over a stub ObjectService.
	 *
	 * @param mixed $rows   Rows findAll() answers with.
	 * @param bool  $throws Whether the read blows up (humaniq absent).
	 *
	 * @return HumaniqHoursReader The reader.
	 */
	private function reader(mixed $rows, bool $throws = false): HumaniqHoursReader {
		$test = $this;
		$objectService = new class($rows, $throws, $test) {
			/**
			 * @param mixed                  $rows   Rows to answer with.
			 * @param bool                   $throws Whether to blow up.
			 * @param HumaniqHoursReaderTest $test   The test, for query capture.
			 */
			public function __construct(
				private mixed $rows,
				private bool $throws,
				private HumaniqHoursReaderTest $test,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				if ($this->throws === true) {
					throw new RuntimeException('register ' . $register . ' not found');
				}

				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed The rows.
			 */
			public function findAll(array $query): mixed {
				$this->test->capture($query);
				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$adapter = $this->createMock(HrmqCostRateAdapter::class);
		$adapter->method('registerSlug')->willReturn('humaniq');

		return new HumaniqHoursReader($container, $adapter, new NullLogger());
	}

	/**
	 * Record the query the stub was asked.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return void
	 */
	public function capture(array $query): void {
		$this->lastQuery = $query;
		$this->reads++;
	}

	/**
	 * One read, scoped to the administration and the period, keyed by uuid.
	 *
	 * @return void
	 */
	public function testOneScopedReadKeyedByUuid(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return [
					'uuid' => 'te-2', 'employeeId' => 'p2', 'date' => '2026-03-02', 'hours' => '1.5',
					'administrationId' => 'adm-1', 'billable' => true, 'approvalState' => 'approved',
					'domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'case-9',
				];
			}
		};
		$reader = $this->reader(
			[
				['uuid' => 'te-1', 'employeeId' => 'p1', 'date' => '2026-03-01', 'hours' => 2, 'administrationId' => 'adm-1'],
				$entity,
			]
		);

		$entries = $reader->entriesInPeriod('adm-1', '2026-03-01', '2026-03-31');

		self::assertSame(1, $this->reads);
		self::assertSame(
			['administrationId' => 'adm-1', 'date' => ['gte' => '2026-03-01', 'lte' => '2026-03-31']],
			$this->lastQuery['filters']
		);
		self::assertSame(['te-1', 'te-2'], array_keys($entries));
		self::assertSame(2.0, $entries['te-1']['hours']);
		self::assertSame('p1', $entries['te-1']['personId']);
		self::assertFalse($entries['te-1']['billable']);
		self::assertSame(1.5, $entries['te-2']['hours']);
		self::assertTrue($entries['te-2']['billable']);
		self::assertSame('dossiq:case', $entries['te-2']['domainObjectType']);
		self::assertSame('case-9', $entries['te-2']['domainObjectRef']);
	}

	/**
	 * Humaniq absent answers null, never an empty or zero result.
	 *
	 * @return void
	 */
	public function testAbsentHumaniqIsNullNotEmpty(): void {
		self::assertNull($this->reader([], true)->entriesInPeriod('adm-1', '2026-01-01', '2026-12-31'));
		self::assertNull($this->reader('not a list')->entriesInPeriod('adm-1', '2026-01-01', '2026-12-31'));
	}

	/**
	 * Humaniq present with no hours answers an empty list.
	 *
	 * @return void
	 */
	public function testNoHoursIsAnEmptyList(): void {
		self::assertSame([], $this->reader([])->entriesInPeriod('adm-1', '2026-01-01', '2026-12-31'));
	}

	/**
	 * Rows of another administration, rows without a uuid and unscoped asks are dropped.
	 *
	 * @return void
	 */
	public function testOutOfScopeRowsAndUnscopedAsksAreRefused(): void {
		$reader = $this->reader(
			[
				['uuid' => 'te-1', 'hours' => 1, 'administrationId' => 'adm-2'],
				['hours' => 1, 'administrationId' => 'adm-1'],
				['id' => 'te-3', 'administrationId' => 'adm-1'],
			]
		);

		$entries = $reader->entriesInPeriod('adm-1', '2026-01-01', '2026-12-31');
		self::assertSame(['te-3'], array_keys($entries));
		self::assertNull($entries['te-3']['hours']);

		$this->reads = 0;
		self::assertSame([], $reader->entriesInPeriod(' ', '2026-01-01', '2026-12-31'));
		self::assertSame([], $reader->entriesInPeriod('adm-1', '', '2026-12-31'));
		self::assertSame(0, $this->reads);
	}

	/**
	 * The project read: one query scoped to the project and the period, other projects dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	public function testTheProjectReadIsScopedToTheProject(): void {
		$reader = $this->reader(
			[
				['uuid' => 'te-1', 'hours' => 2, 'projectId' => 'prj-1', 'description' => ' Design review '],
				['uuid' => 'te-2', 'hours' => 1, 'projectId' => 'prj-2'],
			]
		);

		$entries = $reader->entriesForProject('prj-1', '2026-10-01', '2026-10-31');
		self::assertSame(1, $this->reads);
		self::assertSame(
			['filters' => ['projectId' => 'prj-1', 'date' => ['gte' => '2026-10-01', 'lte' => '2026-10-31']]],
			$this->lastQuery
		);
		self::assertSame(['te-1'], array_keys($entries));
		self::assertSame('Design review', $entries['te-1']['description']);

		$this->reads = 0;
		self::assertSame([], $reader->entriesForProject('', '2026-10-01', '2026-10-31'));
		self::assertSame(0, $this->reads);
	}

	/**
	 * Humaniq not answering the project read is null, not zero hours.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	public function testTheProjectReadIsNullWhenHumaniqDoesNotAnswer(): void {
		self::assertNull($this->reader([], true)->entriesForProject('prj-1', '2026-10-01', '2026-10-31'));
	}
}
