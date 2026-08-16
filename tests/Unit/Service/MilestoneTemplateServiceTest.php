<?php

/**
 * Unit tests for MilestoneTemplateService.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\MilestoneTemplateService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MilestoneTemplateService plan generation (REQ-003) and cashflow
 * forecast (REQ-008).
 *
 * Covers:
 * - Template selection per opdrachttype with 'other' fallback.
 * - Phased plan: 4 quarterly milestones, dates within term, last is eindoplevering.
 * - Recurring plan: 12 milestones summing to ~100%.
 * - Invalid / reversed dates raise InvalidArgumentException.
 * - Cashflow forecast totals exactly the contract value (no cent drift).
 * - sumPercentage tolerates the 12 × 8.33 template noise.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class MilestoneTemplateServiceTest extends TestCase {

	/**
	 * The service under test (reads the bundled templates file).
	 *
	 * @var MilestoneTemplateService
	 */
	private MilestoneTemplateService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new MilestoneTemplateService();

	}//end setUp()

	/**
	 * The getTemplate selection returns the requested template and falls back to 'other'.
	 *
	 * @return void
	 */
	public function testGetTemplateSelectsByTypeWithFallback(): void {
		$this->assertSame('delivery-in-phases', $this->service->getTemplate('delivery-in-phases')['assignmentType']);
		$this->assertSame('other', $this->service->getTemplate('does-not-exist')['assignmentType']);

	}//end testGetTemplateSelectsByTypeWithFallback()

	/**
	 * The phased plan produces 4 milestones inside the term, ending on eindoplevering.
	 *
	 * @return void
	 */
	public function testGeneratePhasedPlan(): void {
		$plan = $this->service->generatePlan('delivery-in-phases', '2026-02-01', '2027-01-31');

		$this->assertCount(4, $plan);
		$this->assertSame('eindoplevering', $plan[3]['deliveryType']);
		$this->assertSame('planned', $plan[0]['status']);

		foreach ($plan as $milestone) {
			$this->assertGreaterThanOrEqual('2026-02-01', $milestone['date']);
			$this->assertLessThanOrEqual('2027-01-31', $milestone['date']);
			$this->assertNotEmpty($milestone['milestoneId']);
		}

		$this->assertSame(100.0, $this->service->sumPercentage($plan));

	}//end testGeneratePhasedPlan()

	/**
	 * The recurring plan produces 12 milestones summing to ~100% (8.33 noise absorbed).
	 *
	 * @return void
	 */
	public function testGenerateRecurringPlan(): void {
		$plan = $this->service->generatePlan('service-provision-continuous', '2026-01-01', '2026-12-31');

		$this->assertCount(12, $plan);
		$this->assertSame(100.0, $this->service->sumPercentage($plan));

	}//end testGenerateRecurringPlan()

	/**
	 * Unknown opdrachttype falls back to the 2-milestone 'other' template.
	 *
	 * @return void
	 */
	public function testGenerateFallbackPlan(): void {
		$plan = $this->service->generatePlan('mystery-type', '2026-01-01', '2026-12-31');

		$this->assertCount(2, $plan);
		$this->assertSame(100.0, $this->service->sumPercentage($plan));

	}//end testGenerateFallbackPlan()

	/**
	 * Reversed contract dates raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testReversedDatesThrow(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->generatePlan('other', '2027-01-01', '2026-01-01');

	}//end testReversedDatesThrow()

	/**
	 * Unparseable contract dates raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testInvalidDatesThrow(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->generatePlan('other', 'not-a-date', 'also-not');

	}//end testInvalidDatesThrow()

	/**
	 * The cashflow forecast distributes the contract value exactly (no cent drift).
	 *
	 * @return void
	 */
	public function testCashflowForecastTotalsExactly(): void {
		$plan = $this->service->generatePlan('service-provision-continuous', '2026-01-01', '2026-12-31');
		$forecast = $this->service->buildCashflowForecast(10000.0, $plan);

		$this->assertCount(12, $forecast);

		$total = 0.0;
		foreach ($forecast as $entry) {
			$total += (float)$entry['amount'];
		}

		$this->assertEqualsWithDelta(10000.0, $total, 0.001);

	}//end testCashflowForecastTotalsExactly()

	/**
	 * The phased forecast distributes a clean 25/25/25/25 split.
	 *
	 * @return void
	 */
	public function testCashflowForecastPhasedSplit(): void {
		$plan = $this->service->generatePlan('delivery-in-phases', '2026-02-01', '2027-01-31');
		$forecast = $this->service->buildCashflowForecast(50000.0, $plan);

		$this->assertCount(4, $forecast);
		foreach ($forecast as $entry) {
			$this->assertEqualsWithDelta(12500.0, (float)$entry['amount'], 0.001);
		}

	}//end testCashflowForecastPhasedSplit()
}//end class
