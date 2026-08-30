<?php

/**
 * Unit tests for PeriodStatusGuard — posting-allowed gating per stage.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PeriodStatusGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Validates REQ-CLS-001 posting-restriction enforcement.
 *
 * The guard expects a real ObjectService for the lookup; we stub the container
 * to surface a fake service whose findAll() returns the PeriodStatus record(s)
 * we provide per test.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-28
 */
final class PeriodStatusGuardTest extends TestCase {
	/**
	 * Build a guard with a stub container whose ObjectService returns the supplied PeriodStatus row.
	 *
	 * @param array<string,mixed>|null $periodStatus PeriodStatus row to return, or null for "no record".
	 *
	 * @return PeriodStatusGuard
	 */
	private function guard(?array $periodStatus): PeriodStatusGuard {
		// Anonymous ObjectService stub: implements the fluent setRegister / setSchema / findAll chain.
		$objectService = new class($periodStatus) {
			public function __construct(
				private readonly ?array $row,
			) {
			}
			public function setRegister(string $register): self {
				return $this;
			}
			public function setSchema(string $schema): self {
				return $this;
			}
			/**
			 * @param array<string,mixed> $opts
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $opts): array {
				return $this->row === null ? [] : [$this->row];
			}
		};

		$container = new class($objectService) implements ContainerInterface {
			public function __construct(
				private readonly object $svc,
			) {
			}
			public function get(string $id): object {
				return $this->svc;
			}
			public function has(string $id): bool {
				return true;
			}
		};

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new PeriodStatusGuard( $config, new NullLogger(),
			objectService: new DuckObjectServiceAdapter($objectService),
		);
	}//end guard()

	/**
	 * Open period accepts any posting (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testOpenPeriodAcceptsPosting(): void {
		$guard = $this->guard(periodStatus: ['stage' => 'open']);
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'administrationId' => 'adm-smb-1']));

	}//end testOpenPeriodAcceptsPosting()

	/**
	 * Periods with no PeriodStatus record allow posting — feature is opt-in (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testUngatedPeriodAcceptsPosting(): void {
		$guard = $this->guard(periodStatus: null);
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => '2026-03']));

	}//end testUngatedPeriodAcceptsPosting()

	/**
	 * Soft-closed period rejects regular posting but accepts accrual reversal (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testSoftClosedPeriodRejectsRegularPostingAndAcceptsReversal(): void {
		$guard = $this->guard(periodStatus: ['stage' => 'soft-closed']);
		self::assertFalse($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'administrationId' => 'adm-smb-1', 'postingKind' => 'regular']));
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'administrationId' => 'adm-smb-1', 'postingKind' => 'accrual-reversal']));
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'administrationId' => 'adm-smb-1', 'postingKind' => 'correction']));

	}//end testSoftClosedPeriodRejectsRegularPostingAndAcceptsReversal()

	/**
	 * Hard-closed period rejects posting unless controllerOverride + exceptionJournal (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testHardClosedRejectsWithoutOverride(): void {
		$guard = $this->guard(periodStatus: ['stage' => 'hard-closed']);
		self::assertFalse($guard->postingAllowed(transaction: ['periodId' => '2026-03']));
		self::assertFalse($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'controllerOverride' => true]));
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'controllerOverride' => true, 'exceptionJournal' => true]));

	}//end testHardClosedRejectsWithoutOverride()

	/**
	 * Locked period rejects every posting, even with override (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testLockedPeriodRejectsEvenWithOverride(): void {
		$guard = $this->guard(periodStatus: ['stage' => 'locked']);
		self::assertFalse($guard->postingAllowed(transaction: ['periodId' => '2026-03', 'controllerOverride' => true, 'exceptionJournal' => true]));

	}//end testLockedPeriodRejectsEvenWithOverride()

	/**
	 * Bad periodId format returns true (no period to gate against).
	 *
	 * @return void
	 */
	public function testInvalidPeriodIdAcceptsPosting(): void {
		$guard = $this->guard(periodStatus: null);
		self::assertTrue($guard->postingAllowed(transaction: ['periodId' => 'NOT-A-PERIOD']));

	}//end testInvalidPeriodIdAcceptsPosting()
}
