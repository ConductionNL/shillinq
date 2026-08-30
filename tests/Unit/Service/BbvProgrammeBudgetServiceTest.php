<?php

/**
 * Unit tests for BbvProgrammeBudgetService + BbvProgrammeBudgetReader — the
 * OpenRegister half of the provincies-BBV Compliance Dashboard (#866/#862).
 *
 * ## What these tests are pointed at
 *
 * Not "does the arithmetic add up" — {@see BbvProgrammeBudgetCalculatorTest}
 * owns that. These cover the reads and the joins, which is where a dashboard
 * quietly reports zero: a GL line linked to its parent by the wrong key, a
 * draft journal counted as spend, another province's budget leaking into the
 * totals, a commitment summed from the wrong fiscal year.
 *
 * There is no `expects($this->once())->method('findAll')` anywhere in this
 * file. A PHPUnit mock cannot observe a named argument — it resolves the call
 * against its OWN signature and then invokes the return callback POSITIONALLY
 * — so an argument expectation over this app's named-argument call style pins
 * the double's defaults rather than the code. The store is the repo's
 * hand-written `InMemoryObjectServiceStub`, which really filters, and tenant
 * isolation is proved by seeding a SECOND administration and asserting its
 * money is absent from the output.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BbvProgrammeBudgetCalculator;
use OCA\Shillinq\Service\BbvProgrammeBudgetReader;
use OCA\Shillinq\Service\BbvProgrammeBudgetService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Dashboard-envelope read/join tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvProgrammeBudgetServiceTest extends TestCase {

	/**
	 * Administration ids the faked membership guard answers with.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-prov-zh'];

	/**
	 * Build the subject over a seeded in-memory OpenRegister.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Schema slug => rows.
	 * @param integer $fiscalYear The fiscal year the window resolver reports.
	 *
	 * @return BbvProgrammeBudgetService
	 */
	private function subject(array $rows, int $fiscalYear = 2026): BbvProgrammeBudgetService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')
			->willReturnCallback(fn (): array => $this->accessible);

		$fiscalYearContext = $this->createMock(FiscalYearContextService::class);
		$fiscalYearContext->method('resolveActiveWindow')->willReturn(
			[
				'fiscalYear' => $fiscalYear,
				'startDate' => sprintf('%d-01-01', $fiscalYear),
				'endDate' => sprintf('%d-12-31', $fiscalYear),
				'administrationId' => 'adm-prov-zh',
			]
		);

		return new BbvProgrammeBudgetService(
			adminContext: $context,
			fiscalYearContext: $fiscalYearContext,
			reader: new BbvProgrammeBudgetReader(
				appConfig: $appConfig,
				logger: new NullLogger(),
				objectService: new InMemoryObjectServiceStub($rows),
			),
			calculator: new BbvProgrammeBudgetCalculator(),
		);
	}//end subject()

	/**
	 * One province, FY2026: a mobiliteit budget, one posted journal with two
	 * GL lines, one draft journal that must NOT count, and one commitment.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function seed(): array {
		return [
			'BbvProgrammeBudget' => [
				[
					'budgetName' => 'Mobiliteit 2026',
					'totalAmount' => 1000000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'mobiliteit',
					'status' => 'approved',
					'fiscalYear' => 2026,
					'administrationId' => 'adm-prov-zh',
				],
				[
					'budgetName' => 'Water 2026 (provisional)',
					'totalAmount' => 400000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'water',
					'status' => 'provisional',
					'fiscalYear' => 2026,
					'administrationId' => 'adm-prov-zh',
				],
				[
					'budgetName' => 'Mobiliteit 2025',
					'totalAmount' => 777000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'mobiliteit',
					'status' => 'approved',
					'fiscalYear' => 2025,
					'administrationId' => 'adm-prov-zh',
				],
			],
			'GLTransaction' => [
				[
					'id' => 'tx-posted-1',
					'transactionNumber' => 'JV-2026-0001',
					'postingDate' => '2026-03-15',
					'periodId' => '2026-03',
					'currency' => 'EUR',
					'description' => 'Wegonderhoud N207',
					'state' => 'posted',
					'administrationId' => 'adm-prov-zh',
				],
				[
					'id' => 'tx-draft-1',
					'transactionNumber' => 'JV-2026-0002',
					'postingDate' => '2026-04-02',
					'periodId' => '2026-04',
					'currency' => 'EUR',
					'description' => 'Nog niet geboekt',
					'state' => 'draft',
					'administrationId' => 'adm-prov-zh',
				],
			],
			'GLLine' => [
				[
					'transactionId' => 'tx-posted-1',
					'lineNumber' => 1,
					'accountNumber' => '4100',
					'side' => 'debit',
					'amount' => 500000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'mobiliteit',
				],
				[
					'transactionId' => 'JV-2026-0001',
					'lineNumber' => 2,
					'accountNumber' => '4110',
					'side' => 'debit',
					'amount' => 100000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'mobiliteit',
				],
				[
					'transactionId' => 'tx-draft-1',
					'lineNumber' => 1,
					'accountNumber' => '4100',
					'side' => 'debit',
					'amount' => 999000.0,
					'currency' => 'EUR',
					'programmeStructure' => 'mobiliteit',
				],
			],
			'CommitmentLine' => [
				[
					'administrationId' => 'adm-prov-zh',
					'ruleNumber' => 1,
					'financialYear' => 2026,
					'programme' => 'mobiliteit',
					'remaining_committed' => 200000.0,
				],
				[
					'administrationId' => 'adm-prov-zh',
					'ruleNumber' => 2,
					'financialYear' => 2025,
					'programme' => 'mobiliteit',
					'remaining_committed' => 888000.0,
				],
			],
			'Account' => [
				[
					'accountNumber' => '4100',
					'name' => 'Wegonderhoud',
					'accountType' => 'expenses',
					'currency' => 'EUR',
					'administrationId' => 'adm-prov-zh',
					'lifecycleState' => 'active',
				],
				[
					'accountNumber' => '4110',
					'name' => 'Bruggen',
					'accountType' => 'expenses',
					'currency' => 'EUR',
					'administrationId' => 'adm-prov-zh',
					'lifecycleState' => 'active',
				],
				[
					'accountNumber' => '0100',
					'name' => 'Gronden',
					'accountType' => 'assets',
					'currency' => 'EUR',
					'administrationId' => 'adm-prov-zh',
					'lifecycleState' => 'active',
				],
			],
		];
	}//end seed()

	/**
	 * Locate one programme's row in the envelope.
	 *
	 * @param array<string,mixed> $envelope The envelope.
	 * @param string $programme The programme code.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function rowFor(array $envelope, string $programme): array {
		foreach ($envelope['programmes']['rows'] as $row) {
			if ($row['programmeStructure'] === $programme) {
				return $row;
			}
		}

		$this->fail(sprintf('no row for programme "%s" in the envelope', $programme));
	}//end rowFor()

	/**
	 * The end-to-end envelope reports the seeded province's real money — the
	 * budget from `Budget`, the spend from the POSTED journal's two lines and
	 * the commitment from `CommitmentLine`.
	 *
	 * @return void
	 */
	public function testEnvelopeReportsTheSeededProvincesRealNumbers(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$row = $this->rowFor($envelope, 'mobiliteit');
		$this->assertSame(1000000.0, $row['totalBudget']);
		$this->assertSame(600000.0, $row['spent']);
		$this->assertSame(200000.0, $row['committed']);
		$this->assertSame(200000.0, $row['remaining']);
		$this->assertSame('green', $row['status']);
	}//end testEnvelopeReportsTheSeededProvincesRealNumbers()

	/**
	 * A GL line referencing its parent by the human `transactionNumber` is
	 * counted exactly like one referencing the OpenRegister object id.
	 *
	 * This is the join most likely to be half-built, and a half-built one is
	 * invisible: it does not error, it just reports less spend. The seed
	 * deliberately links line 1 by object id and line 2 by transactionNumber,
	 * so a service keying only one of the two reads 500 000 instead of
	 * 600 000 and every downstream number stays plausible.
	 *
	 * @return void
	 */
	public function testBothTransactionReferenceStylesAreCounted(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame(
			600000.0,
			$this->rowFor($envelope, 'mobiliteit')['spent'],
			'a line linked by transactionNumber must count exactly like one linked by object id'
		);
	}//end testBothTransactionReferenceStylesAreCounted()

	/**
	 * A DRAFT journal is not spend. The seed's draft carries 999 000, which
	 * would be impossible to miss in the total if it leaked.
	 *
	 * @return void
	 */
	public function testDraftJournalsAreNotCountedAsSpend(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame(600000.0, $envelope['totals']['spent']);
		$this->assertLessThan(999000.0, $envelope['totals']['spent']);
	}//end testDraftJournalsAreNotCountedAsSpend()

	/**
	 * A credit line REDUCES programme spend. Asserted against the same fixture
	 * with and without the correction, because asserting only the corrected
	 * figure would also pass on an implementation that ignored the line
	 * entirely.
	 *
	 * @return void
	 */
	public function testACreditLineReducesSpendRatherThanAddingToIt(): void {
		$withoutCorrection = $this->subject($this->seed())->programmeBudgetVsActuals();

		$corrected = $this->seed();
		$corrected['GLLine'][] = [
			'transactionId' => 'tx-posted-1',
			'lineNumber' => 3,
			'accountNumber' => '4100',
			'side' => 'credit',
			'amount' => 50000.0,
			'currency' => 'EUR',
			'programmeStructure' => 'mobiliteit',
		];
		$withCorrection = $this->subject($corrected)->programmeBudgetVsActuals();

		$this->assertSame(600000.0, $withoutCorrection['totals']['spent']);
		$this->assertSame(550000.0, $withCorrection['totals']['spent']);
	}//end testACreditLineReducesSpendRatherThanAddingToIt()

	/**
	 * Another province's budget, spend and commitments never reach the
	 * envelope. Seeded as a SECOND administration with amounts an order of
	 * magnitude larger, so a leak cannot hide inside a rounding difference.
	 *
	 * @return void
	 */
	public function testAnotherProvincesMoneyIsAbsentFromTheEnvelope(): void {
		$rows = $this->seed();
		$rows['BbvProgrammeBudget'][] = [
			'budgetName' => 'Mobiliteit 2026 (other province)',
			'totalAmount' => 90000000.0,
			'currency' => 'EUR',
			'programmeStructure' => 'mobiliteit',
			'status' => 'approved',
			'fiscalYear' => 2026,
			'administrationId' => 'adm-prov-noord',
		];
		$rows['GLTransaction'][] = [
			'id' => 'tx-other-1',
			'transactionNumber' => 'JV-OTHER-0001',
			'postingDate' => '2026-05-01',
			'periodId' => '2026-05',
			'currency' => 'EUR',
			'description' => 'Andere provincie',
			'state' => 'posted',
			'administrationId' => 'adm-prov-noord',
		];
		$rows['GLLine'][] = [
			'transactionId' => 'tx-other-1',
			'lineNumber' => 1,
			'accountNumber' => '4100',
			'side' => 'debit',
			'amount' => 70000000.0,
			'currency' => 'EUR',
			'programmeStructure' => 'mobiliteit',
		];
		$rows['CommitmentLine'][] = [
			'administrationId' => 'adm-prov-noord',
			'ruleNumber' => 9,
			'financialYear' => 2026,
			'programme' => 'mobiliteit',
			'remaining_committed' => 60000000.0,
		];

		$envelope = $this->subject($rows)->programmeBudgetVsActuals();

		$this->assertSame(1400000.0, $envelope['totals']['totalBudget']);
		$this->assertSame(600000.0, $envelope['totals']['spent']);
		$this->assertSame(200000.0, $envelope['totals']['committed']);
	}//end testAnotherProvincesMoneyIsAbsentFromTheEnvelope()

	/**
	 * REQ-BBC-002's budget-status filter narrows the BUDGET total without
	 * touching spend — the provisional Water budget drops out, the approved
	 * Mobiliteit one stays.
	 *
	 * @return void
	 */
	public function testBudgetStatusFilterNarrowsOnlyTheBudget(): void {
		$service = $this->subject($this->seed());

		$all = $service->programmeBudgetVsActuals();
		$approvedOnly = $service->programmeBudgetVsActuals(statuses: ['approved']);

		$this->assertSame(1400000.0, $all['totals']['totalBudget']);
		$this->assertSame(1000000.0, $approvedOnly['totals']['totalBudget']);
		$this->assertSame($all['totals']['spent'], $approvedOnly['totals']['spent']);
	}//end testBudgetStatusFilterNarrowsOnlyTheBudget()

	/**
	 * REQ-BBC-002's programme filter narrows to the chosen programme, and the
	 * unchosen one disappears from the rows entirely rather than reporting
	 * zeroes.
	 *
	 * @return void
	 */
	public function testProgrammeFilterNarrowsTheReportedRows(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals(programmes: ['water']);

		$codes = array_column($envelope['programmes']['rows'], 'programmeStructure');
		$this->assertSame(['water'], $codes);
		$this->assertSame(400000.0, $envelope['totals']['totalBudget']);
	}//end testProgrammeFilterNarrowsTheReportedRows()

	/**
	 * A commitment booked to another fiscal year is not counted. The seed's
	 * 2025 commitment carries 888 000, which would swamp the 2026 figure.
	 *
	 * @return void
	 */
	public function testCommitmentsFromAnotherFiscalYearAreNotCounted(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame(200000.0, $envelope['totals']['committed']);
	}//end testCommitmentsFromAnotherFiscalYearAreNotCounted()

	/**
	 * `Budget` no longer collides — `budget-core-schema` split it into
	 * `BbvProgrammeBudget` (this reader's own schema, BBV vocabulary:
	 * `fiscalYear` / `totalAmount` / `programmeStructure`) and
	 * `CommitmentBudget` (verplichtingenadministratie vocabulary:
	 * `financialYear` / `authorised_amount` / `programmeCode`), two distinct,
	 * non-colliding schemas (`openspec/changes/budget-core-schema/design.md`
	 * §1/§2c). `BbvProgrammeBudgetReader` now queries `BbvProgrammeBudget`
	 * only, and every record it reads back is BBV-shaped by construction —
	 * there is no second vocabulary left to tolerate, so the dual-vocabulary
	 * assertion this test previously made (`testABudgetWrittenInEitherVocabularyIsCounted`)
	 * no longer applies and was removed rather than left asserting dead
	 * behaviour.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-002
	 */
	public function testOnlyBbvProgrammeBudgetVocabularyIsRead(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame(
			1000000.0,
			$this->rowFor($envelope, 'mobiliteit')['totalBudget'],
			'the BBV vocabulary (fiscalYear/totalAmount/programmeStructure) is read directly, no adapter involved'
		);
		$this->assertSame(1400000.0, $envelope['totals']['totalBudget']);
	}//end testOnlyBbvProgrammeBudgetVocabularyIsRead()

	/**
	 * A caller with no accessible administration gets the empty envelope —
	 * the same SHAPE as a populated one, so the dashboard renders zeroes and a
	 * cross-tenant probe cannot tell an empty province from an inaccessible
	 * one.
	 *
	 * @return void
	 */
	public function testNoAccessibleAdministrationYieldsTheEmptyEnvelopeShape(): void {
		$this->accessible = [];

		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame(0.0, $envelope['totals']['totalBudget']);
		$this->assertSame([], $envelope['programmes']['rows']);
		$this->assertSame([], $envelope['exceptions']);
		$this->assertArrayHasKey('months', $envelope['trend']);
		$this->assertArrayHasKey('generatedAt', $envelope);
	}//end testNoAccessibleAdministrationYieldsTheEmptyEnvelopeShape()

	/**
	 * The fiscal-year options are DISCOVERED from the province's budgets, not
	 * hard-coded — the seed has 2026 and 2025 and both come back, newest
	 * first.
	 *
	 * @return void
	 */
	public function testFiscalYearsAreDiscoveredFromTheBudgets(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertSame([2026, 2025], $envelope['fiscalYears']);
	}//end testFiscalYearsAreDiscoveredFromTheBudgets()

	/**
	 * REQ-BBL-001's account-type facet carries the administration's account
	 * NUMBERS, because `accountType` is a property of `Account` and filtering
	 * GLLine on it would match nothing for every value.
	 *
	 * @return void
	 */
	public function testAccountTypeFacetCarriesGlLineFilterableAccountNumbers(): void {
		$facets = $this->subject($this->seed())->glLineFacets();

		$byValue = array_column($facets['accountTypes'], null, 'value');
		$this->assertArrayHasKey('expenses', $byValue);
		$this->assertArrayHasKey('assets', $byValue);
		$this->assertSame(['4100', '4110'], $byValue['expenses']['accountNumbers']);
		$this->assertSame(['0100'], $byValue['assets']['accountNumbers']);
		$this->assertSame('Expenses', $byValue['expenses']['label']);
	}//end testAccountTypeFacetCarriesGlLineFilterableAccountNumbers()

	/**
	 * The facets offer the seven declared BBV programmes, so the Linker's bar
	 * renders on a fresh instance.
	 *
	 * @return void
	 */
	public function testFacetsOfferTheSevenDeclaredProgrammes(): void {
		$facets = $this->subject($this->seed())->glLineFacets();

		$this->assertSame(
			BbvProgrammeBudgetService::PROGRAMMES,
			array_column($facets['programmes'], 'value')
		);
	}//end testFacetsOfferTheSevenDeclaredProgrammes()

	/**
	 * The assignment-status facet offers `mapped` and NOT `unmapped`.
	 *
	 * That absence is the measurement, not an oversight. An unassigned GL line
	 * has no `programmeStructure` key at all, and OpenRegister's filter grammar
	 * cannot address an ABSENT key — `[empty]`, `[null]` and `[exists]` all
	 * answer ZERO for BOTH truth values on a live instance, while
	 * `vatApplicable[null]=false` (a key every row HAS) answers with all 115
	 * rows, which is the positive control proving the operator family is
	 * otherwise alive. Offering `unmapped` would ship a control that renders,
	 * is clickable, and quietly returns nothing.
	 *
	 * The assertion is written as an explicit NOT-contains so that restoring
	 * the option later has to come with the capability, not just the label.
	 *
	 * @return void
	 */
	public function testAssignmentStatusOffersOnlyTheHalfTheFilterGrammarCanExpress(): void {
		$facets = $this->subject($this->seed())->glLineFacets();
		$values = array_column($facets['assignmentStatuses'], 'value');

		$this->assertSame(['mapped'], $values);
		$this->assertNotContains('unmapped', $values);
	}//end testAssignmentStatusOffersOnlyTheHalfTheFilterGrammarCanExpress()

	/**
	 * Another province's chart of accounts never reaches the facets either.
	 *
	 * @return void
	 */
	public function testFacetsAreScopedToTheCallersAdministrations(): void {
		$rows = $this->seed();
		$rows['Account'][] = [
			'accountNumber' => '9999',
			'name' => 'Andere provincie',
			'accountType' => 'expenses',
			'currency' => 'EUR',
			'administrationId' => 'adm-prov-noord',
			'lifecycleState' => 'active',
		];

		$facets = $this->subject($rows)->glLineFacets();

		$byValue = array_column($facets['accountTypes'], null, 'value');
		$this->assertNotContains('9999', $byValue['expenses']['accountNumbers']);
	}//end testFacetsAreScopedToTheCallersAdministrations()

	/**
	 * REQ-BBC-003 end to end: an overspent programme reaches the exception
	 * list with its overspend, and an in-budget one does not.
	 *
	 * @return void
	 */
	public function testOverspentProgrammeReachesTheExceptionList(): void {
		$rows = $this->seed();
		$rows['CommitmentLine'][0]['remaining_committed'] = 500000.0;

		$envelope = $this->subject($rows)->programmeBudgetVsActuals();

		$this->assertCount(1, $envelope['exceptions']);
		$this->assertSame('mobiliteit', $envelope['exceptions'][0]['programmeStructure']);
		$this->assertSame(100000.0, $envelope['exceptions'][0]['overspent']);
		$this->assertSame('red', $envelope['exceptions'][0]['status']);
	}//end testOverspentProgrammeReachesTheExceptionList()

	/**
	 * The trend covers the whole fiscal-year window and carries the posted
	 * spend in the month it was posted, zero-filling the rest.
	 *
	 * @return void
	 */
	public function testTrendCoversTheWindowAndCarriesThePostedMonth(): void {
		$envelope = $this->subject($this->seed())->programmeBudgetVsActuals();

		$this->assertCount(12, $envelope['trend']['months']);
		$this->assertSame('2026-01', $envelope['trend']['months'][0]);
		$this->assertSame(0.0, $envelope['trend']['cumulativeSpend'][0]);
		$this->assertSame(0.0, $envelope['trend']['cumulativeSpend'][1]);
		$this->assertSame(600000.0, $envelope['trend']['cumulativeSpend'][2]);
		$this->assertSame(600000.0, $envelope['trend']['cumulativeSpend'][11]);
	}//end testTrendCoversTheWindowAndCarriesThePostedMonth()
}//end class
