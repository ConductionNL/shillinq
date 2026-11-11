<?php

/**
 * Golden test for BudgetScenarioEvaluator::evaluate.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;
use OCA\Shillinq\Tests\Unit\Service\Support\FakeKnownCostScheduleExpander;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins every evaluated cell, every expander call and every log line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetScenarioEvaluatorGoldenTest extends TestCase {

	/**
	 * Recorded log lines.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $logs = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testEvaluationsMatchGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => $modifiers) {
			$this->logs = [];
			$fake = new FakeKnownCostScheduleExpander();
			$evaluator = new BudgetScenarioEvaluator($this->expander(fake: $fake), $this->logger());
			$cells = $evaluator->evaluate($this->budgetLines(), $this->ledgerGroups(), $this->recurring(), $modifiers, 2027);
			$result[$name] = ['cells' => $this->nonZero(cells: $cells), 'count' => count($cells), 'expander' => $fake->calls, 'logs' => $this->logs];
		}

		$file = __DIR__.'/fixtures/budget-scenario-evaluator.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('SCENARIO_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testEvaluationsMatchGolden()

	/**
	 * Modifier sets, by name.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function scenarios(): array {
		return [
			'none' => [],
			'recurringEnd' => [['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-host', 'effectiveDate' => '2027-04-30']],
			'amountChange' => [
				['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'targetRecurId' => 'rec-host', 'effectiveDate' => '2027-07-01', 'newStandardAmount' => 300.5],
			],
			'ledgerDeltas' => [
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'lg-parent', 'effectiveDate' => '2027-03-15', 'amountDeltaCents' => 1000],
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'empty-child', 'effectiveDate' => '2027-03', 'amountDeltaCents' => -250],
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'lg-host', 'effectiveDate' => '2026-03-01', 'amountDeltaCents' => 5],
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'lg-host', 'effectiveDate' => '2027', 'amountDeltaCents' => 5],
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'nope', 'effectiveDate' => '2027-01-01', 'amountDeltaCents' => 5],
			],
			'skips' => [
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-missing', 'effectiveDate' => '2027-01-31'],
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-unmapped', 'effectiveDate' => '2027-01-31'],
				['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'targetRecurId' => 'rec-cpi', 'effectiveDate' => '2027-02-01', 'newStandardAmount' => 1],
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-throws', 'effectiveDate' => '2027-01-31'],
				['modifierType' => 'SOMETHING_ELSE', 'targetRecurId' => 'rec-host'],
				['targetRecurId' => 'rec-host'],
			],
			'stacked' => [
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-host', 'effectiveDate' => '2027-06-30'],
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-lic', 'effectiveDate' => '2027-09-30'],
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'lg-host', 'effectiveDate' => '2027-08-01', 'amountDeltaCents' => 777],
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-host', 'effectiveDate' => '2027-10-31'],
			],
		];

	}//end scenarios()

	/**
	 * Cells whose base or scenario is non-zero (the rest is in `count`).
	 *
	 * @param array<string, array<string, mixed>> $cells All cells.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function nonZero(array $cells): array {
		return array_filter($cells, static fn (array $cell): bool => ($cell['base'] !== 0 || $cell['scenario'] !== 0));

	}//end nonZero()

	/**
	 * A parent with two children (one leaf on 4200-4299, one without lines),
	 * a slug-only group, an id-less group and a second root on 6000.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ledgerGroups(): array {
		$group = static fn (array $g): array => array_merge(
			[
				'administrationId' => 'adm-1',
				'parentLedgerGroupId' => null,
				'accountRanges' => [],
				'includedAccountNumbers' => [],
				'excludedAccountNumbers' => [],
			],
			$g
		);
		return [
			$group(['id' => 'lg-host', 'name' => 'Hosting', 'parentLedgerGroupId' => 'lg-parent', 'accountRanges' => [['from' => '4200', 'to' => '4299']]]),
			$group(['id' => 'lg-parent', 'name' => 'Bedrijfskosten']),
			$group(['@self' => ['slug' => 'empty-child'], 'name' => 'Empty child', 'parentLedgerGroupId' => 'lg-parent']),
			$group(['name' => 'Keyless']),
			$group(['id' => 'lg-lic', 'name' => 'Licenties', 'includedAccountNumbers' => ['6000']]),
		];

	}//end ledgerGroups()

	/**
	 * Own BudgetLines: two on the leaf, a zero one on a root, one unresolved.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function budgetLines(): array {
		return [
			['ledgerGroupId' => 'lg-host', 'month01Amount' => 30000, 'month02Amount' => 30000],
			['ledgerGroupId' => 'lg-lic', 'month12Amount' => 0],
			['ledgerGroupId' => 'lg-host', 'month01Amount' => 5],
			['ledgerGroupId' => 'unknown', 'month01Amount' => 99],
		];

	}//end budgetLines()

	/**
	 * Real CashflowRecurring rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function recurring(): array {
		$row = static fn (array $r): array => array_merge(['frequency' => 'MONTHLY', 'validFrom' => '2026-01-01', 'validTo' => null], $r);
		return [
			$row(['recurId' => 'rec-host', 'accountNumberExpense' => '4200', 'standardAmount' => 250.0]),
			$row(['recurId' => 'rec-lic', 'accountNumberExpense' => '6000', 'standardAmount' => 99.99, 'validTo' => '2027-11-30']),
			$row(['recurId' => 'rec-unmapped', 'accountNumberExpense' => '9999', 'standardAmount' => 1.0]),
			$row(['recurId' => 'rec-cpi', 'accountNumberExpense' => '4250', 'standardAmount' => 10.0, 'indexationRule' => 'CPI_PAST_YEAR']),
			$row(['recurId' => 'rec-throws', 'accountNumberExpense' => '4201', 'standardAmount' => 10.0]),
		];

	}//end recurring()

	/**
	 * The fake expander, throwing for rec-throws.
	 *
	 * @param FakeKnownCostScheduleExpander $fake The recording fake.
	 *
	 * @return KnownCostScheduleExpanderInterface
	 */
	private function expander(FakeKnownCostScheduleExpander $fake): KnownCostScheduleExpanderInterface {
		return new class($fake) implements KnownCostScheduleExpanderInterface {

			/**
			 * Construct.
			 *
			 * @param FakeKnownCostScheduleExpander $fake Fake.
			 */
			public function __construct(private FakeKnownCostScheduleExpander $fake) {
			}//end __construct()

			/**
			 * Expand, or throw for rec-throws.
			 *
			 * @param array      $recurring  Row.
			 * @param int        $fiscalYear Year.
			 * @param array|null $contract   Contract.
			 *
			 * @return array
			 */
			public function expand(array $recurring, int $fiscalYear, ?array $contract): array {
				if (($recurring['recurId'] ?? '') === 'rec-throws') {
					throw new \RuntimeException('expander down');
				}

				return $this->fake->expand($recurring, $fiscalYear, $contract);
			}//end expand()
		};

	}//end expander()

	/**
	 * A logger recording into $this->logs.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {

			/**
			 * Construct.
			 *
			 * @param object $test Recorder.
			 */
			public function __construct(private object $test) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array              $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->logs[] = [$level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
