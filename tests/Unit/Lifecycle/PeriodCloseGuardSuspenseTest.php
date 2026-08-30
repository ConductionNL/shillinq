<?php

/**
 * Unit tests for PeriodCloseGuard::suspenseAccountDrained().
 *
 * Proves the declarative-parity close blocker (payment-control-guards,
 * REQ-PCG-003): a period whose administration still has unmatched /
 * routed-to-suspense bank items CANNOT close; an empty worklist allows the
 * close; a record without an administration scope allows the close; and any
 * failure to determine the worklist FAILS CLOSED (denies the close).
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the suspense-account close blocker.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseGuardSuspenseTest extends TestCase {

	/**
	 * Build the guard with a container that yields the given ageing double.
	 *
	 * @param SuspenseAgeingService $ageing The ageing service the container returns.
	 *
	 * @return PeriodCloseGuard
	 */
	private function guardWith(SuspenseAgeingService $ageing): PeriodCloseGuard {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($ageing);

		return new PeriodCloseGuard(
			container: $container,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end guardWith()

	/**
	 * A non-empty suspense worklist BLOCKS the close — the failing-path proof.
	 *
	 * @return void
	 */
	public function testNonEmptySuspenseBlocksClose(): void {
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('hasUnresolvedItems')->willReturn(true);

		$drained = $this->guardWith($ageing)->suspenseAccountDrained(['administrationId' => 'adm-1']);

		self::assertFalse($drained, 'A period with unresolved suspense items must not be closable');

	}//end testNonEmptySuspenseBlocksClose()

	/**
	 * An empty suspense worklist allows the close.
	 *
	 * @return void
	 */
	public function testEmptySuspenseAllowsClose(): void {
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('hasUnresolvedItems')->willReturn(false);

		$drained = $this->guardWith($ageing)->suspenseAccountDrained(['administrationId' => 'adm-1']);

		self::assertTrue($drained);

	}//end testEmptySuspenseAllowsClose()

	/**
	 * A record without an administration scope is allowed (no scope, allow).
	 *
	 * @return void
	 */
	public function testNoAdministrationAllowsClose(): void {
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->expects($this->never())->method('hasUnresolvedItems');

		$drained = $this->guardWith($ageing)->suspenseAccountDrained(['periodId' => '2026-01']);

		self::assertTrue($drained);

	}//end testNoAdministrationAllowsClose()

	/**
	 * A failure to determine the worklist FAILS CLOSED (denies the close).
	 *
	 * @return void
	 */
	public function testFailsClosedWhenWorklistUnreadable(): void {
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('hasUnresolvedItems')->willThrowException(new \RuntimeException('reconciliation backend unavailable'));

		$drained = $this->guardWith($ageing)->suspenseAccountDrained(['administrationId' => 'adm-1']);

		self::assertFalse($drained, 'An indeterminate suspense check must block the close (fail-closed)');

	}//end testFailsClosedWhenWorklistUnreadable()
}//end class
