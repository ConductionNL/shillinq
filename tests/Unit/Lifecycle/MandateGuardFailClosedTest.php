<?php

/**
 * Unit tests for MandateGuard's missing-record and fail-closed paths.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OCA\Shillinq\Lifecycle\MandateGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * A mandate transition without the mandate is refused, a mandate whose
 * fields cannot be read is refused and logged, and dormancy starts exactly
 * 36 months back. Dates are relative to today, so no clock is pinned.
 *
 * @covers \OCA\Shillinq\Lifecycle\MandateGuard
 */
final class MandateGuardFailClosedTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var MandateGuard
	 */
	private MandateGuard $guard;

	/**
	 * Set up the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard  = new MandateGuard($this->logger);
	}//end setUp()

	/**
	 * Without the mandate every transition is refused, silently.
	 *
	 * @return void
	 */
	public function testWithoutTheMandateEveryTransitionIsRefused(): void {
		$this->logger->expects($this->never())->method('error');

		$this->assertFalse($this->guard->canActivate('m-1'));
		$this->assertFalse($this->guard->canCancel('m-1'));
		$this->assertFalse($this->guard->canExpire('m-1'));
	}//end testWithoutTheMandateEveryTransitionIsRefused()

	/**
	 * A mandate never used nor signed cannot expire; a blank reason cannot cancel.
	 *
	 * @return void
	 */
	public function testAMandateWithoutDatesOrReasonIsRefused(): void {
		$this->assertFalse($this->guard->canExpire('m-1', []));
		$this->assertFalse($this->guard->canCancel('m-1', ['cancellationReason' => " \n"]));
		$this->assertFalse($this->guard->canActivate('m-1', ['signedAt' => '2026-01-01', 'scheme' => 'SEPA', 'debtorAccountType' => 'consumer']));
	}//end testAMandateWithoutDatesOrReasonIsRefused()

	/**
	 * A mandate last used exactly 36 months ago is dormant; a day later it is not.
	 *
	 * @return void
	 */
	public function testDormancyStartsExactlyThirtySixMonthsBack(): void {
		$cutoff = (new DateTimeImmutable('today'))->modify('-36 months');

		$this->assertTrue($this->guard->canExpire('m-1', ['lastUsedAt' => $cutoff->format('Y-m-d')]));
		$this->assertFalse($this->guard->canExpire('m-1', ['lastUsedAt' => $cutoff->modify('+1 day')->format('Y-m-d')]));
		$this->assertFalse(
			$this->guard->canExpire('m-1', ['lastUsedAt' => $cutoff->modify('+1 day')->format('Y-m-d'), 'signedAt' => '2000-01-01']),
			'a recent use wins over an old signature'
		);
	}//end testDormancyStartsExactlyThirtySixMonthsBack()

	/**
	 * An unparseable date refuses expiry and logs an error with the mandate id.
	 *
	 * @return void
	 */
	public function testAnUnparseableDateIsRefusedAndLogged(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'MandateGuard: expire check failed — denying transition (fail-closed)',
			$this->callback(static fn (array $context): bool => $context['mandateId'] === 'm-9' && $context['exception'] !== '')
		);

		$this->assertFalse($this->guard->canExpire('m-9', ['lastUsedAt' => 'not a date']));
	}//end testAnUnparseableDateIsRefusedAndLogged()

	/**
	 * A field that cannot be read as text refuses activation and cancellation, logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableFieldIsRefusedAndLogged(): void {
		$messages = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = $message;
				$this->assertSame('m-2', $context['mandateId']);
			}
		);

		$this->assertFalse($this->guard->canActivate('m-2', ['signedAt' => new stdClass()]));
		$this->assertFalse($this->guard->canCancel('m-2', ['cancellationReason' => new stdClass()]));
		$this->assertSame(
			[
				'MandateGuard: activate check failed — denying transition (fail-closed)',
				'MandateGuard: cancel check failed — denying transition (fail-closed)',
			],
			$messages
		);
	}//end testAnUnreadableFieldIsRefusedAndLogged()
}//end class
