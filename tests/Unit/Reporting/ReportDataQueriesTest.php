<?php

/**
 * Unit tests for the data access every CSV/XML report generator shares
 * (ReportDataTrait), driven through the trial balance: the queries it sends
 * for each context, what happens when the store fails, how rows are
 * normalised, how accounts are keyed and how the file is named.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
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

namespace OCA\Shillinq\Tests\Unit\Reporting;

use JsonSerializable;
use OCA\Shillinq\Reporting\Generator\TrialBalanceReportGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared report data access, seen through TrialBalanceReportGenerator.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\TrialBalanceReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\ReportDataTrait
 * @uses   \OCA\Shillinq\Kernel\Model\GeneratedFile
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReportDataQueriesTest extends TestCase {

	/**
	 * Every query the store received, as [register, schema, config].
	 *
	 * @var array<int, array{string, string, array<string, mixed>}>
	 */
	private array $queries = [];

	/**
	 * A container whose object service answers the given rows per schema, or throws.
	 *
	 * @param array<string, mixed> $rows    Rows per schema.
	 * @param bool                 $failing Whether every query throws.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $rows, bool $failing = false): ContainerInterface {
		$queries = &$this->queries;
		$store = new class($rows, $failing, $queries) {
			/**
			 * The register asked for.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema asked for.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Where queries are recorded.
			 *
			 * @var array<int, mixed>
			 */
			private array $queries;

			/**
			 * @param array<string, mixed> $rows    Rows per schema.
			 * @param bool                 $failing Whether every query throws.
			 * @param array<int, mixed>    $queries The query sink.
			 */
			public function __construct(
				private readonly array $rows,
				private readonly bool $failing,
				array &$queries,
			) {
				$this->queries = &$queries;
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->queries[] = [$this->register, $this->schema, $config];
				if ($this->failing === true) {
					throw new RuntimeException('store offline');
				}

				return ($this->rows[$this->schema] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);

		return $container;
	}//end container()

	/**
	 * Lines are read for the administration and period, accounts for the administration, both capped at 10000.
	 *
	 * @return void
	 */
	public function testTheQueriesCarryTheAdministrationAndPeriod(): void {
		$generator = new TrialBalanceReportGenerator($this->container([]), $this->createMock(LoggerInterface::class));

		$generator->generate(['administrationId' => ' adm-1 ', 'period' => '2026-Q1'], 'csv');

		$this->assertSame(
			[
				['shillinq', 'GLLine', ['limit' => 10000, 'filters' => ['administrationId' => 'adm-1', 'periodId' => '2026-Q1']]],
				['shillinq', 'Account', ['limit' => 10000, 'filters' => ['administrationId' => 'adm-1']]],
			],
			$this->queries
		);
	}//end testTheQueriesCarryTheAdministrationAndPeriod()

	/**
	 * A numeric administration id is used as text; a period alone narrows only the lines; an empty or non-text context filters nothing.
	 *
	 * @return void
	 */
	public function testContextValuesThatAreMissingOrNotTextAreLeftOut(): void {
		$logger = $this->createMock(LoggerInterface::class);

		(new TrialBalanceReportGenerator($this->container([]), $logger))->generate(['administrationId' => 42], 'csv');
		(new TrialBalanceReportGenerator($this->container([]), $logger))->generate(['period' => '2026-03'], 'csv');
		(new TrialBalanceReportGenerator($this->container([]), $logger))->generate(['administrationId' => ['x'], 'period' => '  '], 'csv');

		$this->assertSame(
			[
				['limit' => 10000, 'filters' => ['administrationId' => '42']],
				['limit' => 10000, 'filters' => ['administrationId' => '42']],
				['limit' => 10000, 'filters' => ['periodId' => '2026-03']],
				['limit' => 10000],
				['limit' => 10000],
				['limit' => 10000],
			],
			array_column($this->queries, 2)
		);
	}//end testContextValuesThatAreMissingOrNotTextAreLeftOut()

	/**
	 * A failing store is logged per schema and yields an empty, balanced report.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedAndGivesAnEmptyReport(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$file = (new TrialBalanceReportGenerator($this->container([], true), $logger))->generate(['period' => '2026-Q1'], 'csv');

		$this->assertSame(
			[
				'Shillinq report generator: could not load GLLine: store offline',
				'Shillinq report generator: could not load Account: store offline',
			],
			$warnings
		);
		$this->assertSame("accountNumber,accountName,debit,credit,balance\nTOTAL,,0.00,0.00,0.00\n", $file->content);
	}//end testAFailingStoreIsLoggedAndGivesAnEmptyReport()

	/**
	 * Serialisable entities are read as arrays, other rows are dropped, and accounts may be keyed by code.
	 *
	 * @return void
	 */
	public function testEntityRowsAreNormalisedAndAccountsMayBeKeyedByCode(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['accountNumber' => '4000', 'amount' => 30, 'side' => 'debit'];
			}
		};
		$rows = [
			'GLLine'  => [$entity, new \stdClass(), 7, ['accountNumber' => '4000', 'amount' => '10', 'side' => 'credit']],
			'Account' => [['code' => '4000', 'name' => 'Huur'], ['name' => 'Geen nummer']],
		];

		$file = (new TrialBalanceReportGenerator($this->container($rows), $this->createMock(LoggerInterface::class)))->generate(['period' => '2026-Q1'], 'csv');

		$this->assertSame(
			"accountNumber,accountName,debit,credit,balance\n4000,Huur,30.00,10.00,20.00\nTOTAL,,30.00,10.00,20.00\n",
			$file->content
		);
	}//end testEntityRowsAreNormalisedAndAccountsMayBeKeyedByCode()

	/**
	 * A store answer that is not a list gives no rows.
	 *
	 * @return void
	 */
	public function testANonListAnswerGivesNoRows(): void {
		$file = (new TrialBalanceReportGenerator($this->container(['GLLine' => 'oops', 'Account' => null]), $this->createMock(LoggerInterface::class)))
			->generate(['period' => '2026-Q1'], 'csv');

		$this->assertSame("accountNumber,accountName,debit,credit,balance\nTOTAL,,0.00,0.00,0.00\n", $file->content);
	}//end testANonListAnswerGivesNoRows()

	/**
	 * The file is named after the report and the period, stripped to safe characters, or today without a period.
	 *
	 * @return void
	 */
	public function testTheFileIsNamedAfterThePeriodOrToday(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$named = (new TrialBalanceReportGenerator($this->container([]), $logger))->generate(['period' => ' 2026/Q1 (concept) '], 'csv');
		$this->assertSame(['trial-balance-2026Q1concept.csv', 'text/csv', 'csv'], [$named->fileName, $named->mimeType, $named->format]);

		$before = gmdate('Y-m-d');
		$dated = (new TrialBalanceReportGenerator($this->container([]), $logger))->generate([], 'csv');
		$after = gmdate('Y-m-d');
		$this->assertContains($dated->fileName, ['trial-balance-' . $before . '.csv', 'trial-balance-' . $after . '.csv']);
	}//end testTheFileIsNamedAfterThePeriodOrToday()
}//end class
