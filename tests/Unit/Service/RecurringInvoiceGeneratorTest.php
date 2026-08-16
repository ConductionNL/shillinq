<?php

/**
 * Unit tests for RecurringInvoiceGenerator (recurring-invoicing).
 *
 * Covers the testable core of REQ-RIN-002/003/004:
 *  - token expansion ({period}/{month}/{year}, en + nl localization);
 *  - nextRunDate month-end clamping (invoiceDay 31 across Feb/Apr) and the
 *    "clamp does not stick" rule;
 *  - due-period derivation across the five cadences;
 *  - a due profile generates one ordinary ARInvoice with provenance fields
 *    and standard inclusive BTW;
 *  - idempotent regeneration on the (profile, billingPeriod) key;
 *  - a cancelled invoice unblocks regeneration;
 *  - occurrence-count ending decrements + ends the profile.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/recurring-invoicing/specs/recurring-invoicing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * RecurringInvoiceGenerator unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecurringInvoiceGeneratorTest extends TestCase {
	/**
	 * Build a generator wired to an in-memory ObjectService.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return RecurringInvoiceGenerator
	 */
	private function makeGenerator(InMemoryObjectService $os): RecurringInvoiceGenerator {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		return new RecurringInvoiceGenerator(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createStub(LoggerInterface::class),
		);

	}//end makeGenerator()

	/**
	 * A monthly hosting profile fixture.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function profile(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'prof-1',
				'name' => 'Hosting Acme',
				'customerReference' => 'contact:acme',
				'frequency' => 'monthly',
				'interval' => 1,
				'invoiceDay' => 1,
				'startDate' => '2027-01-01',
				'issueMode' => 'draft-for-review',
				'deliveryChannel' => 'email',
				'paymentTermsDays' => 30,
				'status' => 'active',
				'currency' => 'EUR',
				'lines' => [
					[
						'description' => 'Hosting {month} {year}',
						'quantity' => 1,
						'unitPrice' => 99.0,
						'vatCode' => 21,
					],
				],
			],
			$overrides
		);

	}//end profile()

	/**
	 * Token expansion localizes the month name to the document language.
	 *
	 * @return void
	 */
	public function testTokenExpansionLocalized(): void {
		$this->assertSame(
			'Hosting January 2027',
			RecurringInvoiceGenerator::expandTokens('Hosting {month} {year}', '2027-01-15', 'en')
		);
		$this->assertSame(
			'Hosting januari 2027',
			RecurringInvoiceGenerator::expandTokens('Hosting {month} {year}', '2027-01-15', 'nl')
		);
		$this->assertSame(
			'Period: March 2027',
			RecurringInvoiceGenerator::expandTokens('Period: {period}', '2027-03-01', 'en')
		);

	}//end testTokenExpansionLocalized()

	/**
	 * The invoiceDay 31 clamps to the last day of short months and does not stick.
	 *
	 * @return void
	 */
	public function testNextRunDateClampsAndDoesNotStick(): void {
		// Last generated Jan 2027 → next monthly run clamps invoiceDay 31 to
		// 2027-02-28.
		$this->assertSame(
			'2027-02-28',
			RecurringInvoiceGenerator::nextRunDate('monthly', 1, 31, '2027-01-01', '2027-01')
		);
		// After Feb the clamp must not stick: Feb 2027 → 2027-03-31.
		$this->assertSame(
			'2027-03-31',
			RecurringInvoiceGenerator::nextRunDate('monthly', 1, 31, '2027-01-01', '2027-02')
		);
		// April is 30 days → clamp to 2027-04-30.
		$this->assertSame(
			'2027-04-30',
			RecurringInvoiceGenerator::nextRunDate('monthly', 1, 31, '2027-01-01', '2027-03')
		);

	}//end testNextRunDateClampsAndDoesNotStick()

	/**
	 * Cadence multipliers advance the right number of months / weeks.
	 *
	 * @return void
	 */
	public function testNextRunDateCadences(): void {
		$this->assertSame(
			'2027-04-01',
			RecurringInvoiceGenerator::nextRunDate('quarterly', 1, 1, '2027-01-01', '2027-01')
		);
		$this->assertSame(
			'2027-07-01',
			RecurringInvoiceGenerator::nextRunDate('semi-annually', 1, 1, '2027-01-01', '2027-01')
		);
		$this->assertSame(
			'2028-01-01',
			RecurringInvoiceGenerator::nextRunDate('annually', 1, 1, '2027-01-01', '2027-01')
		);
		$this->assertSame(
			'2027-03-01',
			RecurringInvoiceGenerator::nextRunDate('monthly', 2, 1, '2027-01-01', '2027-01')
		);
		$this->assertSame(
			'2027-01-15',
			RecurringInvoiceGenerator::nextRunDate('weekly', 2, 1, '2027-01-01', '2027-01')
		);

	}//end testNextRunDateCadences()

	/**
	 * A due profile generates one ordinary ARInvoice with provenance fields
	 * and standard inclusive BTW, and advances nextRunDate one month.
	 *
	 * @return void
	 */
	public function testDueProfileGeneratesOrdinaryInvoice(): void {
		$os = new InMemoryObjectService();
		$generator = $this->makeGenerator($os);

		$result = $generator->generateForProfile($this->profile());

		$this->assertTrue($result['created']);
		$this->assertSame('2027-01', $result['billingPeriod']);

		$invoice = $result['invoice'];
		$this->assertSame('prof-1', $invoice['recurringProfileId']);
		$this->assertSame('2027-01', $invoice['billingPeriod']);
		$this->assertSame('draft', $invoice['lifecycleState']);
		$this->assertSame('contact:acme', $invoice['customerId']);
		$this->assertSame('2027-01-01', $invoice['invoiceDate']);
		$this->assertSame('2027-01-31', $invoice['dueDate']);
		$this->assertSame(99.0, $invoice['netAmount']);
		// 99.00 * 21% = 20.79 by the standard engine.
		$this->assertSame(20.79, $invoice['vatAmount']);
		$this->assertSame(119.79, $invoice['grossAmount']);
		$this->assertSame('Hosting January 2027', $invoice['lines'][0]['description']);

		// Profile advanced one month.
		$this->assertSame('2027-01', $result['profile']['lastBillingPeriod']);
		$this->assertSame('2027-02-01', $result['profile']['nextRunDate']);
		$this->assertSame('ok', $result['profile']['lastRunStatus']);

	}//end testDueProfileGeneratesOrdinaryInvoice()

	/**
	 * Auto-issue profiles produce an issued (not draft) invoice.
	 *
	 * @return void
	 */
	public function testAutoIssueProducesIssuedInvoice(): void {
		$os = new InMemoryObjectService();
		$generator = $this->makeGenerator($os);

		$result = $generator->generateForProfile(
			$this->profile(['issueMode' => 'auto-issue'])
		);

		$this->assertSame('issued', $result['invoice']['lifecycleState']);

	}//end testAutoIssueProducesIssuedInvoice()

	/**
	 * Regeneration for the same (profile, period) is a no-op (idempotent).
	 *
	 * @return void
	 */
	public function testRegenerationIsIdempotent(): void {
		$os = new InMemoryObjectService();
		$generator = $this->makeGenerator($os);
		$profile = $this->profile();

		$first = $generator->generateForProfile($profile);
		$this->assertTrue($first['created']);

		// Re-run with the ORIGINAL (un-advanced) profile — same billingPeriod
		// key — must find the existing invoice and not create another.
		$second = $generator->generateForProfile($profile);
		$this->assertFalse($second['created']);
		$this->assertSame($first['invoice']['id'], $second['invoice']['id']);
		$this->assertCount(1, $os->dump('ARInvoice'));

	}//end testRegenerationIsIdempotent()

	/**
	 * A cancelled invoice for the period unblocks regeneration.
	 *
	 * @return void
	 */
	public function testCancelledInvoiceUnblocksRegeneration(): void {
		$os = new InMemoryObjectService();
		$os->seed(
			'ARInvoice',
			[
				[
					'id' => 'inv-cancelled',
					'recurringProfileId' => 'prof-1',
					'billingPeriod' => '2027-01',
					'lifecycleState' => 'cancelled',
				],
			]
		);
		$generator = $this->makeGenerator($os);

		$result = $generator->generateForProfile($this->profile());
		$this->assertTrue($result['created']);
		$this->assertNotSame('inv-cancelled', $result['invoice']['id']);

	}//end testCancelledInvoiceUnblocksRegeneration()

	/**
	 * The occurrenceCount ending: the last occurrence ends the profile.
	 *
	 * @return void
	 */
	public function testOccurrenceCountEndsProfile(): void {
		$os = new InMemoryObjectService();
		$generator = $this->makeGenerator($os);

		$result = $generator->generateForProfile(
			$this->profile(['remainingOccurrences' => 1])
		);

		$this->assertSame(0, $result['profile']['remainingOccurrences']);
		$this->assertSame('ended', $result['profile']['status']);

	}//end testOccurrenceCountEndsProfile()

	/**
	 * The first due period for a never-generated profile is its start period.
	 *
	 * @return void
	 */
	public function testDueBillingPeriodFirstRun(): void {
		$this->assertSame('2027-01', RecurringInvoiceGenerator::dueBillingPeriod($this->profile()));
		$this->assertSame(
			'2027-02',
			RecurringInvoiceGenerator::dueBillingPeriod($this->profile(['lastBillingPeriod' => '2027-01']))
		);

	}//end testDueBillingPeriodFirstRun()
}//end class
