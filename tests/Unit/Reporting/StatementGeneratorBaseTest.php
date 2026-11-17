<?php

/**
 * The shared statement steps: which rows are read, how movements are signed and
 * accounts deduplicated, how accounts are labelled, and what an account table adds.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\AbstractStatementReportGenerator;
use OCA\Shillinq\Reporting\Generator\AccountBucket;
use OCA\Shillinq\Reporting\Generator\StatementRun;
use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Reporting\StatementCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A statement generator over fixture rows per schema, recording each read.
 */
final class FixtureStatementGenerator extends AbstractStatementReportGenerator {

	/**
	 * The reads, as [schema, filters].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $reads = [];

	/**
	 * Hold the fixtures.
	 *
	 * @param ContainerInterface                                       $container The container (unused).
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows      Rows per schema, then per administration ('' = unscoped).
	 */
	public function __construct(ContainerInterface $container, private readonly array $rows) {
		parent::__construct($container);
	}

	/**
	 * Answer the fixture rows of the schema for the administration filter.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   Ignored.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function loadObjects(string $schema, array $filters = [], int $limit = 10000): array {
		$this->reads[] = [$schema, $filters];

		return ($this->rows[$schema][(string)($filters['administrationId'] ?? '')] ?? []);
	}

	/**
	 * Nothing to build.
	 *
	 * @param ReportSection        $section The section.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	protected function build(ReportSection $section, array $context): void {
	}

	/**
	 * The report type.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'fixture';
	}

	/**
	 * The title.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Fixture';
	}

	/**
	 * Call a protected step.
	 *
	 * @param string $method The step.
	 * @param mixed  ...$args Its arguments.
	 *
	 * @return mixed
	 */
	public function call(string $method, mixed ...$args): mixed {
		return $this->{$method}(...$args);
	}
}//end class

/**
 * @covers \OCA\Shillinq\Reporting\Generator\AbstractStatementReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AccountBucket
 * @uses   \OCA\Shillinq\Reporting\Generator\StatementRun
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Reporting\StatementPeriods
 */
class StatementGeneratorBaseTest extends TestCase {

	/**
	 * A generator over the rows.
	 *
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows Rows per schema and administration.
	 *
	 * @return FixtureStatementGenerator
	 */
	private function generator(array $rows = []): FixtureStatementGenerator {
		return new FixtureStatementGenerator($this->createMock(ContainerInterface::class), $rows);
	}//end generator()

	/**
	 * An explicit range wins, then the period label, then the current year.
	 *
	 * @return void
	 */
	public function testTheContextRange(): void {
		$generator = $this->generator();

		$this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-15'], $generator->call('contextRange', ['from' => '2026-02-01', 'to' => '2026-02-15', 'period' => '2026']));
		$this->assertSame(['from' => '2026-07-01', 'to' => '2026-09-30'], $generator->call('contextRange', ['from' => '2026-02-15', 'to' => '2026-02-01', 'period' => '2026-Q3']));
		$this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-31'], $generator->call('contextRange', ['period' => '2026-03']));
		$this->assertSame(['from' => gmdate('Y') . '-01-01', 'to' => gmdate('Y') . '-12-31'], $generator->call('contextRange', ['period' => 'Q1']));
	}//end testTheContextRange()

	/**
	 * The calculator comes from the container; an unresolvable one is null.
	 *
	 * @return void
	 */
	public function testTheCalculatorComesFromTheContainer(): void {
		$calculator = $this->createMock(StatementCalculator::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(StatementCalculator::class)->willReturn($calculator);
		$missing = $this->createMock(ContainerInterface::class);
		$missing->method('get')->willThrowException(new RuntimeException('not registered'));
		$wrong = $this->createMock(ContainerInterface::class);
		$wrong->method('get')->willReturn(new \stdClass());

		$this->assertSame($calculator, (new FixtureStatementGenerator($container, []))->call('calculator'));
		$this->assertNull((new FixtureStatementGenerator($missing, []))->call('calculator'));
		$this->assertNull((new FixtureStatementGenerator($wrong, []))->call('calculator'));
	}//end testTheCalculatorComesFromTheContainer()

	/**
	 * An account table adds its lines with the bucket's total and answers that total.
	 *
	 * @return void
	 */
	public function testAnAccountTableAddsItsLinesAndTotal(): void {
		$section = new ReportSection();
		$lines   = [['label' => '8000 Omzet', 'amount' => 120.5], ['label' => '8100 Overig', 'amount' => '9.5'], ['label' => 'Leeg']];

		$total = $this->generator()->call('addAccountTable', new StatementRun($section, 'USD'), new AccountBucket('Totaal opbrengsten', $lines));

		$this->assertSame(130.0, $total);
		$this->assertSame(
			[
				[
					'type' => 'amountTable',
					'labelHead' => 'Rekening',
					'amountHead' => 'Bedrag',
					'lines' => $lines,
					'total' => ['label' => 'Totaal opbrengsten', 'amount' => 130.0],
					'currency' => 'USD',
				],
			],
			$section->toArray()
		);
	}//end testAnAccountTableAddsItsLinesAndTotal()

	/**
	 * An empty bucket still adds a table with a zero total.
	 *
	 * @return void
	 */
	public function testAnEmptyBucketAddsAZeroTotal(): void {
		$section = new ReportSection();

		$total = $this->generator()->call('addAccountTable', new StatementRun($section, 'EUR'), new AccountBucket('Totaal', []));

		$this->assertSame(0.0, $total);
		$this->assertSame(['label' => 'Totaal', 'amount' => 0.0], $section->toArray()[0]['total']);
	}//end testAnEmptyBucketAddsAZeroTotal()
}//end class
