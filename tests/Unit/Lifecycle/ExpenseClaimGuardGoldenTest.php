<?php

/**
 * Golden test for the ExpenseClaimGuard submit and post gates.
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

use OCA\Shillinq\Lifecycle\ExpenseClaimGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per claim.
 *
 * Regenerate with EXPENSE_CLAIM_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class ExpenseClaimGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/expense-claim-guard.golden.json';

	public function testGatesMatchGolden(): void {
		$items = [
			'Receipt' => [['id' => 'r-1', 'costCentreCode' => 'CC-1'], ['id' => 'r-2', 'costCentreCode' => ' ']],
			'MileageEntry' => [['id' => 'm-1', 'costCentreCode' => 'CC-2']],
			'PerDiem' => [['id' => 'p-1', 'costCentreCode' => 'CC-3']],
		];
		$claim = [
			'id' => 'cl-1',
			'administrationId' => 'adm-1',
			'fromDate' => '2026-03-01',
			'receiptIds' => ['r-1'],
			'mileageIds' => ['m-1'],
			'perDiemIds' => ['p-1'],
		];
		$open = [['state' => 'open'], ['state' => 'closed']];
		$scenarios = [
			'submit.empty' => ['requireCostCentresAndItems', ['id' => 'cl-0'], $items, $open],
			'submit.complete' => ['requireCostCentresAndItems', $claim, $items, $open],
			'submit.blankCentre' => ['requireCostCentresAndItems', ['receiptIds' => ['r-1', 'r-2']] + $claim, $items, $open],
			'submit.missingItem' => ['requireCostCentresAndItems', ['mileageIds' => ['m-9']] + $claim, $items, $open],
			'submit.storeDown' => ['requireCostCentresAndItems', $claim, '*throw*', $open],
			'post.openYear' => ['requireOpenPeriodAndCostCentres', $claim, $items, $open],
			'post.closedYear' => ['requireOpenPeriodAndCostCentres', $claim, $items, [['state' => 'closed']]],
			'post.noYear' => ['requireOpenPeriodAndCostCentres', $claim, $items, []],
			'post.noItems' => ['requireOpenPeriodAndCostCentres', ['id' => 'cl-2', 'fromDate' => '2026-01-01'], $items, [['state' => 'open']]],
			'post.yearsDown' => ['requireOpenPeriodAndCostCentres', $claim, $items, '*throw*'],
			'post.blankCentre' => ['requireOpenPeriodAndCostCentres', ['perDiemIds' => ['p-1', 'r-2']] + $claim, $items, $open],
		];

		$actual = [];
		foreach ($scenarios as $name => [$method, $input, $rows, $years]) {
			$actual[$name] = $this->scenario($method, $input, $rows, $years);
		}

		if (getenv('EXPENSE_CLAIM_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one gate and capture result, logs and store calls.
	 *
	 * @param string $method The gate.
	 * @param array<string,mixed> $claim The ExpenseClaim.
	 * @param mixed $items Items per schema, or '*throw*'.
	 * @param mixed $years FiscalYear rows, or '*throw*'.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $method, array $claim, mixed $items, mixed $years): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($items, $years) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private mixed $items, private mixed $years) {
			}

			public function setRegister(string $register): static {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->calls[] = ['findAll' => $params];
				if ($this->years === '*throw*') {
					throw new \RuntimeException('years down');
				}

				return $this->years;
			}

			public function find(string $id): ?array {
				$this->calls[] = 'find:'.$id;
				if ($this->items === '*throw*') {
					throw new \RuntimeException('items down');
				}

				foreach (($this->items[$this->schema] ?? []) as $item) {
					if (($item['id'] ?? '') === $id) {
						return $item;
					}
				}

				return null;
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new ExpenseClaimGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->$method($claim),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
