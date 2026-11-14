<?php

/**
 * Unit tests for DBAOpdrachtGuard.
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\DBAOpdrachtGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes of DBAOpdrachtGuard::validateOnSave().
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBAOpdrachtGuardTest extends TestCase {
	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var DBAOpdrachtGuard
	 */
	private DBAOpdrachtGuard $guard;

	/**
	 * Build the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new DBAOpdrachtGuard(logger: $this->logger);
	}//end setUp()

	/**
	 * A plain draft save passes and logs nothing.
	 *
	 * @return void
	 */
	public function testDraftSavePasses(): void {
		$this->logger->expects($this->never())->method('info');
		$this->assertSame([], $this->guard->validateOnSave(assignment: []));
	}//end testDraftSavePasses()

	/**
	 * ACTIEF from DRAFT is refused, logged with both statuses.
	 *
	 * @return void
	 */
	public function testActiefWithoutIntakeIsRefused(): void {
		$expected = ['REQ-DBA-001: opdracht mag pas ACTIEF worden na voltooide DBA-intake.'];
		$this->logger->expects($this->once())->method('info')->with(
			'DBAOpdrachtGuard: save blocked',
			['errors' => $expected, 'status' => 'ACTIEF', 'previousStatus' => 'DRAFT']
		);
		$this->assertSame($expected, $this->guard->validateOnSave(assignment: ['intakeStatus' => 'ACTIEF']));
	}//end testActiefWithoutIntakeIsRefused()

	/**
	 * ACTIEF after INTAKE_COMPLETED or from ACTIEF is allowed.
	 *
	 * @return void
	 */
	public function testActiefAfterIntakeIsAllowed(): void {
		$active = ['intakeStatus' => 'ACTIEF'];
		$this->assertSame([], $this->guard->validateOnSave(assignment: $active, previous: ['intakeStatus' => 'INTAKE_COMPLETED']));
		$this->assertSame([], $this->guard->validateOnSave(assignment: $active, previous: ['intakeStatus' => 'ACTIEF']));
	}//end testActiefAfterIntakeIsAllowed()

	/**
	 * ENDED without an end date is refused.
	 *
	 * @return void
	 */
	public function testEndedWithoutEndDateIsRefused(): void {
		$this->assertSame(
			['REQ-DBA-018: BEEINDIGD vereist feitelijkeEindDatum.'],
			$this->guard->validateOnSave(assignment: ['intakeStatus' => 'ENDED'])
		);
	}//end testEndedWithoutEndDateIsRefused()

	/**
	 * ENDED with an end date but no retention deadline is refused.
	 *
	 * @return void
	 */
	public function testEndedWithoutRetentionIsRefused(): void {
		$this->assertSame(
			['REQ-DBA-018: BEEINDIGD vereist retentieDeadline (7 jaar na einddatum).'],
			$this->guard->validateOnSave(assignment: ['intakeStatus' => 'ENDED', 'actualEndDate' => '2026-03-31'])
		);
	}//end testEndedWithoutRetentionIsRefused()

	/**
	 * ENDED with a wrong retention deadline is refused with both dates.
	 *
	 * @return void
	 */
	public function testEndedWithWrongRetentionIsRefused(): void {
		$assignment = ['intakeStatus' => 'ENDED', 'actualEndDate' => '2026-03-31', 'retentionDeadline' => '2030-01-01'];
		$this->assertSame(
			['REQ-DBA-018: retentieDeadline 2030-01-01 wijkt af van verwachte 2033-03-31 (einddatum + 7 jaar).'],
			$this->guard->validateOnSave(assignment: $assignment)
		);
	}//end testEndedWithWrongRetentionIsRefused()

	/**
	 * ENDED with the right retention deadline, or an unparseable end date, passes.
	 *
	 * @return void
	 */
	public function testEndedWithMatchingOrUncheckableRetentionPasses(): void {
		$matching = ['intakeStatus' => 'ENDED', 'actualEndDate' => '2026-03-31', 'retentionDeadline' => '2033-03-31'];
		$this->assertSame([], $this->guard->validateOnSave(assignment: $matching));

		$unparseable = ['intakeStatus' => 'ENDED', 'actualEndDate' => 'not a date', 'retentionDeadline' => '2033-03-31'];
		$this->assertSame([], $this->guard->validateOnSave(assignment: $unparseable));
	}//end testEndedWithMatchingOrUncheckableRetentionPasses()

	/**
	 * HIGH risk with an integer score under 75 is refused; 75, a non-int score or LOW passes.
	 *
	 * @return void
	 */
	public function testHighRiskScoreThreshold(): void {
		$this->assertSame(
			['REQ-DBA-000/003: risicoNiveau HOOG vereist actueleRisicoscore >= 75.'],
			$this->guard->validateOnSave(assignment: ['riskLevel' => 'HIGH', 'actueleRisicoscore' => 74])
		);
		$this->assertSame([], $this->guard->validateOnSave(assignment: ['riskLevel' => 'HIGH', 'actueleRisicoscore' => 75]));
		$this->assertSame([], $this->guard->validateOnSave(assignment: ['riskLevel' => 'HIGH', 'actueleRisicoscore' => '10']));
		$this->assertSame([], $this->guard->validateOnSave(assignment: ['riskLevel' => 'LOW', 'actueleRisicoscore' => 10]));
	}//end testHighRiskScoreThreshold()

	/**
	 * All refusals are collected in rule order and logged once.
	 *
	 * @return void
	 */
	public function testErrorsAreCollectedInRuleOrder(): void {
		$this->logger->expects($this->once())->method('info');
		$errors = $this->guard->validateOnSave(
			assignment: ['intakeStatus' => 'ENDED', 'riskLevel' => 'HIGH', 'actueleRisicoscore' => 1]
		);
		$this->assertSame(
			[
				'REQ-DBA-018: BEEINDIGD vereist feitelijkeEindDatum.',
				'REQ-DBA-000/003: risicoNiveau HOOG vereist actueleRisicoscore >= 75.',
			],
			$errors
		);
	}//end testErrorsAreCollectedInRuleOrder()

	/**
	 * The retention deadline is the end date plus seven years.
	 *
	 * @return void
	 */
	public function testComputeRetentieDeadline(): void {
		$this->assertSame('2033-03-31', $this->guard->computeRetentieDeadline(feitelijkeEindDatum: '2026-03-31'));
		$this->assertNull($this->guard->computeRetentieDeadline(feitelijkeEindDatum: 'not a date'));
	}//end testComputeRetentieDeadline()
}//end class
