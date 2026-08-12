<?php

/**
 * Unit tests for SubsidieRepaymentGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
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

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\SubsidieRepaymentGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SubsidieRepaymentGuardTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $installments RepaymentInstallment rows.
	 *
	 * @return SubsidieRepaymentGuard
	 */
	private function buildGuard(array $installments): SubsidieRepaymentGuard {
		$stub = new class($installments) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $installments;

			/**
			 * @param array<int,array<string,mixed>> $installments RepaymentInstallment rows.
			 */
			public function __construct(array $installments) {
				$this->installments = $installments;
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
						$this->installments,
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

		return new SubsidieRepaymentGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildGuard()

	/**
	 * Good path: every installment for this subsidie is paid.
	 *
	 * @return void
	 */
	public function testCloseAllowedWhenAllInstallmentsPaid(): void {
		$guard = $this->buildGuard(
			[
				['subsidyId' => 'sub-1', 'amount' => 5000, 'state' => 'paid'],
				['subsidyId' => 'sub-1', 'amount' => 5000, 'state' => 'paid'],
			]
		);

		$allowed = $guard->requireZeroRepaymentBalance(['id' => 'sub-1']);
		self::assertTrue($allowed);

	}//end testCloseAllowedWhenAllInstallmentsPaid()

	/**
	 * Good path: no installments at all — nothing outstanding.
	 *
	 * @return void
	 */
	public function testCloseAllowedWithNoInstallments(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->requireZeroRepaymentBalance(['id' => 'sub-1']);
		self::assertTrue($allowed);

	}//end testCloseAllowedWithNoInstallments()

	/**
	 * Bad path: an installment remains unpaid — deny close.
	 *
	 * @return void
	 */
	public function testCloseDeniedWithOutstandingInstallment(): void {
		$guard = $this->buildGuard(
			[
				['subsidyId' => 'sub-1', 'amount' => 5000, 'state' => 'paid'],
				['subsidyId' => 'sub-1', 'amount' => 2500, 'state' => 'due'],
			]
		);

		$allowed = $guard->requireZeroRepaymentBalance(['id' => 'sub-1']);
		self::assertFalse($allowed);

	}//end testCloseDeniedWithOutstandingInstallment()
}//end class
