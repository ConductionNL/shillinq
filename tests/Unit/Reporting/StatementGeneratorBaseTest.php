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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
	 * Movements sum per account, credits negative, from the first usable code and amount field.
	 *
	 * @return void
	 */
	public function testMovementsAreSignedAndSummedPerAccount(): void {
		$generator = $this->generator(
			[
				'GLLine' => [
					'adm-1' => [
						['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit'],
						['accountNumber' => '8000', 'amount' => '25.5', 'side' => 'CREDIT'],
						['accountId' => '4000', 'baseCurrencyAmount' => 40, 'drcr' => 'debit'],
						['account' => '4000', 'amount' => 'n/a', 'transactionAmount' => 10],
						['account' => '1000', 'amount' => 5],
						['account' => '1000', 'amount' => -2, 'side' => 'debit'],
						['amount' => 999, 'side' => 'debit'],
						['accountNumber' => '  ', 'amount' => 999],
					],
				],
			]
		);

		$this->assertSame(['8000' => -125.5, '4000' => 50.0, '1000' => 3.0], $generator->call('deriveMovements', 'adm-1'));
		$this->assertSame([['GLLine', ['administrationId' => 'adm-1']]], $generator->reads);
	}//end testMovementsAreSignedAndSummedPerAccount()

	/**
	 * An administration without rows of its own falls back to the unscoped rows; no administration reads unscoped once.
	 *
	 * @return void
	 */
	public function testAnEmptyAdministrationFallsBackToTheUnscopedRows(): void {
		$generator = $this->generator(['GLLine' => ['' => [['accountNumber' => '1000', 'amount' => 7]]]]);

		$this->assertSame(['1000' => 7.0], $generator->call('deriveMovements', 'adm-9'));
		$this->assertSame([['GLLine', ['administrationId' => 'adm-9']], ['GLLine', []]], $generator->reads);

		$generator->reads = [];
		$this->assertSame(['1000' => 7.0], $generator->call('deriveMovements', ''));
		$this->assertSame([['GLLine', []]], $generator->reads);

		$empty = $this->generator();
		$this->assertSame([], $empty->call('deriveMovements', ''));
		$this->assertSame([['GLLine', []]], $empty->reads);
	}//end testAnEmptyAdministrationFallsBackToTheUnscopedRows()

	/**
	 * Accounts are deduplicated per administration and account key, keeping the first.
	 *
	 * @return void
	 */
	public function testAccountsAreDeduplicatedPerAdministrationAndKey(): void {
		$generator = $this->generator(
			[
				'Account' => [
					'adm-1' => [
						['administrationId' => 'adm-1', 'accountNumber' => '1000', 'name' => 'Kas'],
						['administrationId' => 'adm-1', 'code' => '1000', 'name' => 'Kas (copy)'],
						['administrationId' => 'adm-2', 'accountNumber' => '1000', 'name' => 'Kas B'],
						['administrationId' => 'adm-1', 'id' => 'uuid-7', 'name' => 'No number'],
					],
				],
			]
		);

		$this->assertSame(
			['Kas', 'Kas B', 'No number'],
			array_column($generator->call('loadAccounts', 'adm-1'), 'name')
		);
		$this->assertSame([['Account', ['administrationId' => 'adm-1']]], $generator->reads);
	}//end testAccountsAreDeduplicatedPerAdministrationAndKey()

	/**
	 * The currency is the first account currency, else EUR.
	 *
	 * @return void
	 */
	public function testTheCurrencyIsTheFirstAccountCurrency(): void {
		$generator = $this->generator();

		$this->assertSame('USD', $generator->call('accountsCurrency', [['currency' => ''], ['name' => 'x'], ['currency' => 'USD'], ['currency' => 'GBP']]));
		$this->assertSame('EUR', $generator->call('accountsCurrency', [['currency' => '  ']]));
		$this->assertSame('EUR', $generator->call('accountsCurrency', []));
	}//end testTheCurrencyIsTheFirstAccountCurrency()

	/**
	 * An account is keyed and labelled by number and name, falling back to its id.
	 *
	 * @return void
	 */
	public function testAccountKeysAndLabels(): void {
		$generator = $this->generator();

		$this->assertSame('4000', $generator->call('accountKey', ['code' => '4000', 'rgsCode' => 'WBedHui', 'id' => 'u1']));
		$this->assertSame('u1', $generator->call('accountKey', ['id' => 'u1']));
		$this->assertSame('4000 Huisvesting', $generator->call('accountLabel', ['code' => '4000', 'accountName' => 'Huisvesting']));
		$this->assertSame('WBedHui', $generator->call('accountLabel', ['rgsCode' => 'WBedHui']));
		$this->assertSame('Omzet', $generator->call('accountLabel', ['description' => 'Omzet']));
		$this->assertSame('Rekening u1', $generator->call('accountLabel', ['id' => 'u1']));
	}//end testAccountKeysAndLabels()

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
