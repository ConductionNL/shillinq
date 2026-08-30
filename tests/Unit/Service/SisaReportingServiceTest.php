<?php

/**
 * Unit tests for SisaReportingService.
 *
 * Covers the audit opinion calculation rule (REQ-SISA-009) and the
 * finalization precondition guard on SisaReportingService.
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
 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SisaReportingService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SisaReportingService.
 *
 * @covers \OCA\Shillinq\Service\SisaReportingService
 *
 * @spec openspec/changes/bookkeeping-sisa-reporting/tasks.md#task-12
 */
class SisaReportingServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var SisaReportingService
	 */
	private SisaReportingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new SisaReportingService();

	}//end setUp()

	/**
	 * REQ-SISA-009: 0 findings + 0 overdue → unqualified.
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionUnqualified(): void {
		$report = [
			'criticalFindingsCount' => 0,
			'majorFindingsCount' => 0,
			'remediationOverdueCount' => 0,
		];
		self::assertSame(expected: 'unqualified', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionUnqualified()

	/**
	 * REQ-SISA-009: 1 major finding, 0 critical, 0 overdue → qualified.
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionQualifiedOneMajor(): void {
		$report = [
			'criticalFindingsCount' => 0,
			'majorFindingsCount' => 1,
			'remediationOverdueCount' => 0,
		];
		self::assertSame(expected: 'qualified', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionQualifiedOneMajor()

	/**
	 * REQ-SISA-009: 2 major findings, 0 critical, 0 overdue → qualified.
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionQualifiedTwoMajor(): void {
		$report = [
			'criticalFindingsCount' => 0,
			'majorFindingsCount' => 2,
			'remediationOverdueCount' => 0,
		];
		self::assertSame(expected: 'qualified', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionQualifiedTwoMajor()

	/**
	 * REQ-SISA-009: 3 major findings → adverse (threshold is ≥3).
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionAdverseThreeMajor(): void {
		$report = [
			'criticalFindingsCount' => 0,
			'majorFindingsCount' => 3,
			'remediationOverdueCount' => 0,
		];
		self::assertSame(expected: 'adverse', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionAdverseThreeMajor()

	/**
	 * REQ-SISA-009: Any critical finding → adverse (even with 0 major).
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionAdverseCritical(): void {
		$report = [
			'criticalFindingsCount' => 1,
			'majorFindingsCount' => 0,
			'remediationOverdueCount' => 0,
		];
		self::assertSame(expected: 'adverse', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionAdverseCritical()

	/**
	 * REQ-SISA-009: Any overdue remediation → disclaimer (overrides finding severity).
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionDisclaimerOverdue(): void {
		$report = [
			'criticalFindingsCount' => 0,
			'majorFindingsCount' => 1,
			'remediationOverdueCount' => 1,
		];
		self::assertSame(expected: 'disclaimer', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionDisclaimerOverdue()

	/**
	 * REQ-SISA-009: Overdue trumps critical (disclaimer wins when both present).
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionDisclaimerTrumpsAdverse(): void {
		$report = [
			'criticalFindingsCount' => 2,
			'majorFindingsCount' => 5,
			'remediationOverdueCount' => 3,
		];
		self::assertSame(expected: 'disclaimer', actual: $this->service->calculateAuditOpinion($report));

	}//end testCalculateAuditOpinionDisclaimerTrumpsAdverse()

	/**
	 * Missing keys default to 0 — empty report is unqualified.
	 *
	 * @return void
	 */
	public function testCalculateAuditOpinionDefaultsMissingKeys(): void {
		self::assertSame(expected: 'unqualified', actual: $this->service->calculateAuditOpinion([]));

	}//end testCalculateAuditOpinionDefaultsMissingKeys()

	/**
	 * ValidateForFinalization returns true when required fields are present.
	 *
	 * @return void
	 */
	public function testValidateForFinalizationPermitsWhenComplete(): void {
		$report = [
			'reportDate' => '2026-12-31T00:00:00Z',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-gem-amsterdam',
		];
		self::assertTrue(condition: $this->service->canBeFinalized($report));

	}//end testValidateForFinalizationPermitsWhenComplete()

	/**
	 * ValidateForFinalization returns false when reportDate is missing.
	 *
	 * @return void
	 */
	public function testValidateForFinalizationDeniesWhenReportDateMissing(): void {
		$report = [
			'fiscalYear' => 2026,
			'administrationId' => 'adm-gem-amsterdam',
		];
		self::assertFalse(condition: $this->service->canBeFinalized($report));

	}//end testValidateForFinalizationDeniesWhenReportDateMissing()

	/**
	 * ValidateForFinalization returns false when administrationId is missing.
	 *
	 * @return void
	 */
	public function testValidateForFinalizationDeniesWhenAdminIdMissing(): void {
		$report = [
			'reportDate' => '2026-12-31T00:00:00Z',
			'fiscalYear' => 2026,
		];
		self::assertFalse(condition: $this->service->canBeFinalized($report));

	}//end testValidateForFinalizationDeniesWhenAdminIdMissing()
}//end class
