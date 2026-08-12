<?php

/**
 * Unit tests for MandateGuard.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\MandateGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MandateGuard covering REQ-SDD-001 (scheme/account consistency)
 * and REQ-SDD-008 (cancellation reason + 36-month dormancy).
 */
class MandateGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var MandateGuard
	 */
	private MandateGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->guard = new MandateGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * CORE + consumer with a signedAt activates (REQ-SDD-001).
	 *
	 * @return void
	 */
	public function testCoreConsumerActivates(): void {
		$object = ['scheme' => 'CORE', 'debtorAccountType' => 'consumer', 'signedAt' => '2026-05-10'];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivate(mandateId: 'm1', object: $object));
	}//end testCoreConsumerActivates()

	/**
	 * B2B + consumer is a scheme mismatch and cannot activate (REQ-SDD-001).
	 *
	 * @return void
	 */
	public function testB2bConsumerCannotActivate(): void {
		$object = ['scheme' => 'B2B', 'debtorAccountType' => 'consumer', 'signedAt' => '2026-05-10'];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivate(mandateId: 'm2', object: $object));
	}//end testB2bConsumerCannotActivate()

	/**
	 * An unsigned mandate cannot activate (REQ-SDD-001).
	 *
	 * @return void
	 */
	public function testUnsignedMandateCannotActivate(): void {
		$object = ['scheme' => 'CORE', 'debtorAccountType' => 'consumer', 'signedAt' => ''];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivate(mandateId: 'm3', object: $object));
	}//end testUnsignedMandateCannotActivate()

	/**
	 * Scheme/account-type helper matrix (REQ-SDD-001).
	 *
	 * @return void
	 */
	public function testSchemeMatchesAccountType(): void {
		self::assertTrue(MandateGuard::schemeMatchesAccountType('CORE', 'consumer'));
		self::assertTrue(MandateGuard::schemeMatchesAccountType('B2B', 'business'));
		self::assertFalse(MandateGuard::schemeMatchesAccountType('CORE', 'business'));
		self::assertFalse(MandateGuard::schemeMatchesAccountType('B2B', 'consumer'));
		self::assertFalse(MandateGuard::schemeMatchesAccountType('', 'consumer'));
	}//end testSchemeMatchesAccountType()

	/**
	 * Cancelling requires a reason (REQ-SDD-008).
	 *
	 * @return void
	 */
	public function testCancelRequiresReason(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canCancel(mandateId: 'm4', object: ['cancellationReason' => 'Klant opgezegd']));
		self::assertFalse($this->guard->canCancel(mandateId: 'm5', object: ['cancellationReason' => '']));
		self::assertFalse($this->guard->canCancel(mandateId: 'm6', object: []));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testCancelRequiresReason()

	/**
	 * A mandate idle beyond 36 months may expire; a recently-used one may not (REQ-SDD-008).
	 *
	 * @return void
	 */
	public function testDormancyExpiry(): void {
		$dormant = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P40M'))->format('Y-m-d');
		$recent = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P2M'))->format('Y-m-d');

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canExpire(mandateId: 'm7', object: ['lastUsedAt' => $dormant]));
		self::assertFalse($this->guard->canExpire(mandateId: 'm8', object: ['lastUsedAt' => $recent]));
		// No anchor at all cannot be evaluated -> not expired.
		self::assertFalse($this->guard->canExpire(mandateId: 'm9', object: []));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testDormancyExpiry()

	/**
	 * Dormancy falls back to signedAt when lastUsedAt is absent (REQ-SDD-008).
	 *
	 * @return void
	 */
	public function testDormancyFallsBackToSignedAt(): void {
		$oldSign = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P50M'))->format('Y-m-d');
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canExpire(mandateId: 'm10', object: ['signedAt' => $oldSign]));
	}//end testDormancyFallsBackToSignedAt()
}//end class
