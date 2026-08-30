<?php

/**
 * Unit tests for ContractLifecycleGuard.
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
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ContractLifecycleGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContractLifecycleGuard.
 *
 * Covers REQ-CLM-002:
 * - canActivate true only when startDate + counterpartyReference + contractOwner present.
 * - canActivate false when any mandatory field missing or empty.
 * - requireTerminationReason true only when terminationReason non-empty.
 */
class ContractLifecycleGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var ContractLifecycleGuard
	 */
	private ContractLifecycleGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->guard = new ContractLifecycleGuard(logger: $this->logger);
	}//end setUp()

	/**
	 * canActivate returns true when all mandatory fields are present (REQ-CLM-002).
	 *
	 * @return void
	 */
	public function testCanActivateTrueWhenAllFieldsPresent(): void {
		$contract = [
			'startDate' => '2026-01-01',
			'counterpartyReference' => 'contacts://acme-bv',
			'contractOwner' => 'alice',
		];

		self::assertTrue($this->guard->canActivate($contract));
	}//end testCanActivateTrueWhenAllFieldsPresent()

	/**
	 * canActivate returns false when a mandatory field is missing (REQ-CLM-002).
	 *
	 * @return void
	 */
	public function testCanActivateFalseWhenContractOwnerMissing(): void {
		$contract = [
			'startDate' => '2026-01-01',
			'counterpartyReference' => 'contacts://acme-bv',
		];

		self::assertFalse($this->guard->canActivate($contract));
	}//end testCanActivateFalseWhenContractOwnerMissing()

	/**
	 * canActivate returns false when a mandatory field is empty / whitespace.
	 *
	 * @return void
	 */
	public function testCanActivateFalseWhenFieldEmpty(): void {
		$contract = [
			'startDate' => '2026-01-01',
			'counterpartyReference' => '   ',
			'contractOwner' => 'alice',
		];

		self::assertFalse($this->guard->canActivate($contract));
	}//end testCanActivateFalseWhenFieldEmpty()

	/**
	 * canActivate returns false for an empty contract array.
	 *
	 * @return void
	 */
	public function testCanActivateFalseForEmptyContract(): void {
		self::assertFalse($this->guard->canActivate([]));
	}//end testCanActivateFalseForEmptyContract()

	/**
	 * requireTerminationReason returns true when a reason is present (REQ-CLM-002).
	 *
	 * @return void
	 */
	public function testRequireTerminationReasonTrueWhenPresent(): void {
		self::assertTrue(
			$this->guard->requireTerminationReason(['terminationReason' => 'Opgezegd binnen termijn'])
		);
	}//end testRequireTerminationReasonTrueWhenPresent()

	/**
	 * requireTerminationReason returns false when the reason is missing or empty.
	 *
	 * @return void
	 */
	public function testRequireTerminationReasonFalseWhenMissingOrEmpty(): void {
		self::assertFalse($this->guard->requireTerminationReason([]));
		self::assertFalse($this->guard->requireTerminationReason(['terminationReason' => '']));
		self::assertFalse($this->guard->requireTerminationReason(['terminationReason' => '   ']));
	}//end testRequireTerminationReasonFalseWhenMissingOrEmpty()
}//end class
