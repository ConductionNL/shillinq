<?php

/**
 * Golden test for BudgetBlocker::canCommit.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins the decision, every store read and every log line per commitment.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetBlockerCommitGoldenTest extends TestCase {

	/**
	 * Recorded reads and log lines of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testDecisionsMatchGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => [$number, $object, $broken]) {
			$this->calls = [];
			$guard = $this->guard(broken: $broken);
			$result[$name] = ['allowed' => $guard->canCommit($number, $object), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/budget-blocker-commit.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('BUDGET_BLOCKER_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * Scenarios: [commitment number, pre-loaded object or null, store throws].
	 *
	 * @return array<string, array{0: string, 1: ?array, 2: bool}>
	 */
	private function scenarios(): array {
		$rule = static fn (string $programme, int $year, mixed $amount): array => [
			'programme' => $programme,
			'financialYear' => $year,
			'amount_excl_vat' => $amount,
		];
		$commitment = static fn (array $extra): array => array_merge(
			['administrationId' => 'adm-1', 'commitmentNumber' => 'PO-1', 'kind' => 'purchase_order'],
			$extra
		);
		return [
			'notFound' => ['PO-404', null, false],
			'loadedFits' => ['PO-STORED', null, false],
			'withinBudget' => ['PO-1', $commitment(['rules' => [$rule('5.1', 2026, 1000)]]), false],
			'secondRuleOver' => [
				'PO-1',
				$commitment(['rules' => [$rule('5.1', 2026, 1000), $rule('5.2', 2026, 999999999), $rule('5.1', 2027, 1)]]),
				false,
			],
			'noBudgetForYear' => ['PO-1', $commitment(['rules' => ['k' => $rule('5.1', 2030, 1)]]), false],
			'unreadableAmount' => ['PO-1', $commitment(['rules' => [['programme' => '5.1', 'financialYear' => 2026, 'amount' => 1]]]), false],
			'linesFromStore' => ['PO-2', $commitment(['commitmentNumber' => 'PO-2', 'rules' => []]), false],
			'headerFallback' => [
				'PO-3',
				$commitment(['commitmentNumber' => 'PO-3', 'programme' => '5.1', 'financialYear' => 2026, 'total_amount_excl_vat' => 5]),
				false,
			],
			'headerNoNumber' => ['', ['administrationId' => 'adm-1', 'programme' => '5.1', 'financialYear' => 2026], false],
			'overrideMandate' => ['PO-1', $commitment(['kind' => 'override_kind', 'rules' => [$rule('5.1', 2026, 999999999)]]), false],
			'storeBroken' => ['PO-1', null, true],
		];

	}//end scenarios()

	/**
	 * A guard over a recording, filtering store.
	 *
	 * @param bool $broken Whether the container lookup throws.
	 *
	 * @return BudgetBlocker
	 */
	private function guard(bool $broken): BudgetBlocker {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		if ($broken === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no object service'));
		} else {
			$container->method('get')->willReturn($this->store());
		}

		$logger = $this->logger();
		return new BudgetBlocker(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			mandate: new MandateEnforcer(container: $container, appConfig: $appConfig, logger: $logger),
		);

	}//end guard()

	/**
	 * The recording store.
	 *
	 * @return object
	 */
	private function store(): object {
		$rows = [
			'Commitment' => [
				[
					'administrationId' => 'adm-1',
					'commitmentNumber' => 'PO-STORED',
					'rules' => [['programme' => '5.1', 'financialYear' => 2026, 'amount_excl_vat' => '10']],
				],
			],
			'CommitmentBudget' => [
				['administrationId' => 'adm-1', 'programmeCode' => '5.1', 'financialYear' => 2026, 'authorised_amount' => 50000, 'realised_amount' => 20000],
				['administrationId' => 'adm-1', 'programmeCode' => '5.2', 'financialYear' => 2026, 'authorised_amount' => 100, 'outstanding_commitments' => 50],
				['administrationId' => 'adm-1', 'programmeCode' => '5.1', 'financialYear' => 2027, 'authorised_amount' => 1],
			],
			'CommitmentLine' => [['commitment' => 'PO-2', 'programme' => '5.2', 'financialYear' => 2026, 'amount_excl_vat' => 50]],
			'SpendingMandate' => [
				[
					'administrationId' => 'adm-1',
					'mandateCode' => 'M-OVR',
					'maximumAmount' => 1000000000,
					'kind_commitment' => ['override_kind'],
					'is_override' => true,
					'valid_from' => '2020-01-01',
					'valid_to' => '2999-12-31',
				],
			],
		];
		$test = $this;
		return new class($rows, $test) {

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param array  $rows Rows.
			 * @param object $test Recorder.
			 */
			public function __construct(private array $rows, private object $test) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows matching every filter.
			 *
			 * @param array $params Params.
			 *
			 * @return array
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (is_array($value) === false && ($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

	}//end store()

	/**
	 * A logger recording into $this->calls.
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
				$this->test->calls[] = ['log', $level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
