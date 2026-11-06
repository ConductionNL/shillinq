<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every ledger, journal, budget and BBV schema is protected by an authorization block OpenRegister enforces.
 */
final class LedgerBudgetAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'Account' => ['Account'],
			'AllocationRule' => ['AllocationRule'],
			'AnalyticalDimension' => ['AnalyticalDimension'],
			'AnnualBudget' => ['AnnualBudget'],
			'AutoAccrualPosting' => ['AutoAccrualPosting'],
			'AutoAccrualRule' => ['AutoAccrualRule'],
			'BbvAccountMapping' => ['BbvAccountMapping'],
			'BBVProgramma' => ['BBVProgramma'],
			'BBVProgramme' => ['BBVProgramme'],
			'BbvProgrammeBudget' => ['BbvProgrammeBudget'],
			'BbvStatement' => ['BbvStatement'],
			'BbvTaakveld' => ['BbvTaakveld'],
			'Begrotingswijziging' => ['Begrotingswijziging'],
			'BudgetBBVMapping' => ['BudgetBBVMapping'],
			'BudgetLine' => ['BudgetLine'],
			'BudgetLineDerivation' => ['BudgetLineDerivation'],
			'BudgetScenario' => ['BudgetScenario'],
			'BudgetScenarioModifier' => ['BudgetScenarioModifier'],
			'ChartOfAccountsMapping' => ['ChartOfAccountsMapping'],
			'CloseChecklistInstance' => ['CloseChecklistInstance'],
			'CloseChecklistTemplate' => ['CloseChecklistTemplate'],
			'CloseMetrics' => ['CloseMetrics'],
			'ClosingAccount' => ['ClosingAccount'],
			'ClosingEntry' => ['ClosingEntry'],
			'ClosingEntryTemplate' => ['ClosingEntryTemplate'],
			'ContinuousCloseAlert' => ['ContinuousCloseAlert'],
			'EconomischeCategorie' => ['EconomischeCategorie'],
			'FiscalPeriod' => ['FiscalPeriod'],
			'FiscalYear' => ['FiscalYear'],
			'FluxAttribution' => ['FluxAttribution'],
			'FluxItem' => ['FluxItem'],
			'FluxRun' => ['FluxRun'],
			'GLLine' => ['GLLine'],
			'GLTransaction' => ['GLTransaction'],
			'GRDeelnemer' => ['GRDeelnemer'],
			'GRVerdeelsleutel' => ['GRVerdeelsleutel'],
			'Indicator' => ['Indicator'],
			'InterestAllocationRun' => ['InterestAllocationRun'],
			'Investering' => ['Investering'],
			'Iv3Export' => ['Iv3Export'],
			'JournalEntry' => ['JournalEntry'],
			'kernGegevensConfig' => ['kernGegevensConfig'],
			'Kostenpost' => ['Kostenpost'],
			'LedgerGroup' => ['LedgerGroup'],
			'MatchingRule' => ['MatchingRule'],
			'MaterialityPolicy' => ['MaterialityPolicy'],
			'MeerjarenBudget' => ['MeerjarenBudget'],
			'Meerjarenraming' => ['Meerjarenraming'],
			'PeriodStatus' => ['PeriodStatus'],
			'PostingRestriction' => ['PostingRestriction'],
			'Programma' => ['Programma'],
			'Programmabegroting' => ['Programmabegroting'],
			'ProvincialeFondsPosting' => ['ProvincialeFondsPosting'],
			'Reserve' => ['Reserve'],
			'ReserveMutation' => ['ReserveMutation'],
			'RetainedEarnings' => ['RetainedEarnings'],
			'Taakveld' => ['Taakveld'],
			'TrialBalance' => ['TrialBalance'],
			'TrialBalanceLine' => ['TrialBalanceLine'],
			'Voorziening' => ['Voorziening'],
			'WaterschapHeffingPosting' => ['WaterschapHeffingPosting'],
			'WipBalance' => ['WipBalance'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
