<?php

/**
 * Unit tests for AansluitingResolutionGuard.
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
 * @spec openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\AansluitingResolutionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the fail-closed explained -> resolved gate (REQ-AANS-006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingResolutionGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var AansluitingResolutionGuard
	 */
	private AansluitingResolutionGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new AansluitingResolutionGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * Resolution is permitted when the result is explained with a non-blank reason.
	 *
	 * @return void
	 */
	public function testAllowsResolveWhenExplained(): void {
		$result = ['id' => 'aanslres-1', 'status' => 'explained', 'explanationReasonText' => 'Timing difference, will clear next period.'];

		self::assertTrue($this->guard->canResolve(result: $result));

	}//end testAllowsResolveWhenExplained()

	/**
	 * Resolution is denied when the result is not in the explained status.
	 *
	 * @return void
	 */
	public function testDeniesResolveWhenNotExplained(): void {
		$result = ['id' => 'aanslres-1', 'status' => 'open', 'explanationReasonText' => 'Timing difference.'];
		$this->logger->expects($this->once())->method('warning');

		self::assertFalse($this->guard->canResolve(result: $result));

	}//end testDeniesResolveWhenNotExplained()

	/**
	 * Resolution is denied when explanationReasonText is blank (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testDeniesResolveWhenReasonTextBlank(): void {
		$result = ['id' => 'aanslres-1', 'status' => 'explained', 'explanationReasonText' => '   '];
		$this->logger->expects($this->once())->method('warning');

		self::assertFalse($this->guard->canResolve(result: $result));

	}//end testDeniesResolveWhenReasonTextBlank()

	/**
	 * Resolution is denied when explanationReasonText is entirely absent.
	 *
	 * @return void
	 */
	public function testDeniesResolveWhenReasonTextMissing(): void {
		$result = ['id' => 'aanslres-1', 'status' => 'explained'];
		$this->logger->expects($this->once())->method('warning');

		self::assertFalse($this->guard->canResolve(result: $result));

	}//end testDeniesResolveWhenReasonTextMissing()

	/**
	 * Resolution is denied fail-closed on any internal exception (CWE-863).
	 *
	 * @return void
	 */
	public function testDeniesResolveFailClosedOnException(): void {
		$unstringable = new class {
		};

		$result = ['id' => 'aanslres-1', 'status' => $unstringable];
		$this->logger->expects($this->once())->method('error');

		self::assertFalse($this->guard->canResolve(result: $result));

	}//end testDeniesResolveFailClosedOnException()
}//end class
