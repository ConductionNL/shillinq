<?php

/**
 * DBAFlagGenerationJob detector tests.
 *
 * The boundaries of each DBA signal the flag job raises: the hourly-rate
 * floor, an expired model agreement, theoretical replaceability, a long
 * main relationship, an overdue reassessment and a fixed monthly invoice.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\DBAFlagGenerationJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Each detector fires on its threshold and not one step before it.
 *
 * @covers \OCA\Shillinq\BackgroundJob\DBAFlagGenerationJob
 */
final class DBAFlagDetectorsTest extends TestCase {

	/**
	 * The job under test.
	 *
	 * @var DBAFlagGenerationJob
	 */
	private DBAFlagGenerationJob $job;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->job = new DBAFlagGenerationJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The effective hourly rate is compared to the floor in cents; no hours or no amount raises nothing.
	 *
	 * @return void
	 */
	public function testTheHourlyRateFloor(): void {
		self::assertTrue($this->job->detectVbarGrensOnderschreden(amountCents: 329900, hours: 100.0, vbarGrensCents: 3300));
		self::assertFalse($this->job->detectVbarGrensOnderschreden(amountCents: 330000, hours: 100.0, vbarGrensCents: 3300));
		self::assertFalse($this->job->detectVbarGrensOnderschreden(amountCents: 100, hours: 0.0, vbarGrensCents: 3300));
		self::assertFalse($this->job->detectVbarGrensOnderschreden(amountCents: 0, hours: 10.0, vbarGrensCents: 3300));
	}//end testTheHourlyRateFloor()

	/**
	 * A model agreement has expired only after its end; none or an unreadable date raises nothing.
	 *
	 * @return void
	 */
	public function testAnExpiredModelAgreement(): void {
		$now = new DateTimeImmutable('2026-06-01T12:00:00Z');

		self::assertTrue($this->job->detectModelovereenkomstVerlopen(validTo: '2026-05-31', now: $now));
		self::assertFalse($this->job->detectModelovereenkomstVerlopen(validTo: '2026-06-02', now: $now));
		self::assertFalse($this->job->detectModelovereenkomstVerlopen(validTo: null, now: $now));
		self::assertFalse($this->job->detectModelovereenkomstVerlopen(validTo: '', now: $now));
		self::assertFalse($this->job->detectModelovereenkomstVerlopen(validTo: 'soon', now: $now));
	}//end testAnExpiredModelAgreement()

	/**
	 * Replaceability is theoretical from eighteen months on, with a low replaceable and a high actual score.
	 *
	 * @return void
	 */
	public function testTheoreticalReplaceability(): void {
		self::assertTrue($this->job->detectVervangbaarheidTheoretisch(replaceableScore: 4, replacementActualScore: 10, durationInMonths: 18.0));
		self::assertFalse($this->job->detectVervangbaarheidTheoretisch(replaceableScore: 4, replacementActualScore: 10, durationInMonths: 17.9));
		self::assertFalse($this->job->detectVervangbaarheidTheoretisch(replaceableScore: 5, replacementActualScore: 10, durationInMonths: 24.0));
		self::assertFalse($this->job->detectVervangbaarheidTheoretisch(replaceableScore: 4, replacementActualScore: 9, durationInMonths: 24.0));
	}//end testTheoreticalReplaceability()

	/**
	 * A main relationship is long from two years and half the revenue.
	 *
	 * @return void
	 */
	public function testALongMainRelationship(): void {
		self::assertTrue($this->job->detectLangjarigeHoofdrelatie(durationInJaren: 2.0, revenueShare: 0.5));
		self::assertFalse($this->job->detectLangjarigeHoofdrelatie(durationInJaren: 1.99, revenueShare: 0.9));
		self::assertFalse($this->job->detectLangjarigeHoofdrelatie(durationInJaren: 5.0, revenueShare: 0.49));
	}//end testALongMainRelationship()

	/**
	 * A reassessment is overdue after twelve months and thirty days; none or an unreadable intake raises nothing.
	 *
	 * @return void
	 */
	public function testAnOverdueReassessment(): void {
		$deadline = (new DateTimeImmutable('2025-01-15'))->modify('+12 months')->modify('+30 days');

		self::assertTrue($this->job->detectHerbeoordelingOverdue(intakeDate: '2025-01-15', now: $deadline->modify('+1 second')));
		self::assertFalse($this->job->detectHerbeoordelingOverdue(intakeDate: '2025-01-15', now: $deadline));
		self::assertFalse($this->job->detectHerbeoordelingOverdue(intakeDate: null, now: $deadline->modify('+1 year')));
		self::assertFalse($this->job->detectHerbeoordelingOverdue(intakeDate: 'last spring', now: $deadline->modify('+1 year')));
	}//end testAnOverdueReassessment()

	/**
	 * Six readable monthly invoices of one amount are a fixed monthly invoice; unreadable rows do not count towards six.
	 *
	 * @return void
	 */
	public function testAFixedMonthlyInvoiceNeedsSixReadableInvoices(): void {
		$rows = [];
		foreach (['2026-01-01', '2026-01-31', '2026-03-02', '2026-04-01', '2026-05-01', '2026-05-31'] as $date) {
			$rows[] = ['invoiceDate' => $date, 'bedragCents' => 500000];
		}

		self::assertTrue($this->job->detectVasteMaandfactuur(facturen: $rows));

		$rows[5] = ['invoiceDate' => 'not a date', 'bedragCents' => 500000];
		self::assertFalse($this->job->detectVasteMaandfactuur(facturen: $rows));

		$rows[5] = ['invoiceDate' => '2026-05-31', 'bedragCents' => 0];
		self::assertFalse($this->job->detectVasteMaandfactuur(facturen: $rows));
	}//end testAFixedMonthlyInvoiceNeedsSixReadableInvoices()
}//end class
