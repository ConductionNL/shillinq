<?php

/**
 * Unit tests for LeaseContractGuard.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-contracts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\LeaseContractGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the draft→active activation guard (REQ-LC-004): a valid classification
 * and the complete economic field set are required; everything else fails closed.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseContractGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var LeaseContractGuard
	 */
	private LeaseContractGuard $guard;

	/**
	 * Set up the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new LeaseContractGuard($this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * A complete capitalised lease may activate (REQ-LC-004).
	 *
	 * @return void
	 */
	public function testValidLeaseActivates(): void {
		self::assertTrue($this->guard->guardActivation('lease-1', $this->validLease()));

	}//end testValidLeaseActivates()

	/**
	 * A null object fails closed (CWE-863).
	 *
	 * @return void
	 */
	public function testNullObjectFailsClosed(): void {
		self::assertFalse($this->guard->guardActivation('lease-1', null));

	}//end testNullObjectFailsClosed()

	/**
	 * An unrecognised classification fails (REQ-LC-002).
	 *
	 * @return void
	 */
	public function testInvalidClassificationFails(): void {
		$lease = $this->validLease();
		$lease['classification'] = 'made-up';
		self::assertFalse($this->guard->guardActivation('lease-1', $lease));

	}//end testInvalidClassificationFails()

	/**
	 * A missing economic field fails closed (REQ-LC-004).
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldFails(): void {
		$lease = $this->validLease();
		unset($lease['ibrPercent']);
		self::assertFalse($this->guard->guardActivation('lease-1', $lease));

	}//end testMissingRequiredFieldFails()

	/**
	 * A capitalised lease with a zero payment cannot activate (REQ-LC-003).
	 *
	 * @return void
	 */
	public function testCapitalisedLeaseNeedsPositivePayment(): void {
		$lease = $this->validLease();
		$lease['basePaymentAmount'] = 0.0;
		self::assertFalse($this->guard->guardActivation('lease-1', $lease));

	}//end testCapitalisedLeaseNeedsPositivePayment()

	/**
	 * A short-term-exempt lease may activate with a zero IBR (REQ-LE-002).
	 *
	 * @return void
	 */
	public function testShortTermExemptAllowsZeroIbr(): void {
		$lease = $this->validLease();
		$lease['classification'] = 'short-term-exempt';
		$lease['ibrPercent'] = 0.0;
		self::assertTrue($this->guard->guardActivation('lease-1', $lease));

	}//end testShortTermExemptAllowsZeroIbr()

	/**
	 * A complete, valid capitalised lease fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function validLease(): array {
		return [
			'leaseNumber' => 'VH-2024-001',
			'commencementDate' => '2024-01-15',
			'endDate' => '2027-01-14',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 425.0,
			'paymentCurrency' => 'EUR',
			'ibrPercent' => 4.25,
			'classification' => 'IFRS16-capitalised',
		];

	}//end validLease()
}//end class
