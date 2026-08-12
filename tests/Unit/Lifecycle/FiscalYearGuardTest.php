<?php

/**
 * Unit tests for FiscalYearGuard.
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

use OCA\Shillinq\Lifecycle\FiscalYearGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FiscalYearGuardTest extends TestCase {
	/**
	 * Build a guard over a set of FiscalPeriod rows the stub ObjectService returns.
	 *
	 * @param array<int,array<string,mixed>> $periods FiscalPeriod rows.
	 *
	 * @return FiscalYearGuard
	 */
	private function buildGuard(array $periods): FiscalYearGuard {
		$stub = new class($periods) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $periods;

			/**
			 * @param array<int,array<string,mixed>> $periods FiscalPeriod rows.
			 */
			public function __construct(array $periods) {
				$this->periods = $periods;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->periods,
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

		return new FiscalYearGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildGuard()

	/**
	 * Good path: every period for the year is closed.
	 *
	 * @return void
	 */
	public function testBeginCloseAllowedWhenAllPeriodsClosed(): void {
		$guard = $this->buildGuard(
			[
				['fiscalYear' => 2026, 'administrationId' => 'adm-1', 'state' => 'closed'],
				['fiscalYear' => 2026, 'administrationId' => 'adm-1', 'state' => 'audit-locked'],
			]
		);

		$allowed = $guard->requireAllPeriodsClosedForYear(['yearNumber' => 2026, 'administrationId' => 'adm-1']);
		self::assertTrue($allowed);

	}//end testBeginCloseAllowedWhenAllPeriodsClosed()

	/**
	 * Bad path: an open period remains — deny beginClose.
	 *
	 * @return void
	 */
	public function testBeginCloseDeniedWhenAPeriodStillOpen(): void {
		$guard = $this->buildGuard(
			[
				['fiscalYear' => 2026, 'administrationId' => 'adm-1', 'state' => 'closed'],
				['fiscalYear' => 2026, 'administrationId' => 'adm-1', 'state' => 'open'],
			]
		);

		$allowed = $guard->requireAllPeriodsClosedForYear(['yearNumber' => 2026, 'administrationId' => 'adm-1']);
		self::assertFalse($allowed);

	}//end testBeginCloseDeniedWhenAPeriodStillOpen()

	/**
	 * No FiscalPeriod records yet — nothing to gate against, allow.
	 *
	 * @return void
	 */
	public function testBeginCloseAllowedWithNoPeriodsYet(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->requireAllPeriodsClosedForYear(['yearNumber' => 2026, 'administrationId' => 'adm-1']);
		self::assertTrue($allowed);

	}//end testBeginCloseAllowedWithNoPeriodsYet()
}//end class
