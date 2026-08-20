<?php

/**
 * Unit tests for RateScheduleOverlapGuard.
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

use OCA\Shillinq\Guard\RateScheduleOverlapGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RateScheduleOverlapGuardTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $siblings RateSchedule rows.
	 *
	 * @return RateScheduleOverlapGuard
	 */
	private function buildGuard(array $siblings): RateScheduleOverlapGuard {
		$stub = new class($siblings) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $siblings;

			/**
			 * @param array<int,array<string,mixed>> $siblings RateSchedule rows.
			 */
			public function __construct(array $siblings) {
				$this->siblings = $siblings;
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
						$this->siblings,
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

		return new RateScheduleOverlapGuard(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildGuard()

	/**
	 * Good path: no other active schedule for this tier/entity — no overlap.
	 *
	 * @return void
	 */
	public function testReactivateAllowedWithNoSiblings(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->requireNonOverlappingWindow(
			[
				'tier' => 'user',
				'entityId' => 'user-alice',
				'administrationId' => 'adm-1',
				'effectiveDate' => '2026-04-01',
				'expiryDate' => null,
			]
		);
		self::assertTrue($allowed);

	}//end testReactivateAllowedWithNoSiblings()

	/**
	 * Good path: sibling window ends before this one starts — no overlap.
	 *
	 * @return void
	 */
	public function testReactivateAllowedWithNonOverlappingWindow(): void {
		$guard = $this->buildGuard(
			[
				[
					'id' => 'rs-1',
					'tier' => 'user',
					'entityId' => 'user-alice',
					'administrationId' => 'adm-1',
					'status' => 'active',
					'effectiveDate' => '2026-01-01',
					'expiryDate' => '2026-03-31',
				],
			]
		);

		$allowed = $guard->requireNonOverlappingWindow(
			[
				'id' => 'rs-2',
				'tier' => 'user',
				'entityId' => 'user-alice',
				'administrationId' => 'adm-1',
				'effectiveDate' => '2026-04-01',
				'expiryDate' => null,
			]
		);
		self::assertTrue($allowed);

	}//end testReactivateAllowedWithNonOverlappingWindow()

	/**
	 * Bad path: sibling window overlaps this one — deny reactivate.
	 *
	 * @return void
	 */
	public function testReactivateDeniedWithOverlappingWindow(): void {
		$guard = $this->buildGuard(
			[
				[
					'id' => 'rs-1',
					'tier' => 'user',
					'entityId' => 'user-alice',
					'administrationId' => 'adm-1',
					'status' => 'active',
					'effectiveDate' => '2026-01-01',
					'expiryDate' => null,
				],
			]
		);

		$allowed = $guard->requireNonOverlappingWindow(
			[
				'id' => 'rs-2',
				'tier' => 'user',
				'entityId' => 'user-alice',
				'administrationId' => 'adm-1',
				'effectiveDate' => '2026-04-01',
				'expiryDate' => null,
			]
		);
		self::assertFalse($allowed);

	}//end testReactivateDeniedWithOverlappingWindow()

	/**
	 * Re-checking the SAME record (same id) does not self-collide.
	 *
	 * @return void
	 */
	public function testReactivateAllowedWhenOnlyMatchIsSelf(): void {
		$guard = $this->buildGuard(
			[
				[
					'id' => 'rs-1',
					'tier' => 'user',
					'entityId' => 'user-alice',
					'administrationId' => 'adm-1',
					'status' => 'active',
					'effectiveDate' => '2026-01-01',
					'expiryDate' => null,
				],
			]
		);

		$allowed = $guard->requireNonOverlappingWindow(
			[
				'id' => 'rs-1',
				'tier' => 'user',
				'entityId' => 'user-alice',
				'administrationId' => 'adm-1',
				'effectiveDate' => '2026-01-01',
				'expiryDate' => null,
			]
		);
		self::assertTrue($allowed);

	}//end testReactivateAllowedWhenOnlyMatchIsSelf()
}//end class
