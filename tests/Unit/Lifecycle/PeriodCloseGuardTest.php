<?php

/**
 * Unit tests for PeriodCloseGuard.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-14
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
 * Tests the closed-period posting rejection + close/reopen preconditions.
 *
 * Covers REQ-PC-003 (backdating prevention), REQ-PC-002 (mandatory checklist
 * before close), and REQ-PC-006 (close reason before reopen).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseGuardTest extends TestCase {
	/**
	 * Build the guard over a set of PeriodClose records the stub returns.
	 *
	 * @param array<int,array<string,mixed>> $periods PeriodClose rows.
	 *
	 * @return PeriodCloseGuard
	 */
	private function buildGuard(array $periods): PeriodCloseGuard {
		$stub = new class($periods) {

			/**
			 * PeriodClose rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $periods;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $periods PeriodClose rows.
			 */
			public function __construct(array $periods) {
				$this->periods = $periods;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Filter PeriodClose rows by simple equality.
			 *
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

		return new PeriodCloseGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildGuard()

	/**
	 * Posting into a closed period is rejected (REQ-PC-003).
	 *
	 * @return void
	 */
	public function testPostingRejectedAgainstClosedPeriod(): void {
		$guard = $this->buildGuard(
			[
				['periodId' => '2026-01', 'administrationId' => 'adm-1', 'state' => 'closed'],
			]
		);

		$allowed = $guard->periodOpen(['periodId' => '2026-01', 'administrationId' => 'adm-1']);
		self::assertFalse($allowed);

	}//end testPostingRejectedAgainstClosedPeriod()

	/**
	 * Posting into an audit-locked period is rejected (REQ-PC-003).
	 *
	 * @return void
	 */
	public function testPostingRejectedAgainstAuditLockedPeriod(): void {
		$guard = $this->buildGuard(
			[
				['periodId' => '2026-01', 'administrationId' => 'adm-1', 'state' => 'audit-locked'],
			]
		);

		self::assertFalse($guard->periodOpen(['periodId' => '2026-01', 'administrationId' => 'adm-1']));

	}//end testPostingRejectedAgainstAuditLockedPeriod()

	/**
	 * Posting into an open period is allowed (REQ-PC-003).
	 *
	 * @return void
	 */
	public function testPostingAllowedAgainstOpenPeriod(): void {
		$guard = $this->buildGuard(
			[
				['periodId' => '2026-01', 'administrationId' => 'adm-1', 'state' => 'open'],
			]
		);

		self::assertTrue($guard->periodOpen(['periodId' => '2026-01', 'administrationId' => 'adm-1']));

	}//end testPostingAllowedAgainstOpenPeriod()

	/**
	 * A posting whose period has no PeriodClose record is allowed (REQ-PC-003).
	 *
	 * @return void
	 */
	public function testPostingAllowedWhenNoPeriodRecord(): void {
		$guard = $this->buildGuard([]);
		self::assertTrue($guard->periodOpen(['periodId' => '2026-09', 'administrationId' => 'adm-1']));

	}//end testPostingAllowedWhenNoPeriodRecord()

	/**
	 * A posting without a periodId is allowed (no period scope to gate) (REQ-PC-003).
	 *
	 * @return void
	 */
	public function testPostingAllowedWhenNoPeriodId(): void {
		$guard = $this->buildGuard([]);
		self::assertTrue($guard->periodOpen(['administrationId' => 'adm-1']));

	}//end testPostingAllowedWhenNoPeriodId()

	/**
	 * Close is gated until all mandatory (AP/AR) checklist items resolve (REQ-PC-002).
	 *
	 * @return void
	 */
	public function testMandatoryChecklistResolved(): void {
		$guard = $this->buildGuard([]);

		$unresolved = [
			'taskChecklistItems' => [
				['category' => 'ap', 'resolved' => false],
				['category' => 'ar', 'resolved' => true],
			],
		];
		self::assertFalse($guard->mandatoryChecklistResolved($unresolved));

		$resolved = [
			'taskChecklistItems' => [
				['category' => 'ap', 'resolved' => true],
				['category' => 'ar', 'resolved' => true],
				// Non-mandatory bank item left unresolved must not block close.
				['category' => 'bank', 'resolved' => false],
			],
		];
		self::assertTrue($guard->mandatoryChecklistResolved($resolved));

	}//end testMandatoryChecklistResolved()

	/**
	 * Reopen is gated until a non-empty close reason is supplied (REQ-PC-006).
	 *
	 * @return void
	 */
	public function testCloseReasonSupplied(): void {
		$guard = $this->buildGuard([]);
		self::assertFalse($guard->closeReasonSupplied(['closeReason' => '']));
		self::assertFalse($guard->closeReasonSupplied(['closeReason' => '   ']));
		self::assertTrue($guard->closeReasonSupplied(['closeReason' => 'Posted correction']));

	}//end testCloseReasonSupplied()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
