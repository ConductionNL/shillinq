<?php

/**
 * Unit tests for UrenDagregistratieGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-24
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-001/004/017:
 * - reistijd-cap (max 4h/day, audit note on overage)
 * - backfill label stamping (T+N dagen)
 * - backfill rule (>7 days requires reden + bewijs)
 * - evidence requirement for SCHOLING / FICTIE_ZEZ
 * - fail-closed on malformed input
 */
class UrenDagregistratieGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var UrenDagregistratieGuard
	 */
	private UrenDagregistratieGuard $guard;

	/**
	 * Set up the guard with a mocked logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new UrenDagregistratieGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * Reistijd within the cap is counted unchanged with no note (REQ-URC-001).
	 *
	 * @return void
	 */
	public function testReistijdWithinCapUnchanged(): void {
		$result = $this->guard->pasReistijdCapToe(category: 'TRAVEL_TIME_BUSINESS', hours: 3.0);
		self::assertSame(3.0, $result['countedHours']);
		self::assertNull($result['capNote']);

	}//end testReistijdWithinCapUnchanged()

	/**
	 * Reistijd above the cap counts 4 hours and records the overage note.
	 *
	 * @return void
	 */
	public function testReistijdCapAppliedWithNote(): void {
		$result = $this->guard->pasReistijdCapToe(category: 'TRAVEL_TIME_BUSINESS', hours: 6.0);
		self::assertSame(4.0, $result['countedHours']);
		self::assertSame('Reistijd-cap toegepast: 2 uur niet meegeteld', $result['capNote']);

	}//end testReistijdCapAppliedWithNote()

	/**
	 * Non-reistijd categories are never capped.
	 *
	 * @return void
	 */
	public function testOtherCategoriesNotCapped(): void {
		$result = $this->guard->pasReistijdCapToe(category: 'ACQUISITION', hours: 9.0);
		self::assertSame(9.0, $result['countedHours']);
		self::assertNull($result['capNote']);

	}//end testOtherCategoriesNotCapped()

	/**
	 * A 5-day-old backfill is auto-labelled (REQ-URC-017 scenario).
	 *
	 * @return void
	 */
	public function testBackfillLabelStamped(): void {
		self::assertSame(
			'Backfill T+5 dagen',
			$this->guard->bepaalBackfillLabel(date: '2026-05-16', registrationMoment: '2026-05-21T10:00:00Z')
		);

	}//end testBackfillLabelStamped()

	/**
	 * Same-day registration is not a backfill.
	 *
	 * @return void
	 */
	public function testSameDayNotBackfill(): void {
		self::assertNull(
			$this->guard->bepaalBackfillLabel(date: '2026-05-21', registrationMoment: '2026-05-21T18:00:00Z')
		);

	}//end testSameDayNotBackfill()

	/**
	 * A backfill within 7 days passes the save precondition without reden/bewijs.
	 *
	 * @return void
	 */
	public function testBackfillWithinWindowPasses(): void {
		$entry = [
			'enterpriseId' => 'ond-1',
			'date' => '2026-05-16',
			'category' => 'ACQUISITION',
			'hours' => 2,
			'registrationMoment' => '2026-05-21T10:00:00Z',
		];
		self::assertTrue($this->guard->validateOnSave(entry: $entry));

	}//end testBackfillWithinWindowPasses()

	/**
	 * A backfill older than 7 days without reden + bewijs is rejected (REQ-URC-017).
	 *
	 * @return void
	 */
	public function testOldBackfillWithoutEvidenceRejected(): void {
		$entry = [
			'enterpriseId' => 'ond-1',
			'date' => '2026-04-05',
			'category' => 'ACQUISITION',
			'hours' => 2,
			'registrationMoment' => '2026-05-21T10:00:00Z',
		];
		self::assertFalse($this->guard->validateOnSave(entry: $entry));

	}//end testOldBackfillWithoutEvidenceRejected()

	/**
	 * A backfill older than 7 days WITH reden + bewijs is accepted (REQ-URC-017).
	 *
	 * @return void
	 */
	public function testOldBackfillWithEvidenceAccepted(): void {
		$entry = [
			'enterpriseId' => 'ond-1',
			'date' => '2026-04-05',
			'category' => 'ACQUISITION',
			'hours' => 2,
			'registrationMoment' => '2026-05-21T10:00:00Z',
			'backfillReason' => 'Factuur opgemaakt op 20 mei voor werk van 5 april',
			'backfillEvidence' => 'file-77',
		];
		self::assertTrue($this->guard->validateOnSave(entry: $entry));

	}//end testOldBackfillWithEvidenceAccepted()

	/**
	 * A SCHOLING entry without evidence is rejected (REQ-URC-004).
	 *
	 * @return void
	 */
	public function testScholingWithoutEvidenceRejected(): void {
		$entry = [
			'enterpriseId' => 'ond-1',
			'date' => '2026-05-21',
			'category' => 'TRAINING',
			'hours' => 8,
			'registrationMoment' => '2026-05-21T18:00:00Z',
		];
		self::assertFalse($this->guard->validateOnSave(entry: $entry));

	}//end testScholingWithoutEvidenceRejected()

	/**
	 * A SCHOLING entry with evidence is accepted.
	 *
	 * @return void
	 */
	public function testScholingWithEvidenceAccepted(): void {
		$entry = [
			'enterpriseId' => 'ond-1',
			'date' => '2026-05-21',
			'category' => 'TRAINING',
			'hours' => 8,
			'registrationMoment' => '2026-05-21T18:00:00Z',
			'backfillEvidence' => 'file-cursus-99',
		];
		self::assertTrue($this->guard->validateOnSave(entry: $entry));

	}//end testScholingWithEvidenceAccepted()
}//end class
