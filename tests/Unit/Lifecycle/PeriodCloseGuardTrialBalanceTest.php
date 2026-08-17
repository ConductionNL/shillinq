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
	 * @param array<int,array<string,mixed>> $periods FiscalPeriod rows.
	 * @param array<int,array<string,mixed>> $lines GLLine rows.
	 *
	 * @return PeriodCloseGuard
	 */
	private function buildGuard(array $periods, array $lines): PeriodCloseGuard {
		$stub = new class($periods, $lines) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $periods;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $lines;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $periods FiscalPeriod rows.
			 * @param array<int,array<string,mixed>> $lines GLLine rows.
			 */
			public function __construct(array $periods, array $lines) {
				$this->periods = $periods;
				$this->lines = $lines;
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
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$source = ($this->schema === 'GLLine' ? $this->lines : $this->periods);
				$filters = ($params['filters'] ?? []);
				return array_values(
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
				['periodId' => '2026-01', 'side' => 'debit', 'amount' => 18500.40],
				['periodId' => '2026-01', 'side' => 'credit', 'amount' => 18500.40],
			]
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
				['periodId' => '2026-01', 'side' => 'debit', 'amount' => 20000.00],
				['periodId' => '2026-01', 'side' => 'credit', 'amount' => 18500.40],
			]
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
}//end class
