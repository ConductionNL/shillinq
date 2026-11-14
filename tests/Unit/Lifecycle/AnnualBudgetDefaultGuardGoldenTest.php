<?php

/**
 * Golden test for AnnualBudgetDefaultGuard::isUniqueDefault.
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with ANNUAL_BUDGET_DEFAULT_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class AnnualBudgetDefaultGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/annual-budget-default-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$budget = ['id' => 'b1', 'administrationId' => 'adm-1', 'fiscalYear' => '2026', 'isDefault' => true];
		$scenarios = [
			'notDefault' => [['isDefault' => 'true'], []],
			'noSiblings' => [$budget, []],
			'onlySelf' => [$budget, [['id' => 'b1']]],
			'selfViaAtSelf' => [['@self' => ['id' => 'b1']] + array_diff_key($budget, ['id' => 1]), [['@self' => ['id' => 'b1']]]],
			'otherDefault' => [$budget, [['id' => 'b1'], ['id' => 'b2'], ['id' => 'b3']]],
			'newBudgetVsIdless' => [['isDefault' => true], [[]]],
			'storeDown' => [$budget, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$input, $siblings]) {
			$actual[$name] = $this->scenario($input, $siblings);
		}

		if (getenv('ANNUAL_BUDGET_DEFAULT_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $budget The AnnualBudget being activated.
	 * @param array<int|string,mixed> $siblings The default siblings the store answers.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $budget, array $siblings): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($siblings) {

			public array $calls = [];

			public function __construct(private array $siblings) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->siblings['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return $this->siblings;
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new AnnualBudgetDefaultGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->isUniqueDefault($budget),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
