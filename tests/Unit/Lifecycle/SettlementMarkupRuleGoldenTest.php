<?php

/**
 * Golden test for SettlementGuard::matchMarkupRule.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SettlementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins the matched rule, every lookup and every log line per request.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SettlementMarkupRuleGoldenTest extends TestCase {

	/**
	 * Recorded lookups and log lines of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testMatchesMatchGolden(): void {
		$rules = [
			['id' => 'any', 'targetCustomerId' => '', 'targetCategory' => ''],
			['id' => 'cat-travel', 'targetCategory' => 'travel'],
			['id' => 'cust-a', 'targetCustomerId' => 'cust-a'],
			['id' => 'cust-a-travel', 'targetCustomerId' => 'cust-a', 'targetCategory' => 'travel'],
			['id' => 'cust-a-travel-dup', 'targetCustomerId' => 'cust-a', 'targetCategory' => 'travel'],
			['id' => 'cust-b', 'targetCustomerId' => 'cust-b'],
			['id' => 'cat-food', 'targetCategory' => 'food'],
		];
		$scenarios = [
			'exact' => ['ok', $rules, ['cust-a', 'travel', 'adm-1', 2026]],
			'customerOnly' => ['ok', $rules, ['cust-a', 'hotel', 'adm-1', 2026]],
			'categoryOnly' => ['ok', $rules, ['cust-c', 'travel', 'adm-1', 0]],
			'wildcardOnly' => ['ok', $rules, ['cust-c', 'hotel', 'adm-1', -1]],
			'otherScopesOnly' => ['ok', [$rules[5], $rules[6]], ['cust-a', 'travel', 'adm-1', 2026]],
			'noRules' => ['ok', [], ['cust-a', 'travel', 'adm-1', 2026]],
			'notArray' => ['ok', 'none', ['cust-a', 'travel', 'adm-1', 2026]],
			'storeThrows' => ['ok', 'throw', ['cust-a', 'travel', 'adm-2', 2027]],
			'noObjectService' => ['missing', [], ['cust-a', 'travel', 'adm-1', 2026]],
		];
		$result = [];
		foreach ($scenarios as $name => [$mode, $rows, $args]) {
			$this->calls = [];
			$result[$name] = ['rule' => $this->guard(mode: $mode, rows: $rows)->matchMarkupRule(...$args), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/settlement-markup-rule.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('MARKUP_RULE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testMatchesMatchGolden()

	/**
	 * A guard whose container serves a recording store, or nothing.
	 *
	 * @param string $mode 'ok' or 'missing'.
	 * @param mixed  $rows Rows findAll returns, 'none' for a non-array, 'throw'.
	 *
	 * @return SettlementGuard
	 */
	private function guard(string $mode, mixed $rows): SettlementGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-x');
		$container = $this->createMock(ContainerInterface::class);
		if ($mode === 'missing') {
			$container->method('get')->willThrowException(new \RuntimeException('no object service'));
		} else {
			$container->method('get')->willReturn($this->store(rows: $rows));
		}

		return new SettlementGuard(container: $container, appConfig: $appConfig, logger: $this->logger());

	}//end guard()

	/**
	 * The recording store.
	 *
	 * @param mixed $rows Rows, 'none' or 'throw'.
	 *
	 * @return object
	 */
	private function store(mixed $rows): object {
		$test = $this;
		return new class($rows, $test) {

			/**
			 * Construct.
			 *
			 * @param mixed  $rows Rows.
			 * @param object $test Recorder.
			 */
			public function __construct(private mixed $rows, private object $test) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
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
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Find.
			 *
			 * @param array $params Params.
			 *
			 * @return mixed
			 */
			public function findAll(array $params=[]): mixed {
				$this->test->calls[] = ['findAll', $params];
				if ($this->rows === 'throw') {
					throw new \RuntimeException('store down');
				}

				if ($this->rows === 'none') {
					return null;
				}

				return $this->rows;
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
