<?php

/**
 * Unit tests for IcpFinalizeGuard.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\IcpFinalizeGuard;
use OCA\Shillinq\Service\IcpService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the fail-closed finalize gate (REQ-ICP-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpFinalizeGuardTest extends TestCase {

	/**
	 * Mock IcpService.
	 *
	 * @var IcpService&MockObject
	 */
	private IcpService&MockObject $service;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var IcpFinalizeGuard
	 */
	private IcpFinalizeGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(IcpService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new IcpFinalizeGuard(icpService: $this->service, logger: $this->logger);

	}//end setUp()

	/**
	 * Finalize is permitted when the period reconciles (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testAllowsFinalizeWhenReconciled(): void {
		$this->service->method('reconcile')->willReturn(
			['period' => '2026-Q2', 'icpTotal' => 100.0, 'rubriek3b' => 100.0, 'matches' => true, 'missing' => false, 'difference' => 0.0]
		);

		self::assertTrue($this->guard->canFinalize(administrationId: 'adm-1', period: '2026-Q2'));

	}//end testAllowsFinalizeWhenReconciled()

	/**
	 * Finalize is denied on a reconciliation mismatch (REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testDeniesFinalizeOnMismatch(): void {
		$this->service->method('reconcile')->willReturn(
			['period' => '2026-Q2', 'icpTotal' => 100.0, 'rubriek3b' => 80.0, 'matches' => false, 'missing' => false, 'difference' => 20.0]
		);

		self::assertFalse($this->guard->canFinalize(administrationId: 'adm-1', period: '2026-Q2'));

	}//end testDeniesFinalizeOnMismatch()

	/**
	 * Finalize is denied when no BTW-aangifte exists (icp.btw.missing, REQ-ICP-004).
	 *
	 * @return void
	 */
	public function testDeniesFinalizeWhenBtwMissing(): void {
		$this->service->method('reconcile')->willReturn(
			['period' => '2026-Q2', 'icpTotal' => 100.0, 'rubriek3b' => null, 'matches' => false, 'missing' => true, 'difference' => 0.0]
		);
		$this->logger->expects($this->once())->method('warning');

		self::assertFalse($this->guard->canFinalize(administrationId: 'adm-1', period: '2026-Q2'));

	}//end testDeniesFinalizeWhenBtwMissing()

	/**
	 * Finalize is denied fail-closed on any exception (CWE-863).
	 *
	 * @return void
	 */
	public function testDeniesFinalizeFailClosedOnException(): void {
		$this->service->method('reconcile')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		self::assertFalse($this->guard->canFinalize(administrationId: 'adm-1', period: '2026-Q2'));

	}//end testDeniesFinalizeFailClosedOnException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
