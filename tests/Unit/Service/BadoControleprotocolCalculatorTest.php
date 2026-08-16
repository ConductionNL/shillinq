<?php

/**
 * Unit tests for BadoControleprotocolCalculator.
 *
 * Covers the BADO tolerance-ceiling validation (REQ-002), the dual-axis
 * severity classification against materialiteit ceilings (REQ-006), the
 * per-topic finding aggregation (REQ-006), the four-point opinion decision tree
 * (REQ-007) and the four-eye completeness rule (REQ-006).
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BadoControleprotocolCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic BADO tolerance, severity and opinion helper.
 *
 * PHPUnit assertions take positional arguments; the custom named-parameter sniff
 * does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\BadoControleprotocolCalculator
 */
final class BadoControleprotocolCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var BadoControleprotocolCalculator
	 */
	private BadoControleprotocolCalculator $calc;

	/**
	 * A statutory-default ToleranceMatrix row (1% approval, 3% qualification).
	 *
	 * @var array<string,mixed>
	 */
	private array $defaultRow = [
		'topic' => 'Sociaal Domein',
		'faithfulnessApprovalCeiling' => 1,
		'faithfulnessQualificationCeiling' => 3,
		'lawfulnessApprovalCeiling' => 1,
		'lawfulnessQualificationCeiling' => 3,
		'uncertaintyCeiling' => 3,
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new BadoControleprotocolCalculator();

	}//end setUp()

	/**
	 * REQ-002: statutory-default row passes ceiling validation.
	 *
	 * @return void
	 */
	public function testValidateCeilingsAcceptsStatutoryDefaults(): void {
		self::assertSame([], $this->calc->validateCeilings($this->defaultRow));

	}//end testValidateCeilingsAcceptsStatutoryDefaults()

	/**
	 * REQ-002: tightened ceilings (below statutory max) pass.
	 *
	 * @return void
	 */
	public function testValidateCeilingsAcceptsTightenedCeilings(): void {
		$row = $this->defaultRow;
		$row['lawfulnessApprovalCeiling'] = 0.5;
		$row['lawfulnessQualificationCeiling'] = 1.5;
		self::assertSame([], $this->calc->validateCeilings($row));

	}//end testValidateCeilingsAcceptsTightenedCeilings()

	/**
	 * REQ-002: an approval ceiling above 1% is a violation.
	 *
	 * @return void
	 */
	public function testValidateCeilingsRejectsApprovalAboveOne(): void {
		$row = $this->defaultRow;
		$row['faithfulnessApprovalCeiling'] = 2;
		self::assertContains('faithfulnessApprovalCeiling', $this->calc->validateCeilings($row));

	}//end testValidateCeilingsRejectsApprovalAboveOne()

	/**
	 * REQ-002: a qualification ceiling above 3% is a violation (the 5% scenario).
	 *
	 * @return void
	 */
	public function testValidateCeilingsRejectsQualificationAboveThree(): void {
		$row = $this->defaultRow;
		$row['lawfulnessQualificationCeiling'] = 5;
		self::assertContains('lawfulnessQualificationCeiling', $this->calc->validateCeilings($row));

	}//end testValidateCeilingsRejectsQualificationAboveThree()

	/**
	 * REQ-002: a negative ceiling fails closed.
	 *
	 * @return void
	 */
	public function testValidateCeilingsRejectsNegative(): void {
		$row = $this->defaultRow;
		$row['uncertaintyCeiling'] = -1;
		self::assertContains('uncertaintyCeiling', $this->calc->validateCeilings($row));

	}//end testValidateCeilingsRejectsNegative()

	/**
	 * REQ-006: ceiling EUR amounts derive from materialiteit × percentage.
	 *
	 * 1% approval of €1.2M = €12k; 3% qualification = €36k (in cents).
	 *
	 * @return void
	 */
	public function testCeilingCentsForAxis(): void {
		$ceilings = $this->calc->ceilingCentsForAxis(1200000.0, $this->defaultRow, 'lawfulness');
		self::assertSame(1200000, $ceilings['approvalCents']);
		self::assertSame(3600000, $ceilings['qualificationCents']);

	}//end testCeilingCentsForAxis()

	/**
	 * REQ-006: a compliant/faithful finding is acceptabel regardless of amount.
	 *
	 * @return void
	 */
	public function testClassifySeverityCompliantIsAcceptabel(): void {
		$finding = [
			'amount' => 5000000.0,
			'lawfulness' => 'compliant',
			'faithfulness' => 'faithful',
		];
		self::assertSame('acceptabel', $this->calc->classifySeverity($finding, $this->defaultRow, 1200000.0));

	}//end testClassifySeverityCompliantIsAcceptabel()

	/**
	 * REQ-006: an exception below the approval ceiling is acceptabel.
	 *
	 * Approval ceiling = 1% × €1.2M = €12k; €8k exception is below it.
	 *
	 * @return void
	 */
	public function testClassifySeverityBelowApprovalIsAcceptabel(): void {
		$finding = [
			'amount' => 8000.0,
			'lawfulness' => 'exception',
			'faithfulness' => 'faithful',
		];
		self::assertSame('acceptabel', $this->calc->classifySeverity($finding, $this->defaultRow, 1200000.0));

	}//end testClassifySeverityBelowApprovalIsAcceptabel()

	/**
	 * REQ-006: an exception at/above approval but below qualification is te-corrigeren.
	 *
	 * Approval €12k, qualification €36k; €20k exception falls between.
	 *
	 * @return void
	 */
	public function testClassifySeverityBetweenCeilingsIsTeCorrigeren(): void {
		$finding = [
			'amount' => 20000.0,
			'lawfulness' => 'exception',
			'faithfulness' => 'faithful',
		];
		self::assertSame('te-corrigeren', $this->calc->classifySeverity($finding, $this->defaultRow, 1200000.0));

	}//end testClassifySeverityBetweenCeilingsIsTeCorrigeren()

	/**
	 * REQ-006: an exception at/above the qualification ceiling is materieel.
	 *
	 * Qualification €36k; a €40k getrouwheid misstatement exceeds it.
	 *
	 * @return void
	 */
	public function testClassifySeverityAboveQualificationIsMaterieel(): void {
		$finding = [
			'amount' => 40000.0,
			'lawfulness' => 'compliant',
			'faithfulness' => 'misstated',
		];
		self::assertSame('materieel', $this->calc->classifySeverity($finding, $this->defaultRow, 1200000.0));

	}//end testClassifySeverityAboveQualificationIsMaterieel()

	/**
	 * REQ-006: findingType drives the axis when explicit outcomes are absent.
	 *
	 * @return void
	 */
	public function testClassifySeverityFallsBackToFindingType(): void {
		$finding = [
			'amount' => 40000.0,
			'findingType' => 'uncertainty',
		];
		self::assertSame('materieel', $this->calc->classifySeverity($finding, $this->defaultRow, 1200000.0));

	}//end testClassifySeverityFallsBackToFindingType()

	/**
	 * REQ-006: only agreed/resolved findings aggregate; open findings are ignored.
	 *
	 * @return void
	 */
	public function testAggregateFindingsIgnoresOpenFindings(): void {
		$findings = [
			['topic' => 'Sociaal Domein', 'severity' => 'materieel', 'status' => 'open', 'amount' => 40000.0, 'faithfulness' => 'misstated'],
			['topic' => 'Sociaal Domein', 'severity' => 'acceptabel', 'status' => 'resolved', 'amount' => 5000.0, 'lawfulness' => 'exception'],
		];
		$rows = $this->calc->aggregateFindings($findings);
		self::assertCount(1, $rows);
		self::assertSame(0, $rows[0]['materieelCount']);
		self::assertSame('acceptable', $rows[0]['verdict']);

	}//end testAggregateFindingsIgnoresOpenFindings()

	/**
	 * REQ-006: a materieel finding makes the topic verdict adverse.
	 *
	 * @return void
	 */
	public function testAggregateFindingsMaterieelIsAdverseVerdict(): void {
		$findings = [
			['topic' => 'Sociaal Domein', 'severity' => 'materieel', 'status' => 'resolved', 'amount' => 40000.0, 'faithfulness' => 'misstated'],
		];
		$rows = $this->calc->aggregateFindings($findings);
		self::assertSame('adverse', $rows[0]['verdict']);
		self::assertSame(1, $rows[0]['materieelCount']);

	}//end testAggregateFindingsMaterieelIsAdverseVerdict()

	/**
	 * REQ-007: no materieel findings → goedkeurend.
	 *
	 * @return void
	 */
	public function testDeriveOpinionGoedkeurend(): void {
		$verdicts = [
			['topic' => 'A', 'verdict' => 'acceptable', 'materieelCount' => 0],
			['topic' => 'B', 'verdict' => 'qualified', 'materieelCount' => 0],
		];
		self::assertSame('goedkeurend', $this->calc->deriveOpinion($verdicts));

	}//end testDeriveOpinionGoedkeurend()

	/**
	 * REQ-007: a single materieel topic among many → met-beperking.
	 *
	 * @return void
	 */
	public function testDeriveOpinionMetBeperking(): void {
		$verdicts = [
			['topic' => 'A', 'verdict' => 'adverse', 'materieelCount' => 1],
			['topic' => 'B', 'verdict' => 'acceptable', 'materieelCount' => 0],
			['topic' => 'C', 'verdict' => 'acceptable', 'materieelCount' => 0],
		];
		self::assertSame('met-beperking', $this->calc->deriveOpinion($verdicts));

	}//end testDeriveOpinionMetBeperking()

	/**
	 * REQ-007: materieel findings across the majority of topics → afkeurend.
	 *
	 * @return void
	 */
	public function testDeriveOpinionAfkeurend(): void {
		$verdicts = [
			['topic' => 'A', 'verdict' => 'adverse', 'materieelCount' => 1],
			['topic' => 'B', 'verdict' => 'adverse', 'materieelCount' => 2],
			['topic' => 'C', 'verdict' => 'acceptable', 'materieelCount' => 0],
		];
		self::assertSame('afkeurend', $this->calc->deriveOpinion($verdicts));

	}//end testDeriveOpinionAfkeurend()

	/**
	 * REQ-007: a pervasive scope limitation → oordeelonthouding (overrides all).
	 *
	 * @return void
	 */
	public function testDeriveOpinionOordeelonthoudingOnScopeLimitation(): void {
		$verdicts = [
			['topic' => 'A', 'verdict' => 'adverse', 'materieelCount' => 5],
		];
		self::assertSame('oordeelonthouding', $this->calc->deriveOpinion($verdicts, true));

	}//end testDeriveOpinionOordeelonthoudingOnScopeLimitation()

	/**
	 * REQ-007: an empty finding set yields goedkeurend.
	 *
	 * @return void
	 */
	public function testDeriveOpinionEmptyIsGoedkeurend(): void {
		self::assertSame('goedkeurend', $this->calc->deriveOpinion([]));

	}//end testDeriveOpinionEmptyIsGoedkeurend()

	/**
	 * REQ-006: four-eye is complete only with both responses and both axes.
	 *
	 * @return void
	 */
	public function testIsFourEyeCompleteRequiresAllFour(): void {
		$complete = [
			'controllerResponse' => 'response',
			'auditorConclusion' => 'conclusion',
			'lawfulness' => 'exception',
			'faithfulness' => 'faithful',
		];
		self::assertTrue($this->calc->isFourEyeComplete($complete));

	}//end testIsFourEyeCompleteRequiresAllFour()

	/**
	 * REQ-006: missing auditor conclusion fails the four-eye check.
	 *
	 * @return void
	 */
	public function testIsFourEyeCompleteDeniesWithoutAuditorConclusion(): void {
		$incomplete = [
			'controllerResponse' => 'response',
			'auditorConclusion' => '',
			'lawfulness' => 'exception',
			'faithfulness' => 'faithful',
		];
		self::assertFalse($this->calc->isFourEyeComplete($incomplete));

	}//end testIsFourEyeCompleteDeniesWithoutAuditorConclusion()

	/**
	 * REQ-006: missing an axis classification fails the four-eye check.
	 *
	 * @return void
	 */
	public function testIsFourEyeCompleteDeniesWithoutBothAxes(): void {
		$incomplete = [
			'controllerResponse' => 'response',
			'auditorConclusion' => 'conclusion',
			'lawfulness' => 'exception',
			'faithfulness' => '',
		];
		self::assertFalse($this->calc->isFourEyeComplete($incomplete));

	}//end testIsFourEyeCompleteDeniesWithoutBothAxes()
}//end class
