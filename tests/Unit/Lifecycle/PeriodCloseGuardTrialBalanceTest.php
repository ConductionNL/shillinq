<?php

/**
 * Unit tests for PeriodCloseGuard::trialBalanceVerifies() (shillinq#425).
 *
 * Kept as a separate test file from PeriodCloseGuardTest so its GLLine-aware
 * stub doesn't have to be reconciled with that file's FiscalPeriod-only
 * stub / existing passing tests.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseGuardTrialBalanceTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $periods      FiscalPeriod rows.
	 * @param array<int,array<string,mixed>> $lines        GLLine rows.
	 * @param array<int,array<string,mixed>> $transactions GLTransaction rows.
	 * @param bool                           $asEntities   Return rows as jsonSerialize() objects, like OpenRegister.
	 *
	 * @return PeriodCloseGuard
	 */
	private function buildGuard(
		array $periods,
		array $lines,
		array $transactions=[],
		bool $asEntities=false
	): PeriodCloseGuard {
		$stub = new class($periods, $lines, $transactions, $asEntities) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $periods;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $lines;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $transactions;

			/**
			 * @var bool
			 */
			private bool $asEntities;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $periods      FiscalPeriod rows.
			 * @param array<int,array<string,mixed>> $lines        GLLine rows.
			 * @param array<int,array<string,mixed>> $transactions GLTransaction rows.
			 * @param bool                           $asEntities   Wrap rows in jsonSerialize() objects.
			 */
			public function __construct(array $periods, array $lines, array $transactions, bool $asEntities) {
				$this->periods = $periods;
				$this->lines = $lines;
				$this->transactions = $transactions;
				$this->asEntities = $asEntities;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params = []): array {
				$source = match ($this->schema) {
					'GLLine' => $this->lines,
					'GLTransaction' => $this->transactions,
					default => $this->periods,
				};
				$filters = ($params['filters'] ?? []);
				$rows = array_values(
					array_filter(
						$source,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
				if ($this->asEntities === false) {
					return $rows;
				}

				return array_map(
					static fn (array $row): \JsonSerializable => new class($row) implements \JsonSerializable {
						/**
						 * @param array<string,mixed> $row Row data.
						 */
						public function __construct(private array $row) {
						}//end __construct()

						/**
						 * @return array<string,mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}//end jsonSerialize()
					},
					$rows
				);
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PeriodCloseGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildGuard()

	/**
	 * Good path: total posted debits equal total posted credits.
	 *
	 * @return void
	 */
	public function testBeginCloseAllowedWhenTrialBalanceBalances(): void {
		$guard = $this->buildGuard(
			[['periodId' => '2026-01', 'administrationId' => 'adm-1']],
			[
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'debit', 'amount' => 18500.40],
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'credit', 'amount' => 18500.40],
			],
			[['id' => 'txn-1', 'periodId' => '2026-01', 'administrationId' => 'adm-1']]
		);

		$allowed = $guard->trialBalanceVerifies(['periodId' => '2026-01', 'administrationId' => 'adm-1']);
		self::assertTrue($allowed);

	}//end testBeginCloseAllowedWhenTrialBalanceBalances()

	/**
	 * Bad path: debits and credits differ — deny beginClose.
	 *
	 * @return void
	 */
	public function testBeginCloseDeniedWhenTrialBalanceDoesNotBalance(): void {
		$guard = $this->buildGuard(
			[['periodId' => '2026-01', 'administrationId' => 'adm-1']],
			[
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'debit', 'amount' => 20000.00],
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'credit', 'amount' => 18500.40],
			],
			[['id' => 'txn-1', 'periodId' => '2026-01', 'administrationId' => 'adm-1']]
		);

		$allowed = $guard->trialBalanceVerifies(['periodId' => '2026-01', 'administrationId' => 'adm-1']);
		self::assertFalse($allowed);

	}//end testBeginCloseDeniedWhenTrialBalanceDoesNotBalance()

	/**
	 * A period with no posted GLLines yet is trivially balanced.
	 *
	 * @return void
	 */
	public function testBeginCloseAllowedWithNoPostedLinesYet(): void {
		$guard = $this->buildGuard([['periodId' => '2026-01', 'administrationId' => 'adm-1']], []);

		$allowed = $guard->trialBalanceVerifies(['periodId' => '2026-01', 'administrationId' => 'adm-1']);
		self::assertTrue($allowed);

	}//end testBeginCloseAllowedWithNoPostedLinesYet()

	/**
	 * OpenRegister returns ObjectEntity rows, not arrays: they must be read
	 * through jsonSerialize(), and an unbalanced period is still denied.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadThroughJsonSerialize(): void {
		$txn = [['id' => 'txn-1', 'periodId' => '2026-01', 'administrationId' => 'adm-1']];
		$balanced = $this->buildGuard(
			[],
			[
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'debit', 'amount' => 250.00],
				['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'credit', 'amount' => 250.00],
			],
			$txn,
			true
		);
		$unbalanced = $this->buildGuard(
			[],
			[['periodId' => '2026-01', 'transactionId' => 'txn-1', 'side' => 'debit', 'amount' => 250.00]],
			$txn,
			true
		);

		self::assertTrue($balanced->trialBalanceVerifies(['periodId' => '2026-01', 'administrationId' => 'adm-1']));
		self::assertFalse($unbalanced->trialBalanceVerifies(['periodId' => '2026-01', 'administrationId' => 'adm-1']));

	}//end testEntityRowsAreReadThroughJsonSerialize()

	/**
	 * Lines posted by another administration in the same period do not count
	 * against this administration's trial balance.
	 *
	 * @return void
	 */
	public function testLinesOfAnotherAdministrationAreIgnored(): void {
		$guard = $this->buildGuard(
			[],
			[
				['periodId' => '2026-04', 'transactionId' => 'txn-own', 'side' => 'debit', 'amount' => 100.00],
				['periodId' => '2026-04', 'transactionId' => 'txn-own', 'side' => 'credit', 'amount' => 100.00],
				['periodId' => '2026-04', 'transactionId' => 'txn-other', 'side' => 'debit', 'amount' => 12000.00],
			],
			[
				['id' => 'txn-own', 'periodId' => '2026-04', 'administrationId' => 'adm-1'],
				['id' => 'txn-other', 'periodId' => '2026-04', 'administrationId' => 'adm-2'],
			],
			true
		);

		self::assertTrue($guard->trialBalanceVerifies(['periodId' => '2026-04', 'administrationId' => 'adm-1']));
		self::assertFalse($guard->trialBalanceVerifies(['periodId' => '2026-04', 'administrationId' => 'adm-2']));

	}//end testLinesOfAnotherAdministrationAreIgnored()

	/**
	 * Without an administration scope every line in the period counts.
	 *
	 * @return void
	 */
	public function testUnscopedPeriodCountsEveryLine(): void {
		$guard = $this->buildGuard(
			[],
			[['periodId' => '2026-04', 'transactionId' => 'txn-other', 'side' => 'debit', 'amount' => 12000.00]],
			[],
			true
		);

		self::assertFalse($guard->trialBalanceVerifies(['periodId' => '2026-04']));

	}//end testUnscopedPeriodCountsEveryLine()
}//end class
