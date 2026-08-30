<?php

/**
 * Unit tests for CommitmentGuard.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\CommitmentGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CommitmentGuard::canActiveren per design D2 + date-range validation.
 *
 * Covers:
 * - Missing kostenplaats → denied.
 * - Missing grootboekrekening → denied.
 * - Enriched, no milestones → permitted.
 * - Milestone within term → permitted.
 * - Milestone before start → denied.
 * - Milestone after end → denied.
 * - Milestone on boundary dates → permitted.
 * - No declared term → milestone bound skipped (permitted).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CommitmentGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var CommitmentGuard
	 */
	private CommitmentGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new CommitmentGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * Build an enriched obligation with a 1-year term.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function commitment(array $overrides = []): array {
		return array_merge(
			[
				'commitmentNumber' => 'VPL-2026-0001',
				'costCentre' => 'FAC-001',
				'generalLedgerAccount' => '4500',
				'termStart' => '2026-02-01',
				'termEnd' => '2027-01-31',
				'milestones' => [],
			],
			$overrides
		);

	}//end verplichting()

	/**
	 * Missing kostenplaats denies activation (design D2).
	 *
	 * @return void
	 */
	public function testMissingKostenplaatsDeniesActivation(): void {
		$this->assertFalse($this->guard->canActiveren($this->commitment(['costCentre' => ''])));

	}//end testMissingKostenplaatsDeniesActivation()

	/**
	 * Missing grootboekrekening denies activation (design D2).
	 *
	 * @return void
	 */
	public function testMissingGrootboekrekeningDeniesActivation(): void {
		$this->assertFalse($this->guard->canActiveren($this->commitment(['generalLedgerAccount' => ''])));

	}//end testMissingGrootboekrekeningDeniesActivation()

	/**
	 * Enriched obligation with no milestones is permitted.
	 *
	 * @return void
	 */
	public function testEnrichedWithoutMilestonesPermitted(): void {
		$this->assertTrue($this->guard->canActiveren($this->commitment()));

	}//end testEnrichedWithoutMilestonesPermitted()

	/**
	 * Milestone within the term is permitted.
	 *
	 * @return void
	 */
	public function testMilestoneWithinTermPermitted(): void {
		$v = $this->commitment(
			['milestones' => [['milestoneId' => 'MS-001', 'date' => '2026-08-01']]]
		);
		$this->assertTrue($this->guard->canActiveren($v));

	}//end testMilestoneWithinTermPermitted()

	/**
	 * Milestone before the start date denies activation.
	 *
	 * @return void
	 */
	public function testMilestoneBeforeStartDenied(): void {
		$v = $this->commitment(
			['milestones' => [['milestoneId' => 'MS-001', 'date' => '2026-01-01']]]
		);
		$this->assertFalse($this->guard->canActiveren($v));

	}//end testMilestoneBeforeStartDenied()

	/**
	 * Milestone after the end date denies activation.
	 *
	 * @return void
	 */
	public function testMilestoneAfterEndDenied(): void {
		$v = $this->commitment(
			['milestones' => [['milestoneId' => 'MS-001', 'date' => '2027-03-01']]]
		);
		$this->assertFalse($this->guard->canActiveren($v));

	}//end testMilestoneAfterEndDenied()

	/**
	 * Milestones on the exact boundary dates are permitted.
	 *
	 * @return void
	 */
	public function testMilestoneOnBoundaryDatesPermitted(): void {
		$v = $this->commitment(
			[
				'milestones' => [
					['milestoneId' => 'MS-001', 'date' => '2026-02-01'],
					['milestoneId' => 'MS-002', 'date' => '2027-01-31'],
				],
			]
		);
		$this->assertTrue($this->guard->canActiveren($v));

	}//end testMilestoneOnBoundaryDatesPermitted()

	/**
	 * With no declared term, milestone dates are not bounded (permitted).
	 *
	 * @return void
	 */
	public function testNoTermSkipsMilestoneBound(): void {
		$v = $this->commitment(
			[
				'termStart' => '',
				'termEnd' => '',
				'milestones' => [['milestoneId' => 'MS-001', 'date' => '2099-01-01']],
			]
		);
		$this->assertTrue($this->guard->canActiveren($v));

	}//end testNoTermSkipsMilestoneBound()
}//end class
