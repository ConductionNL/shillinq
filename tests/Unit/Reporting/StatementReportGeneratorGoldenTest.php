<?php

/**
 * Golden characterisation tests for the balance-sheet and profit-and-loss
 * report generators.
 *
 * Renders the document body of both generators over fixed chart-of-accounts
 * and GL-line datasets and compares every block with a recorded golden, so a
 * restructuring of the account loading, de-duplication, movement derivation
 * or layout cannot change a figure, a label or the block order. The cover's
 * "Gegenereerd" timestamp is the only clock-dependent value and is masked.
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
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator;
use OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * An object service answering findAll() from rows keyed by schema and scope.
 */
final class StatementFixtureObjectService {

	/**
	 * Every findAll() config received, with its schema.
	 *
	 * @var list<string>
	 */
	public array $reads = [];

	/**
	 * The schema set by setSchema().
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows schema => 'scoped'|'all' => rows.
	 */
	public function __construct(
		private readonly array $rows,
	) {
	}

	public function setRegister(string $register): self {
		return $this;
	}

	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}

	/**
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$this->reads[] = $this->schema . ' ' . json_encode($config);
		$scope = isset($config['filters']) === true ? 'scoped' : 'all';
		return $this->rows[$this->schema][$scope] ?? [];
	}
}

/**
 * Golden tests for the two statement generators.
 */
final class StatementReportGeneratorGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/statement-report-golden.json';

	/**
	 * The chart of accounts used by the datasets.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function accounts(): array {
		return [
			['accountNumber' => '0100', 'name' => 'Gebouwen', 'accountType' => 'assets', 'balance' => '125000.50', 'administrationId' => 'adm-1'],
			['accountNumber' => '0100', 'name' => 'Gebouwen (dubbel)', 'accountType' => 'assets', 'balance' => 1, 'administrationId' => 'adm-1'],
			['accountNumber' => '1000', 'name' => 'Kas', 'type' => 'activa', 'currency' => 'USD', 'administrationId' => 'adm-1'],
			['code' => '1300', 'accountName' => 'Debiteuren', 'category' => 'Asset', 'currentBalance' => 900, 'administrationId' => 'adm-1'],
			['accountNumber' => '0500', 'name' => 'Kapitaal', 'accountType' => 'equity', 'closingBalance' => -50000, 'administrationId' => 'adm-1'],
			['accountNumber' => '0600', 'title' => 'Reserves', 'accountType' => 'eigen-vermogen', 'administrationId' => 'adm-1'],
			['accountNumber' => '1600', 'description' => 'Crediteuren', 'accountType' => 'liabilities', 'endBalance' => 'n/a', 'saldo' => -300.25, 'administrationId' => 'adm-1'],
			['accountNumber' => '1700', 'accountType' => 'debts', 'administrationId' => 'adm-1'],
			['id' => 'acc-x', 'accountType' => 'vreemd-vermogen', 'balance' => 10, 'administrationId' => 'adm-2'],
			['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
			['accountNumber' => '8100', 'name' => 'Overige baten', 'accountType' => 'baten', 'periodTotal' => -1200, 'administrationId' => 'adm-1'],
			['accountNumber' => '8200', 'name' => 'Rente', 'accountType' => 'income', 'amount' => '75.5', 'administrationId' => 'adm-1'],
			['accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'kosten', 'administrationId' => 'adm-1'],
			['accountNumber' => '4100', 'name' => 'Huur', 'accountType' => 'Expenses', 'balance' => 2400, 'administrationId' => 'adm-1'],
			['accountNumber' => '4200', 'name' => 'Afschrijving', 'accountType' => 'lasten', 'saldo' => 'x', 'administrationId' => 'adm-1'],
			['accountNumber' => '9999', 'name' => 'Onbekend', 'accountType' => 'memo', 'balance' => 5, 'administrationId' => 'adm-1'],
		];

	}//end accounts()

	/**
	 * GL lines for the derived balances.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function glLines(): array {
		return [
			['accountNumber' => '1000', 'amount' => 500, 'side' => 'debit'],
			['accountId' => '1000', 'baseCurrencyAmount' => '25.25', 'drcr' => 'CREDIT'],
			['account' => '0600', 'transactionAmount' => 4000, 'side' => 'credit'],
			['accountNumber' => '1700', 'amount' => 800, 'side' => 'credit'],
			['accountNumber' => '8000', 'amount' => 10000, 'side' => 'credit'],
			['accountNumber' => '8000', 'amount' => 250, 'side' => 'debit'],
			['accountNumber' => '4000', 'amount' => 3000, 'side' => 'debit'],
			['accountNumber' => '4200', 'amount' => 125, 'side' => 'credit'],
			['amount' => 99, 'side' => 'debit'],
		];

	}//end glLines()

	/**
	 * Datasets: rows per schema/scope and the generation context.
	 *
	 * @return array<string, array{0: array<string, array<string, list<array<string, mixed>>>>, 1: array<string, mixed>}>
	 */
	public static function datasets(): array {
		return [
			'scoped reads'        => [['Account' => ['scoped' => self::accounts()], 'GLLine' => ['scoped' => self::glLines()]], ['administrationId' => 'adm-1', 'period' => '2026']],
			'fallback to all'     => [['Account' => ['all' => self::accounts()], 'GLLine' => ['all' => self::glLines()]], ['administrationId' => 'adm-1']],
			'unscoped context'    => [['Account' => ['all' => self::accounts()], 'GLLine' => ['all' => []]], ['period' => 'Q1']],
			'no accounts at all'  => [[], ['administrationId' => 'adm-9']],
		];

	}//end datasets()

	/**
	 * Render the body of one generator over a dataset.
	 *
	 * @param class-string                                              $class   The generator class.
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows    The dataset rows.
	 * @param array<string, mixed>                                      $context The context.
	 *
	 * @return array{blocks: array<int, mixed>, reads: list<string>}
	 */
	private function render(string $class, array $rows, array $context): array {
		$objectService = new StatementFixtureObjectService($rows);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new RuntimeException('not in container: ' . $id);
			}
		);

		$generator = new $class($container);
		$section = new ReportSection();
		$build = new ReflectionMethod($generator, 'build');
		$build->setAccessible(true);
		$build->invoke($generator, $section, $context);

		$blocks = json_decode(
			(string)preg_replace('/Gegenereerd: \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/', 'Gegenereerd: <now>', (string)json_encode($section->toArray())),
			true
		);

		return ['blocks' => $blocks, 'reads' => $objectService->reads];

	}//end render()

	/**
	 * Generators by name.
	 *
	 * @return array<string, class-string>
	 */
	private static function generators(): array {
		return [
			'balance-sheet' => BalanceSheetReportGenerator::class,
			'profit-loss' => ProfitLossReportGenerator::class,
		];

	}//end generators()

	/**
	 * Each generator renders its golden blocks and reads for each dataset.
	 *
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows    The dataset rows.
	 * @param array<string, mixed>                                      $context The context.
	 *
	 * @return void
	 */
	#[DataProvider('datasets')]
	public function testMatchesGolden(array $rows, array $context): void {
		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$key = (string)json_encode($context);

		foreach (self::generators() as $name => $class) {
			$actual = $this->render($class, $rows, $context);
			if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
				$golden[$name][$key] = $actual;
				file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
				continue;
			}

			self::assertSame($golden[$name][$key], $actual, $name . ' ' . $key);
		}

	}//end testMatchesGolden()
}//end class
