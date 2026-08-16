<?php

/**
 * Unit tests for IntercompanyJournalService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntercompanyJournalService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure intercompany mirroring + reconciliation logic (REQ-MA-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntercompanyJournalServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var IntercompanyJournalService
	 */
	private IntercompanyJournalService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new IntercompanyJournalService();

	}//end setUp()

	/**
	 * The mirror swaps source/destination and keeps the amount + intercompany number (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testBuildMirrorSwapsAdministrations(): void {
		$source = [
			'intercompanyNumber' => 'IC-2026-00042',
			'date' => '2026-06-30',
			'kind' => 'management_fee',
			'sourceAdministrationId' => 'adm-werk-001',
			'destinationAdministrationId' => 'adm-beheer-001',
			'amount' => 25000.00,
			'currency' => 'EUR',
			'vatTreatment' => 'verlegd',
			'eliminateOnConsolidation' => true,
			'eliminationAccount' => '9999',
		];

		$mirror = $this->service->buildMirror($source);

		self::assertSame('IC-2026-00042', $mirror['intercompanyNumber']);
		self::assertSame('adm-beheer-001', $mirror['sourceAdministrationId']);
		self::assertSame('adm-werk-001', $mirror['destinationAdministrationId']);
		self::assertEqualsWithDelta(25000.00, $mirror['amount'], 0.001);
		self::assertSame('gekoppeld', $mirror['status']);
		self::assertTrue($mirror['eliminateOnConsolidation']);

	}//end testBuildMirrorSwapsAdministrations()

	/**
	 * Equal amounts on both sides reconcile to a zero variance (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testBalancedSidesHaveNoVariance(): void {
		self::assertTrue($this->service->isBalanced(25000.00, 25000.00));
		self::assertSame(0.0, $this->service->reconcileVariance(25000.00, 25000.00));

	}//end testBalancedSidesHaveNoVariance()

	/**
	 * A mismatched mirror surfaces the variance for manual review (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testMismatchedSidesTrackVariance(): void {
		// One side posts 24,900 instead of 25,000.
		self::assertFalse($this->service->isBalanced(25000.00, 24900.00));
		self::assertSame(100.0, $this->service->reconcileVariance(25000.00, 24900.00));

	}//end testMismatchedSidesTrackVariance()

	/**
	 * Cent arithmetic avoids binary-float rounding error.
	 *
	 * @return void
	 */
	public function testVarianceUsesCentArithmetic(): void {
		self::assertSame(0.1, $this->service->reconcileVariance(0.30, 0.20));
		self::assertTrue($this->service->isBalanced(0.1 + 0.2, 0.30));

	}//end testVarianceUsesCentArithmetic()

	/**
	 * Allowed status transitions follow the declared lifecycle (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testAllowedTransitions(): void {
		self::assertTrue($this->service->isTransitionAllowed('draft', 'gekoppeld'));
		self::assertTrue($this->service->isTransitionAllowed('gekoppeld', 'bevestigd_beide'));
		self::assertTrue($this->service->isTransitionAllowed('bevestigd_beide', 'eliminatie_geboekt'));
		// Re-opening after an edit is allowed.
		self::assertTrue($this->service->isTransitionAllowed('bevestigd_beide', 'draft'));

	}//end testAllowedTransitions()

	/**
	 * Illegal transitions are rejected (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testIllegalTransitionsRejected(): void {
		self::assertFalse($this->service->isTransitionAllowed('draft', 'eliminatie_geboekt'));
		self::assertFalse($this->service->isTransitionAllowed('draft', 'bevestigd_beide'));
		// Eliminated pairs are locked.
		self::assertFalse($this->service->isTransitionAllowed('eliminatie_geboekt', 'draft'));

	}//end testIllegalTransitionsRejected()

	/**
	 * Editing an unconfirmed side returns the pair to concept; an eliminated pair is locked (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testStatusAfterEdit(): void {
		self::assertSame('draft', $this->service->statusAfterEdit('bevestigd_beide'));
		self::assertSame('draft', $this->service->statusAfterEdit('gekoppeld'));
		self::assertSame('draft', $this->service->statusAfterEdit('draft'));
		self::assertSame('eliminatie_geboekt', $this->service->statusAfterEdit('eliminatie_geboekt'));

	}//end testStatusAfterEdit()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
