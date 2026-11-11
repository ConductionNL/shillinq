<?php

/**
 * Annual accounts (jaarrekening) report generator
 *
 * Produces a Titel 9 BW2 jaarrekening — balans, winst-en-verliesrekening and
 * toelichting — for one reporting period, rendered as editable DOCX/ODT or as a
 * dompdf PDF from the default in-code layout. The figures are read from a real
 * FinancialStatement object in the shillinq register (its balanceSheet,
 * profitAndLoss, size classification and audit/filing metadata), so the document
 * reflects the live statutory accounts rather than a stub.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\ReportSection;

/**
 * Renders the statutory annual accounts from a FinancialStatement object.
 */
final class AnnualAccountsReportGenerator extends AbstractDocumentReportGenerator {
	/**
	 * The catalogue report-type id this generator produces.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'annual-accounts';
	}//end reportType()

	/**
	 * Cover title for the document.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Jaarrekening';
	}//end documentTitle()

	/**
	 * Build the jaarrekening body: cover, kerngegevens, balans, W&V, toelichting.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
	 *
	 * @return void
	 */
	protected function build(ReportSection $section, array $context): void {
		$statement = $this->resolveStatement($context);
		$currency = $this->str($statement, 'currency');
		if ($currency === '') {
			$currency = 'EUR';
		}

		$this->addCover($section, 'Jaarrekening', 'Titel 9 BW2 — jaarrekening', $context);

		// --- Kerngegevens (key facts) ---
		$this->addHeading($section, 'Kerngegevens');
		$this->addDetailsTable(
			$section,
			[
				'Soort jaarrekening' => $this->statementTypeLabel($this->str($statement, 'statementType')),
				'Jurisdictie' => $this->str($statement, 'jurisdiction'),
				'Stelsel' => $this->str($statement, 'framework'),
				'Boekjaar' => $this->periodLabel($statement),
				'Taal' => $this->str($statement, 'language'),
				'Valuta' => $currency,
				'Grootteklasse' => $this->str($statement, 'sizeClass'),
				'Balanstotaal' => $this->money($this->num($statement, 'balanceTotal'), $currency),
				'Netto-omzet' => $this->money($this->num($statement, 'turnover'), $currency),
				'Gemiddeld personeel' => $this->employeesLabel($statement),
			]
		);

		// --- Balans ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Balans per ' . $this->str($statement, 'reportingPeriodEnd'));
		$this->buildBalanceSheet($section, $statement, $currency);

		// --- Winst- en verliesrekening ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Winst- en verliesrekening');
		$this->buildProfitAndLoss($section, $statement, $currency);

		// --- Toelichting ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Toelichting');
		$this->buildNotes($section, $statement);

	}//end build()

	/**
	 * Lay out the balance sheet: assets on one side, equity/provisions/
	 * liabilities on the other, from FinancialStatement.balanceSheet.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The statement object.
	 * @param string $currency The presentation currency.
	 *
	 * @return void
	 */
	private function buildBalanceSheet(ReportSection $section, array $statement, string $currency): void {
		$balance = $statement['balanceSheet'] ?? [];
		if (is_array($balance) === false) {
			$balance = [];
		}

		$fixedAssets = $this->num($balance, 'fixedAssets');
		$currentAssets = $this->num($balance, 'currentAssets');
		$equity = $this->num($balance, 'equity');
		$provisions = $this->num($balance, 'provisions');
		$liabilities = $this->num($balance, 'liabilities');

		$totalAssets = ($fixedAssets + $currentAssets);
		$totalCredit = ($equity + $provisions + $liabilities);

		$this->addAmountTable(
			$section,
			'Activa',
			'Bedrag',
			[
				['label' => 'Vaste activa', 'amount' => $fixedAssets],
				['label' => 'Vlottende activa', 'amount' => $currentAssets],
			],
			['label' => 'Totaal activa', 'amount' => $totalAssets],
			$currency
		);

		$section->addTextBreak(1);

		$this->addAmountTable(
			$section,
			'Passiva',
			'Bedrag',
			[
				['label' => 'Eigen vermogen', 'amount' => $equity],
				['label' => 'Voorzieningen', 'amount' => $provisions],
				['label' => 'Schulden', 'amount' => $liabilities],
			],
			['label' => 'Totaal passiva', 'amount' => $totalCredit],
			$currency
		);

		if (abs($totalAssets - $totalCredit) > 0.005) {
			$section->addTextBreak(1);
			$this->addNote(
				$section,
				'Let op: activa (' . $this->money($totalAssets, $currency) . ') en passiva ('
				. $this->money($totalCredit, $currency) . ') sluiten niet op elkaar aan.'
			);
		}

	}//end buildBalanceSheet()

	/**
	 * Lay out the profit-and-loss account from FinancialStatement.profitAndLoss.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The statement object.
	 * @param string $currency The presentation currency.
	 *
	 * @return void
	 */
	private function buildProfitAndLoss(ReportSection $section, array $statement, string $currency): void {
		$pnl = $statement['profitAndLoss'] ?? [];
		if (is_array($pnl) === false) {
			$pnl = [];
		}

		$turnover = $this->num($pnl, 'netTurnover');
		$expenses = $this->num($pnl, 'operatingExpenses');
		$financial = $this->num($pnl, 'financialResult');
		$result = $this->num($pnl, 'result');
		if ($result === 0.0) {
			$result = ($turnover - $expenses + $financial);
		}

		$this->addAmountTable(
			$section,
			'Resultatenrekening',
			'Bedrag',
			[
				['label' => 'Netto-omzet', 'amount' => $turnover],
				['label' => 'Bedrijfslasten', 'amount' => (-1 * $expenses)],
				['label' => 'Financiële baten en lasten', 'amount' => $financial],
			],
			['label' => 'Resultaat boekjaar', 'amount' => $result],
			$currency
		);

		$model = $this->str($statement, 'plPresentationModel');
		if ($model !== '') {
			$section->addTextBreak(1);
			$this->addNote(
				$section,
				'Presentatiemodel: ' . ($model === 'functional' ? 'functioneel (cost-of-sales)' : 'categoriaal (type-of-expense)') . '.'
			);
		}

	}//end buildProfitAndLoss()

	/**
	 * Build the toelichting (notes): general principles, audit and filing.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return void
	 */
	private function buildNotes(ReportSection $section, array $statement): void {
		$this->addParagraph($section, $this->notesIntro($statement));
		foreach ($this->optionalNotes($statement) as $paragraph) {
			$this->addParagraph($section, $paragraph);
		}

		$section->addTextBreak(1);
		$this->addHeading($section, 'Controle en deponering');
		$this->addDetailsTable($section, $this->auditDetails($statement));

	}//end buildNotes()

	/**
	 * The opening sentence: the reporting basis (Titel 9 BW2 for a Dutch or
	 * unspecified jurisdiction, else the framework) and the size regime.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string
	 */
	private function notesIntro(array $statement): string {
		$jurisdiction = $this->str($statement, 'jurisdiction');
		$intro = 'Deze jaarrekening is opgesteld op grond van Titel 9 Boek 2 BW';
		if ($jurisdiction !== '' && strtoupper($jurisdiction) !== 'NL') {
			$intro = 'Deze jaarrekening is opgesteld op grond van het toepasselijke verslaggevingsstelsel ('
				. $this->str($statement, 'framework') . ')';
		}

		$sizeClass = $this->str($statement, 'sizeClass');
		if ($sizeClass !== '') {
			$intro .= ', met toepassing van het regime voor de grootteklasse "' . $sizeClass . '"';
		}

		return $intro . '.';

	}//end notesIntro()

	/**
	 * The notes the statement calls for, in order: comparatives, netting,
	 * components present.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return list<string>
	 */
	private function optionalNotes(array $statement): array {
		$notes = [
			$this->comparativesNote($statement),
			$this->nettingNote($statement),
			$this->componentsNote($statement),
		];

		return array_values(array_filter($notes, static fn (?string $note): bool => $note !== null));

	}//end optionalNotes()

	/**
	 * Whether comparative figures are present or still missing, or null when
	 * the statement does not say.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string|null
	 */
	private function comparativesNote(array $statement): string|null {
		$comparatives = ($statement['priorYearComparatives'] ?? null);
		if ($comparatives === null) {
			return null;
		}

		if ((bool)$comparatives === true) {
			return 'De posten zijn voorzien van vergelijkende cijfers over het voorgaande boekjaar (art. 2:363 BW).';
		}

		return 'Vergelijkende cijfers over het voorgaande boekjaar ontbreken; dit dient nog te worden aangevuld (art. 2:363 BW).';

	}//end comparativesNote()

	/**
	 * The gross-principle warning when items were netted, else null.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string|null
	 */
	private function nettingNote(array $statement): string|null {
		$netted = ($statement['itemsNetted'] ?? null);
		if ($netted === null || (bool)$netted === false) {
			return null;
		}

		return 'Er is gebruikgemaakt van saldering van posten. Het bruto-principe (art. 2:363 BW) verbiedt saldering '
			. 'tenzij dit uitdrukkelijk is toegestaan.';

	}//end nettingNote()

	/**
	 * The components present, or null when the statement lists none.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string|null
	 */
	private function componentsNote(array $statement): string|null {
		$components = ($statement['components'] ?? []);
		if (is_array($components) === false || $components === []) {
			return null;
		}

		return 'Aanwezige onderdelen: ' . implode(', ', array_map('strval', $components)) . '.';

	}//end componentsNote()

	/**
	 * The audit and filing details.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return array<string, string>
	 */
	private function auditDetails(array $statement): array {
		$audited = 'Nee';
		if (($statement['audited'] ?? false) === true) {
			$audited = 'Ja';
		}

		return [
			'Gecontroleerd' => $audited,
			'Accountantskwalificatie' => $this->str($statement, 'auditorType'),
			'Opgemaakt op' => $this->str($statement, 'preparedDate'),
			'Vastgesteld op' => $this->str($statement, 'adoptionDate'),
			'Gedeponeerd op' => $this->str($statement, 'filedDate'),
		];

	}//end auditDetails()

	/**
	 * Resolve the FinancialStatement object for the report. When the context
	 * carries a statementId (or financialStatementId) that object is loaded;
	 * otherwise the best-matching statement for the period/administration is
	 * picked from the register. Returns an empty array when nothing matches —
	 * the document then renders with zeroed totals and a "no data" note.
	 *
	 * @param array<string, mixed> $context `{ period, administrationId, statementId? }`.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveStatement(array $context): array {
		$id = $this->str($context, 'statementId', 'financialStatementId', 'objectId');
		if ($id !== '') {
			$object = $this->loadObject('FinancialStatement', $id);
			if ($object !== null) {
				return $object;
			}
		}

		$candidates = $this->loadObjects('FinancialStatement');
		if ($candidates === []) {
			return [];
		}

		$period = $this->str($context, 'period');
		if ($period !== '') {
			foreach ($candidates as $candidate) {
				$start = $this->str($candidate, 'reportingPeriodStart');
				$end = $this->str($candidate, 'reportingPeriodEnd');
				if (str_contains($start, $period) === true || str_contains($end, $period) === true) {
					return $candidate;
				}
			}
		}

		return $candidates[0];
	}//end resolveStatement()

	/**
	 * The reporting-period label (start – end) for the cover/details.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string
	 */
	private function periodLabel(array $statement): string {
		$start = $this->str($statement, 'reportingPeriodStart');
		$end = $this->str($statement, 'reportingPeriodEnd');
		if ($start !== '' && $end !== '') {
			return $start . ' t/m ' . $end;
		}

		return $end !== '' ? $end : $start;
	}//end periodLabel()

	/**
	 * Human label for the average-employees figure.
	 *
	 * @param array<string, mixed> $statement The statement object.
	 *
	 * @return string
	 */
	private function employeesLabel(array $statement): string {
		if (array_key_exists('employees', $statement) === false || is_numeric($statement['employees']) === false) {
			return '';
		}

		return (string)((int)round((float)$statement['employees']));
	}//end employeesLabel()

	/**
	 * Map a statementType enum to a Dutch label.
	 *
	 * @param string $type The statementType value.
	 *
	 * @return string
	 */
	private function statementTypeLabel(string $type): string {
		return match ($type) {
			'abridged' => 'Verkorte jaarrekening',
			'consolidated' => 'Geconsolideerde jaarrekening',
			'interim' => 'Tussentijdse cijfers',
			'annual' => 'Volledige jaarrekening',
			default => $type,
		};

	}//end statementTypeLabel()
}//end class
