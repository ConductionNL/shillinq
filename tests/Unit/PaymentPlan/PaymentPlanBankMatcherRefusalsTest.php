<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\Kernel\Exception\PaymentPlanRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lines that cannot pay a plan, and payments a plan refuses (REQ-RPPL-003).
 */
final class PaymentPlanBankMatcherRefusalsTest extends TestCase {
	use PaymentPlanFixture;

	/**
	 * Seed Café De Zwaan.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * A matcher whose warnings land in the given logger.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return PaymentPlanBankMatcher
	 */
	private function matcherLoggingTo(LoggerInterface $logger): PaymentPlanBankMatcher {
		$location = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);
		$matches  = new ManualMatchService($this->store, $this->runner(), $location, $logger);
		return new PaymentPlanBankMatcher($this->store, $this->settings(), $matches, $this->service(), $logger);
	}//end matcherLoggingTo()

	/**
	 * Outgoing and zero lines are never candidates, even when they name the plan.
	 *
	 * @return void
	 */
	public function testOutgoingAndZeroLinesAreNoCandidates(): void {
		$this->activeZwaanPlan();

		$this->assertSame([], $this->matcher()->candidates($this->line('line-out', -403.33, 'RGL-2026-0007 terugboeking')));
		$this->assertSame([], $this->matcher()->candidates($this->line('line-zero', 0.004, 'RGL-2026-0007')));
	}//end testOutgoingAndZeroLinesAreNoCandidates()

	/**
	 * A line without an administration looks at no plans.
	 *
	 * @return void
	 */
	public function testALineWithoutAnAdministrationIsNoCandidate(): void {
		$this->activeZwaanPlan();
		$line = $this->line('line-anon', 403.33, 'RGL-2026-0007 termijn 1');
		$line['administrationId'] = '';

		$this->assertSame([], $this->matcher()->candidates($line));
		$this->assertNull($this->matcher()->book($line));
	}//end testALineWithoutAnAdministrationIsNoCandidate()

	/**
	 * The reference must stand as a word: a longer reference containing it is not a match.
	 *
	 * @return void
	 */
	public function testTheReferenceMustStandAlone(): void {
		$this->activeZwaanPlan();

		$this->assertSame([], $this->matcher()->candidates($this->line('line-a', 403.33, 'XRGL-2026-0007 termijn', 'NL02ABNA0123456789')));
		$this->assertSame([], $this->matcher()->candidates($this->line('line-b', 403.33, 'RGL-2026-00071 termijn', 'NL02ABNA0123456789')));
		$slashed = $this->line('line-c', 403.33, 'betaling/rgl-2026-0007/nov', 'NL02ABNA0123456789');
		$this->assertSame('high', $this->matcher()->candidates($slashed)[0]['confidence']);
	}//end testTheReferenceMustStandAlone()

	/**
	 * The customer's account is compared without spaces and case.
	 *
	 * @return void
	 */
	public function testTheCustomersAccountIsNormalised(): void {
		$this->activeZwaanPlan();

		$candidates = $this->matcher()->candidates($this->line('line-iban', 403.33, 'termijn november', ' nl44 rabo 0123 4567 89 '));

		$this->assertCount(1, $candidates);
		$this->assertSame('medium', $candidates[0]['confidence']);
	}//end testTheCustomersAccountIsNormalised()

	/**
	 * Paying a plan from a line of another administration is refused as if the plan did not exist.
	 *
	 * @return void
	 */
	public function testAPaymentFromAnotherAdministrationIsRefused(): void {
		$plan = $this->activeZwaanPlan();
		$line = $this->line('line-other', 403.33, 'RGL-2026-0007');
		$line['administrationId'] = 'adm-elsewhere';

		try {
			$this->matcher()->payFromLine((string)$plan['id'], $line, 'bookkeeper', 'manual');
			$this->fail('A payment from another administration was taken.');
		} catch (PaymentPlanRefusedException $e) {
			$this->assertSame('This payment plan does not exist.', $e->getMessage());
		}

		$this->assertSame('pending', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testAPaymentFromAnotherAdministrationIsRefused()

	/**
	 * A plan that is not active takes no payment.
	 *
	 * @return void
	 */
	public function testAPlanThatIsNotActiveTakesNoPayment(): void {
		$this->expectException(PaymentPlanRefusedException::class);
		$this->expectExceptionMessage('Payments are only taken on an active plan.');

		$this->matcher()->payFromLine('plan-old-1', $this->line('line-done', 100.0, 'RGL-2026-0001'), 'bookkeeper', 'manual');
	}//end testAPlanThatIsNotActiveTakesNoPayment()

	/**
	 * A line that cannot be booked to its one plan is logged and left waiting.
	 *
	 * @return void
	 */
	public function testALineThatCannotBeBookedWaits(): void {
		$this->activeZwaanPlan();
		$line = $this->line('line-t1', 403.33, 'RGL-2026-0007 termijn 1');
		$line['id'] = 'line-never-stored';
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'PaymentPlanBankMatcher: line could not be booked to its plan, it waits',
			$this->arrayHasKey('exception')
		);

		$this->assertNull($this->matcherLoggingTo($logger)->book($line));
		$this->assertSame('pending', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testALineThatCannotBeBookedWaits()
}//end class
