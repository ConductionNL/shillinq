<?php

/**
 * The balance sheet and the profit-and-loss account, asserted per decision.
 *
 * StatementReportGeneratorGoldenTest renders both generators over four
 * datasets and compares the bytes with a recording: it notices a change but
 * states no rule, so what the code is SUPPOSED to do lives only in the
 * recording. This test pins the decisions themselves — which account types
 * land on which side, which materialised balance field wins, when the GLLine
 * movements are derived (and that they are derived once), how the balance
 * check closes, where the reporting currency comes from, and how the result
 * is labelled and signed.
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

use OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator;
use OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * An object service answering fixture rows per schema and administration scope,
 * recording every read.
 */
final class StatementStructureObjectService {

	/**
	 * The reads, as "<schema>|<administrationId>".
	 *
	 * @var list<string>
	 */
	public array $reads = [];

	/**
	 * The schema last asked for.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Hold the fixtures.
	 *
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows Rows per schema, then per administration ('' = unscoped).
	 */
	public function __construct(
		private readonly array $rows,
	) {

	}//end __construct()

	/**
	 * Accept the register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;

	}//end setRegister()

	/**
	 * Accept the schema.
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
	 * Answer the rows of the schema for the administration filter.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$administrationId = (string)($config['filters']['administrationId'] ?? '');
		$this->reads[] = $this->schema . '|' . $administrationId;

		return ($this->rows[$this->schema][$administrationId] ?? []);

	}//end findAll()
}//end class

/**
 * Behavioural tests for the two statement generators.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\AbstractStatementReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AccountBucket
 * @uses   \OCA\Shillinq\Reporting\Generator\StatementRun
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StatementStructureTest extends TestCase {

	/**
	 * The object service of the last render.
	 *
	 * @var StatementStructureObjectService|null
	 */
	private ?StatementStructureObjectService $objects = null;

	/**
	 * Render one generator's body over fixture rows.
	 *
	 * @param class-string                                             $class   The generator class.
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows    Rows per schema, then per administration.
	 * @param array<string, mixed>                                     $context The generation context.
	 *
	 * @return array<int, array<string, mixed>> The section's blocks.
	 */
	private function render(string $class, array $rows, array $context = ['administrationId' => 'adm-1']): array {
		$this->objects = new StatementStructureObjectService($rows);
		$objects = $this->objects;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objects;
				}

				throw new RuntimeException('not in container: ' . $id);
			}
		);

		$section = new ReportSection();
		$build = new ReflectionMethod($class, 'build');
		$build->invoke(new $class($container), $section, $context);

		return $section->toArray();

	}//end render()

	/**
	 * The amount tables of a rendered body, in order.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 *
	 * @return list<array<string, mixed>> The amountTable blocks.
	 */
	private function amountTables(array $blocks): array {
		return array_values(array_filter($blocks, static fn (array $block): bool => $block['type'] === 'amountTable'));

	}//end amountTables()

	/**
	 * The label/amount pairs of one amount table.
	 *
	 * @param array<string, mixed> $table The amountTable block.
	 *
	 * @return array<string, float> Label => amount.
	 */
	private function lines(array $table): array {
		$lines = [];
		foreach ($table['lines'] as $line) {
			$lines[$line['label']] = $line['amount'];
		}

		return $lines;

	}//end lines()

	/**
	 * The first details table of a rendered body.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 *
	 * @return array<string, string> Its rows.
	 */
	private function detailsTable(array $blocks): array {
		foreach ($blocks as $block) {
			if ($block['type'] === 'detailsTable') {
				return $block['rows'];
			}
		}

		return [];

	}//end detailsTable()

	/**
	 * The note texts of a rendered body.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 *
	 * @return list<string> The notes.
	 */
	private function notes(array $blocks): array {
		$notes = [];
		foreach ($blocks as $block) {
			if ($block['type'] === 'note') {
				$notes[] = $block['text'];
			}
		}

		return $notes;

	}//end notes()

	/**
	 * Accounts land on the side their type names, in English or in Dutch, and a
	 * result account never sits on the balance.
	 *
	 * @return void
	 */
	public function testEachAccountTypeLandsOnItsOwnSideOfTheBalance(): void {
		$blocks = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '0100', 'name' => 'Gebouwen', 'accountType' => 'assets', 'balance' => 100.0],
				['accountNumber' => '1000', 'name' => 'Kas', 'type' => 'activa', 'balance' => 25.0],
				['accountNumber' => '1300', 'accountName' => 'Debiteuren', 'category' => 'Asset', 'balance' => 5.0],
				['accountNumber' => '0500', 'name' => 'Kapitaal', 'accountType' => 'equity', 'balance' => 80.0],
				['accountNumber' => '0600', 'title' => 'Reserves', 'accountType' => 'eigen-vermogen', 'balance' => 20.0],
				['accountNumber' => '1600', 'description' => 'Crediteuren', 'accountType' => 'liabilities', 'balance' => 30.0],
				['accountNumber' => '1700', 'name' => 'Leningen', 'accountType' => 'vreemd-vermogen', 'balance' => 0.0],
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => 999.0],
				['accountNumber' => '9999', 'name' => 'Memo', 'accountType' => 'memo', 'balance' => 7.0],
			]]]
		);

		$tables = $this->amountTables($blocks);

		$this->assertSame(
			['0100 Gebouwen' => 100.0, '1000 Kas' => 25.0, '1300 Debiteuren' => 5.0],
			$this->lines($tables[0])
		);
		$this->assertSame(['0500 Kapitaal' => 80.0, '0600 Reserves' => 20.0], $this->lines($tables[1]));
		$this->assertSame(['1600 Crediteuren' => 30.0, '1700 Leningen' => 0.0], $this->lines($tables[2]));
		$this->assertSame(['label' => 'Totaal activa', 'amount' => 130.0], $tables[0]['total']);
		$this->assertSame(['label' => 'Totaal passiva', 'amount' => 130.0], $tables[3]['total']);

	}//end testEachAccountTypeLandsOnItsOwnSideOfTheBalance()

	/**
	 * The first numeric balance field wins; a non-numeric one is passed over.
	 *
	 * @return void
	 */
	public function testTheFirstNumericBalanceFieldIsTheAccountBalance(): void {
		$blocks = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '1', 'name' => 'Alles', 'accountType' => 'assets', 'balance' => 1, 'currentBalance' => 2, 'saldo' => 3],
				['accountNumber' => '2', 'name' => 'Niet numeriek', 'accountType' => 'assets', 'balance' => 'n/a', 'closingBalance' => '12.50'],
				['accountNumber' => '3', 'name' => 'Alleen saldo', 'accountType' => 'assets', 'saldo' => -4.25],
			]]]
		);

		$this->assertSame(
			['1 Alles' => 1.0, '2 Niet numeriek' => 12.5, '3 Alleen saldo' => -4.25],
			$this->lines($this->amountTables($blocks)[0])
		);

	}//end testTheFirstNumericBalanceFieldIsTheAccountBalance()

	/**
	 * An account without any numeric balance falls back to its net GLLine
	 * movement (debit positive, credit negative), and the movements are read
	 * once however many accounts need them.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutABalanceFallsBackToItsMovementsReadOnce(): void {
		$blocks = $this->render(
			BalanceSheetReportGenerator::class,
			[
				'Account' => ['adm-1' => [
					['accountNumber' => '1000', 'name' => 'Kas', 'accountType' => 'assets'],
					['accountNumber' => '1600', 'name' => 'Crediteuren', 'accountType' => 'liabilities'],
					['accountNumber' => '1700', 'name' => 'Zonder mutaties', 'accountType' => 'liabilities'],
				]],
				'GLLine' => ['adm-1' => [
					['accountNumber' => '1000', 'amount' => 500, 'side' => 'debit'],
					['accountId' => '1000', 'baseCurrencyAmount' => '25.25', 'drcr' => 'CREDIT'],
					['account' => '1600', 'transactionAmount' => 40, 'side' => 'credit'],
					['amount' => 99, 'side' => 'debit'],
				]],
			]
		);

		$tables = $this->amountTables($blocks);

		$this->assertSame(['1000 Kas' => 474.75], $this->lines($tables[0]));
		$this->assertSame(['1600 Crediteuren' => -40.0, '1700 Zonder mutaties' => 0.0], $this->lines($tables[2]));
		$this->assertSame(['Account|adm-1', 'GLLine|adm-1'], $this->objects->reads);

	}//end testAnAccountWithoutABalanceFallsBackToItsMovementsReadOnce()

	/**
	 * A scoped read that finds nothing is retried unscoped, and the same
	 * account is listed once per administration.
	 *
	 * @return void
	 */
	public function testAnEmptyScopedReadFallsBackToEveryRowAndAccountsAreDeduped(): void {
		$blocks = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['' => [
				['accountNumber' => '0100', 'name' => 'Gebouwen', 'accountType' => 'assets', 'balance' => 10.0, 'administrationId' => 'adm-1'],
				['accountNumber' => '0100', 'name' => 'Gebouwen (dubbel)', 'accountType' => 'assets', 'balance' => 99.0, 'administrationId' => 'adm-1'],
				['code' => '0100', 'name' => 'Gebouwen elders', 'accountType' => 'assets', 'balance' => 5.0, 'administrationId' => 'adm-2'],
			]]]
		);

		$this->assertSame(
			['0100 Gebouwen' => 10.0, '0100 Gebouwen elders' => 5.0],
			$this->lines($this->amountTables($blocks)[0])
		);
		$this->assertSame(['Account|adm-1', 'Account|'], $this->objects->reads);

	}//end testAnEmptyScopedReadFallsBackToEveryRowAndAccountsAreDeduped()

	/**
	 * The balance check counts the accounts, states both totals and the
	 * difference, and closes only within half a cent.
	 *
	 * @return void
	 */
	public function testTheBalanceCheckClosesOnlyWithinHalfACent(): void {
		$closing = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '1', 'name' => 'Activa', 'accountType' => 'assets', 'balance' => 100.004],
				['accountNumber' => '2', 'name' => 'Vermogen', 'accountType' => 'equity', 'balance' => 100.0],
			]]]
		);
		$off = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '1', 'name' => 'Activa', 'accountType' => 'assets', 'balance' => 100.02],
				['accountNumber' => '2', 'name' => 'Vermogen', 'accountType' => 'equity', 'balance' => 100.0],
			]]]
		);

		$this->assertSame(
			[
				'Aantal grootboekrekeningen' => '2',
				'Totaal activa' => 'EUR 100,00',
				'Totaal passiva' => 'EUR 100,00',
				'Verschil' => 'EUR 0,00',
				'Balans sluit aan' => 'Ja',
			],
			$this->detailsTable($closing)
		);
		$this->assertSame('Nee', $this->detailsTable($off)['Balans sluit aan']);
		$this->assertSame('EUR 0,02', $this->detailsTable($off)['Verschil']);

	}//end testTheBalanceCheckClosesOnlyWithinHalfACent()

	/**
	 * The reporting currency is the first account that names one, else EUR.
	 *
	 * @return void
	 */
	public function testTheReportingCurrencyComesFromTheFirstAccountThatNamesOne(): void {
		$usd = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '1', 'name' => 'Zonder valuta', 'accountType' => 'assets', 'balance' => 1.0],
				['accountNumber' => '2', 'name' => 'Kas', 'accountType' => 'assets', 'currency' => 'USD', 'balance' => 2.0],
				['accountNumber' => '3', 'name' => 'Pond', 'accountType' => 'assets', 'currency' => 'GBP', 'balance' => 3.0],
			]]]
		);
		$empty = $this->render(BalanceSheetReportGenerator::class, []);

		$this->assertSame('USD', $this->amountTables($usd)[0]['currency']);
		$this->assertStringStartsWith('USD ', $this->detailsTable($usd)['Totaal activa']);
		$this->assertSame('EUR 0,00', $this->detailsTable($empty)['Totaal activa']);

	}//end testTheReportingCurrencyComesFromTheFirstAccountThatNamesOne()

	/**
	 * An administration without any general-ledger account says so, and every
	 * empty table says it has no entries rather than rendering a bare total.
	 *
	 * @return void
	 */
	public function testABalanceWithoutAccountsSaysSo(): void {
		$blocks = $this->render(BalanceSheetReportGenerator::class, []);

		$this->assertContains(
			'Er zijn geen grootboekrekeningen gevonden voor deze administratie.',
			$this->notes($blocks)
		);
		$this->assertSame('0', $this->detailsTable($blocks)['Aantal grootboekrekeningen']);

	}//end testABalanceWithoutAccountsSaysSo()

	/**
	 * An account with neither number nor name is labelled by its id.
	 *
	 * @return void
	 */
	public function testANamelessAccountIsLabelledByItsId(): void {
		$blocks = $this->render(
			BalanceSheetReportGenerator::class,
			['Account' => ['adm-1' => [['id' => 'acc-x', 'accountType' => 'assets', 'balance' => 3.0]]]]
		);

		$this->assertSame(['Rekening acc-x' => 3.0], $this->lines($this->amountTables($blocks)[0]));

	}//end testANamelessAccountIsLabelledByItsId()

	/**
	 * Result accounts are split into revenue and costs by type, in English or
	 * in Dutch, and both sides are listed as positive amounts.
	 *
	 * @return void
	 */
	public function testResultAccountsAreSplitIntoRevenueAndCostsAsPositiveAmounts(): void {
		$blocks = $this->render(
			ProfitLossReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => -1000.0],
				['accountNumber' => '8100', 'name' => 'Baten', 'accountType' => 'baten', 'periodTotal' => 200.0],
				['accountNumber' => '8200', 'name' => 'Rente', 'accountType' => 'income', 'amount' => '75.5'],
				['accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'kosten', 'balance' => 300.0],
				['accountNumber' => '4100', 'name' => 'Huur', 'accountType' => 'Expenses', 'balance' => -100.0],
				['accountNumber' => '0100', 'name' => 'Gebouwen', 'accountType' => 'assets', 'balance' => 50.0],
			]]]
		);

		$tables = $this->amountTables($blocks);

		$this->assertSame(['8000 Omzet' => 1000.0, '8100 Baten' => 200.0, '8200 Rente' => 75.5], $this->lines($tables[0]));
		$this->assertSame(['4000 Lonen' => 300.0, '4100 Huur' => 100.0], $this->lines($tables[1]));

	}//end testResultAccountsAreSplitIntoRevenueAndCostsAsPositiveAmounts()

	/**
	 * The result is revenue minus costs, labelled profit when it is not
	 * negative and loss when it is.
	 *
	 * @return void
	 */
	public function testTheResultIsLabelledProfitOrLoss(): void {
		$profit = $this->render(
			ProfitLossReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => 1000.0],
				['accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'kosten', 'balance' => 400.0],
			]]]
		);
		$loss = $this->render(
			ProfitLossReportGenerator::class,
			['Account' => ['adm-1' => [
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => 100.0],
				['accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'kosten', 'balance' => 400.0],
			]]]
		);

		$profitResult = $this->amountTables($profit)[2];
		$this->assertSame(['label' => 'Resultaat (winst)', 'amount' => 600.0], $profitResult['total']);
		$this->assertSame(['Totaal opbrengsten' => 1000.0, 'Totaal kosten' => -400.0], $this->lines($profitResult));
		$this->assertSame(
			['label' => 'Resultaat (verlies)', 'amount' => -300.0],
			$this->amountTables($loss)[2]['total']
		);

	}//end testTheResultIsLabelledProfitOrLoss()

	/**
	 * A result account without a materialised amount takes its own side of the
	 * net movement, and only then does the note mention the movements.
	 *
	 * @return void
	 */
	public function testResultAmountsFallBackToMovementsAndTheNoteSaysSo(): void {
		$derived = $this->render(
			ProfitLossReportGenerator::class,
			[
				'Account' => ['adm-1' => [
					['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue'],
					['accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'kosten'],
				]],
				'GLLine' => ['adm-1' => [
					['accountNumber' => '8000', 'amount' => 1000, 'side' => 'credit'],
					['accountNumber' => '8000', 'amount' => 250, 'side' => 'debit'],
					['accountNumber' => '4000', 'amount' => 300, 'side' => 'debit'],
				]],
			]
		);
		$materialised = $this->render(
			ProfitLossReportGenerator::class,
			['Account' => ['adm-1' => [['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => 10.0]]]]
		);

		$this->assertSame(['8000 Omzet' => 750.0], $this->lines($this->amountTables($derived)[0]));
		$this->assertSame(['4000 Lonen' => 300.0], $this->lines($this->amountTables($derived)[1]));
		$this->assertContains(
			'Het resultaat is bepaald als het saldo van opbrengsten en kosten over de periode. '
			. 'Bedragen zijn afgeleid uit de grootboekrekeningen van het type opbrengst en kosten, '
			. 'aangevuld met de geboekte grootboekmutaties.',
			$this->notes($derived)
		);
		$this->assertContains(
			'Het resultaat is bepaald als het saldo van opbrengsten en kosten over de periode. '
			. 'Bedragen zijn afgeleid uit de grootboekrekeningen van het type opbrengst en kosten.',
			$this->notes($materialised)
		);

	}//end testResultAmountsFallBackToMovementsAndTheNoteSaysSo()

	/**
	 * An administration without result accounts says so.
	 *
	 * @return void
	 */
	public function testAProfitAndLossWithoutResultAccountsSaysSo(): void {
		$blocks = $this->render(
			ProfitLossReportGenerator::class,
			['Account' => ['adm-1' => [['accountNumber' => '0100', 'name' => 'Gebouwen', 'accountType' => 'assets', 'balance' => 1.0]]]]
		);

		$this->assertContains(
			'Er zijn geen resultaatrekeningen gevonden voor deze administratie.',
			$this->notes($blocks)
		);

	}//end testAProfitAndLossWithoutResultAccountsSaysSo()

	/**
	 * The cover carries the period and the administration of the context, and
	 * the statement's own title.
	 *
	 * @return void
	 */
	public function testTheCoverCarriesThePeriodAndTheAdministration(): void {
		$blocks = $this->render(
			ProfitLossReportGenerator::class,
			[],
			['administrationId' => 'adm-1', 'period' => '2026-Q1']
		);
		$unscoped = $this->render(BalanceSheetReportGenerator::class, [], []);

		$cover = $blocks[0];
		$this->assertSame(
			['cover', 'Winst- en verliesrekening', 'Resultatenrekening over de periode'],
			[$cover['type'], $cover['title'], $cover['subtitle']]
		);
		$this->assertSame(['Periode: 2026-Q1', 'Administratie: adm-1'], array_slice($cover['meta'], 0, 2));
		$this->assertSame('Balans', $unscoped[0]['title']);
		$this->assertCount(1, $unscoped[0]['meta']);

	}//end testTheCoverCarriesThePeriodAndTheAdministration()
}//end class
