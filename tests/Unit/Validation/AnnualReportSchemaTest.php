<?php

/**
 * Unit tests for the Titel 9 jaarrekening register fragment (ADR-037).
 *
 * Locks the declarative contract of the bookkeeping-titel-9-jaarrekening spec:
 * the seven schemas (AnnualReport, BalanceSheet, IncomeStatement,
 * CashFlowStatement, Note, DirectorReport, ReviewWorkflow) live in a register.d
 * fragment (not the monolith), declare the spec field sets / closed enums, and
 * the AnnualReport carries the concept -> opgemaakt -> in-review -> vastgesteld
 * -> gedeponeerd lifecycle with PHP guards on the opmaken / vaststellen paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-titel-9-jaarrekening/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Titel 9 jaarrekening register fragment shape against the spec.
 */
final class AnnualReportSchemaTest extends TestCase {

	/**
	 * Decoded fragment contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment;

	/**
	 * Load and decode the register fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json';
		self::assertFileExists($path, 'Titel 9 register fragment must exist (ADR-037, not the monolith).');
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Fragment must be valid JSON.');
		$this->fragment = $decoded;

	}//end setUp()

	/**
	 * The fragment declares all seven jaarrekening schemas under components.schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresAllSchemas(): void {
		self::assertArrayHasKey('components', $this->fragment);
		self::assertArrayHasKey('schemas', $this->fragment['components']);
		$schemas = $this->fragment['components']['schemas'];
		foreach (['AnnualReport', 'BalanceSheet', 'IncomeStatement', 'CashFlowStatement', 'Note', 'DirectorReport', 'ReviewWorkflow'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Missing schema: $name");
		}

	}//end testFragmentDeclaresAllSchemas()

	/**
	 * REQ-T9-001: groottecategorie is the closed four-value enum.
	 *
	 * @return void
	 */
	public function testGroottecategorieIsClosedEnum(): void {
		$schema = $this->fragment['components']['schemas']['AnnualReport'];
		self::assertSame(
			['micro', 'klein', 'middelgroot', 'groot'],
			$schema['properties']['sizeCategory']['enum']
		);

	}//end testGroottecategorieIsClosedEnum()

	/**
	 * REQ-T9-010: the AnnualReport status is the five-value lifecycle enum.
	 *
	 * @return void
	 */
	public function testAnnualReportStatusEnum(): void {
		$schema = $this->fragment['components']['schemas']['AnnualReport'];
		self::assertSame(
			['draft', 'opgemaakt', 'in-review', 'determined', 'gedeponeerd'],
			$schema['properties']['status']['enum']
		);

	}//end testAnnualReportStatusEnum()

	/**
	 * REQ-T9-010: the lifecycle declares the five states, the workflow transitions,
	 * and PHP guards on the opmaken / vaststellen paths.
	 *
	 * @return void
	 */
	public function testAnnualReportLifecycleStatesAndGuards(): void {
		$schema = $this->fragment['components']['schemas']['AnnualReport'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'opgemaakt', 'in-review', 'determined', 'gedeponeerd'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state: $state");
		}

		foreach (['opmaken', 'naarReview', 'reviewAnnuleren', 'vaststellen', 'vaststellenZonderReview', 'deponeren'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions'], "Missing transition: $transition");
		}

		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\AnnualReportGuard::canOpmaken',
			$lifecycle['transitions']['opmaken']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\AnnualReportGuard::canVaststellen',
			$lifecycle['transitions']['vaststellen']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\AnnualReportGuard::canVaststellen',
			$lifecycle['transitions']['vaststellenZonderReview']['requires']
		);

	}//end testAnnualReportLifecycleStatesAndGuards()

	/**
	 * REQ-T9-003: IncomeStatement.model is the closed two-value art. 2:377 enum.
	 *
	 * @return void
	 */
	public function testIncomeStatementModelEnum(): void {
		$schema = $this->fragment['components']['schemas']['IncomeStatement'];
		self::assertSame(
			['a-categorical', 'e-functional'],
			$schema['properties']['model']['enum']
		);

	}//end testIncomeStatementModelEnum()

	/**
	 * REQ-T9-005: CashFlowStatement.method is indirect/direct, default indirect.
	 *
	 * @return void
	 */
	public function testCashFlowMethodEnumDefaultsIndirect(): void {
		$schema = $this->fragment['components']['schemas']['CashFlowStatement'];
		self::assertSame(['indirect', 'direct'], $schema['properties']['method']['enum']);
		self::assertSame('indirect', $schema['properties']['method']['default']);

	}//end testCashFlowMethodEnumDefaultsIndirect()

	/**
	 * REQ-T9-007: ReviewWorkflow.huidigStap mirrors the linear progression.
	 *
	 * @return void
	 */
	public function testReviewWorkflowStepEnum(): void {
		$schema = $this->fragment['components']['schemas']['ReviewWorkflow'];
		self::assertSame(
			['draft', 'in-review', 'determined', 'gedeponeerd'],
			$schema['properties']['currentStap']['enum']
		);

	}//end testReviewWorkflowStepEnum()

	/**
	 * REQ-T9-002: every BalanceSheet rubriek carries a wettelijke rubriek code
	 * and the relation back to AnnualReport is declared.
	 *
	 * The relation is asserted in the CANONICAL dialect (ADR-062 rule 7): a
	 * property-level `$ref` on the foreign-key property, resolving case-exactly
	 * to a schema key in the same register set. It was previously read from the
	 * per-schema `x-openregister-relations` block, which was retired
	 * 2026-07-08; the relationship itself is unchanged, only its expression.
	 *
	 * @return void
	 */
	public function testBalanceSheetRubriekAndRelation(): void {
		$schema = $this->fragment['components']['schemas']['BalanceSheet'];
		$item = $schema['properties']['rubrieken']['items'];
		self::assertContains('rubrieckCode', $item['required']);
		self::assertContains('currentYear', $item['required']);

		self::assertArrayHasKey(
			key: 'reportId',
			array: $schema['properties'],
			message: 'BalanceSheet must declare the reportId foreign key back to AnnualReport.'
		);
		self::assertSame(
			expected: 'AnnualReport',
			actual: ($schema['properties']['reportId']['$ref'] ?? null),
			message: 'BalanceSheet.reportId must carry the canonical property-level $ref to AnnualReport (ADR-062 rule 7).'
		);

	}//end testBalanceSheetRubriekAndRelation()

	/**
	 * REQ-T9-002: the BalanceSheet seed object balances (sum activa = sum passiva).
	 *
	 * @return void
	 */
	public function testBalanceSheetSeedBalances(): void {
		self::assertArrayHasKey('objects', $this->fragment);
		$sheets = array_filter(
			$this->fragment['objects'],
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'BalanceSheet'
		);
		self::assertNotEmpty($sheets, 'At least one BalanceSheet seed expected.');

		foreach ($sheets as $sheet) {
			$activa = 0;
			$passiva = 0;
			foreach ($sheet['rubrieken'] as $section) {
				$cents = (int)round(((float)$section['currentYear']) * 100);
				if (($section['side'] ?? '') === 'assets') {
					$activa += $cents;
				} elseif (($section['side'] ?? '') === 'liabilities') {
					$passiva += $cents;
				}
			}

			self::assertSame($activa, $passiva, 'Balans seed must balance (activa = passiva).');
			self::assertGreaterThan(0, $activa, 'Balans seed must have value.');
		}

	}//end testBalanceSheetSeedBalances()

	/**
	 * Every seed object references a schema this fragment declares.
	 *
	 * @return void
	 */
	public function testSeedObjectsReferenceDeclaredSchemas(): void {
		$declared = array_keys($this->fragment['components']['schemas']);
		foreach ($this->fragment['objects'] as $object) {
			self::assertSame('shillinq', $object['@self']['register'] ?? null);
			self::assertContains($object['@self']['schema'] ?? '', $declared);
		}

	}//end testSeedObjectsReferenceDeclaredSchemas()
}//end class
