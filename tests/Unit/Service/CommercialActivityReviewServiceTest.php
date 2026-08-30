<?php

/**
 * Unit tests for CommercialActivityReviewService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CommercialActivityReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the annual review-task detector (REQ-WMO-001 §c).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommercialActivityReviewServiceTest extends TestCase {

	/**
	 * Service under test.
	 */
	private CommercialActivityReviewService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new CommercialActivityReviewService();

	}//end setUp()

	/**
	 * REQ-WMO-001 §c: lastReviewedAt > 365 days old triggers the task.
	 */
	public function testStaleActivityIsOverdue(): void {
		$activity = ['state' => 'active', 'lastReviewedAt' => '2024-12-31T00:00:00Z'];
		self::assertTrue($this->svc->reviewOverdueState($activity, '2026-01-15'));

	}//end testStaleActivityIsOverdue()

	/**
	 * Fresh review does not trigger.
	 */
	public function testFreshReviewIsNotOverdue(): void {
		$activity = ['state' => 'active', 'lastReviewedAt' => '2025-12-01T00:00:00Z'];
		self::assertFalse($this->svc->reviewOverdueState($activity, '2026-01-15'));

	}//end testFreshReviewIsNotOverdue()

	/**
	 * Paused activities skip the review-task.
	 */
	public function testPausedActivitiesAreSkipped(): void {
		$activity = ['state' => 'paused', 'lastReviewedAt' => '2024-12-31T00:00:00Z'];
		self::assertFalse($this->svc->reviewOverdueState($activity, '2026-01-15'));

	}//end testPausedActivitiesAreSkipped()

	/**
	 * Activities never reviewed fall back to startDatum.
	 */
	public function testNeverReviewedFallbackToStart(): void {
		$activity = ['state' => 'active', 'startDate' => '2024-01-15'];
		self::assertTrue($this->svc->reviewOverdueState($activity, '2026-01-16'));

	}//end testNeverReviewedFallbackToStart()

	/**
	 * Compose review task envelope mentions code + name.
	 */
	public function testComposeReviewTaskEnvelope(): void {
		$task = $this->svc->composeReviewTask(['id' => 'ca-001', 'code' => 'MO-SP-014', 'name' => 'Dansschool'], '2026-01-15');
		self::assertSame('wmo-annual-review', $task['type']);
		self::assertSame('Annual review due: MO-SP-014 Dansschool', $task['subject']);
		self::assertSame('concerncontroller', $task['assignedTo']);
		self::assertSame('ca-001', $task['commercialActivityId']);

	}//end testComposeReviewTaskEnvelope()

}//end class
