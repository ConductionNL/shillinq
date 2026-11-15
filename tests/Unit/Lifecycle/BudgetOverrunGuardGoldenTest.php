<?php

/**
 * Golden test for BudgetOverrunGuard::canPost.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BudgetOverrunGuard;
use OCA\Shillinq\Service\BegrotingswijzigingStacker;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with BUDGET_OVERRUN_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class BudgetOverrunGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/budget-overrun-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$records = [
			'Taakveld' => [['taskFieldCode' => '1.2', 'revenue' => 0.0, 'expenses' => 500.0], 'junk'],
			'Begrotingswijziging' => [['status' => 'determined', 'movements' => [['taskFieldCode' => '1.2', 'lasten_delta' => 100.0]]]],
			'GLLine' => [['amount' => '199.995'], ['amount' => 200], 'junk', []],
		];
		$scenarios = [
			'noBudget' => ['', '1.2', 1.0, $records],
			'noTaskField' => ['pb-1', '', 1.0, $records],
			'within' => ['pb-1', '1.2', 200.0, $records],
			'exact' => ['pb-1', '1.2', 199.99, $records],
			'over' => ['pb-1', '1.2', 200.01, $records],
			'unknownTaskField' => ['pb-1', '9.9', 0.01, $records],
			'storeDown' => ['pb-1', '1.2', 1.0, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$budgetId, $taskField, $attempted, $rows]) {
			$actual[$name] = $this->scenario($budgetId, $taskField, $attempted, $rows);
		}

		if (getenv('BUDGET_OVERRUN_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param string $budgetId The budget id.
	 * @param string $taskField The task field code.
	 * @param float $attempted The attempted amount.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $budgetId, string $taskField, float $attempted, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new BudgetOverrunGuard($appConfig, new BegrotingswijzigingStacker(), $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canPost($budgetId, $taskField, $attempted),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
