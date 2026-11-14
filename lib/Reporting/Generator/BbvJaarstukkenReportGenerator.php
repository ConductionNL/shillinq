<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * BBV jaarstukken (programmaverantwoording) report generator
 *
 * Produces the NL public-sector BBV jaarstukken / programmaverantwoording for
 * one fiscal year, rendered as editable DOCX/ODT or as a dompdf PDF from the
 * default in-code layout. The content is read from a real BbvStatement object in
 * the shillinq register: the document parts (art. 24), the programmaplan and its
 * programmes, the mandatory paragraphs (art. 9), the taakvelden overview (with
 * estimated revenue/expense per task field), the jaarrekening composition and
 * the capitalised fixed assets (arts. 59/62).
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
use OCA\Shillinq\Reporting\ReportTable;

/**
 * Renders BBV jaarstukken / programmaverantwoording from a BbvStatement object.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class BbvJaarstukkenReportGenerator extends AbstractDocumentReportGenerator {
	/**
	 * The catalogue report-type id this generator produces.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'bbv-jaarstukken';
	}//end reportType()

	/**
	 * Cover title for the document.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'BBV-jaarstukken';
	}//end documentTitle()

	/**
	 * Build the BBV jaarstukken body from the BbvStatement object.
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

		$this->addCover(
			$section,
			'BBV-jaarstukken',
			$this->documentTypeLabel($this->str($statement, 'documentType')) . ' — programmaverantwoording',
			$context
		);

		// --- Kerngegevens ---
		$this->addHeading($section, 'Kerngegevens');
		$this->addDetailsTable($section, $this->keyFacts(statement: $statement, currency: $currency));

		// --- Programmaplan (art. 8) ---
		$section->addTextBreak(1);
		$this->buildProgrammaplan($section, $statement, $currency);

		// --- Verplichte paragrafen (art. 9) ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Verplichte paragrafen (art. 9 BBV)');
		$this->buildParagraphs($section, $statement);

		// --- Taakvelden ---
		$section->addTextBreak(1);
		$this->buildTaskFields($section, $statement, $currency);

		// --- Jaarrekening (art. 24) ---
		$section->addTextBreak(1);
		$this->buildAnnualAccounts($section, $statement);

		// --- Vaste activa (arts. 59/62) ---
		$section->addTextBreak(1);
		$this->buildFixedAssets($section, $statement, $currency);

	}//end build()

	/**
	 * The kerngegevens rows of a statement.
	 *
	 * @param array<string, mixed> $statement The BbvStatement object.
	 * @param string               $currency  The presentation currency.
	 *
	 * @return array<string, string>
	 */
	private function keyFacts(array $statement, string $currency): array {
		return [
			'Documenttype' => $this->documentTypeLabel($this->str($statement, 'documentType')),
			'Soort lichaam' => $this->entityTypeLabel($this->str($statement, 'entityType')),
			'Jurisdictie' => $this->str($statement, 'jurisdiction'),
			'Boekjaar' => $this->str($statement, 'fiscalYear'),
			'Valuta' => $currency,
			'Onderdelen' => $this->joinList($statement['parts'] ?? []),
		];

	}//end keyFacts()

	/**
	 * Build the programmaplan section: the programmes plus the general cover
	 * funds, overhead, VPB charge and onvoorzien.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The BbvStatement object.
	 * @param string $currency The presentation currency.
	 *
	 * @return void
	 */
	private function buildProgrammaplan(ReportSection $section, array $statement, string $currency): void {
		$this->addHeading($section, 'Programmaplan (art. 8 BBV)');

		$plan = $statement['programmePlan'] ?? [];
		if (is_array($plan) === false) {
			$plan = [];
		}

		$this->addProgrammes(section: $section, programmes: ($plan['programmes'] ?? []));

		$section->addTextBreak(1);
		$this->addAmountTable($section, 'Algemene posten', 'Bedrag', $this->generalItems(plan: $plan), null, $currency);

	}//end buildProgrammaplan()

	/**
	 * The programmes table, or a note when the plan holds none.
	 *
	 * @param ReportSection $section    The block accumulator.
	 * @param mixed         $programmes The plan's programmes.
	 *
	 * @return void
	 */
	private function addProgrammes(ReportSection $section, mixed $programmes): void {
		if (is_array($programmes) === false || $programmes === []) {
			$this->addNote($section, 'Geen programma\'s opgenomen in het programmaplan.');
			return;
		}

		$table = $section->addTable('reportTable');
		$table->addRow();
		$table->addCell(4, ['bgColor' => self::ACCENT_COLOUR])
			->addText('Code', 'tableHeader');
		$table->addCell(12, ['bgColor' => self::ACCENT_COLOUR])
			->addText('Programma', 'tableHeader');

		foreach ($programmes as $programme) {
			if (is_array($programme) === true) {
				$table->addRow();
				$table->addCell(4)->addText($this->str($programme, 'code'), 'value');
				$table->addCell(12)->addText($this->str($programme, 'name'), 'value');
			}
		}

	}//end addProgrammes()

	/**
	 * The general items of the programmaplan: cover funds, overhead, VPB
	 * charge and onvoorzien.
	 *
	 * @param array<string, mixed> $plan The programme plan.
	 *
	 * @return list<array{label: string, amount: float}>
	 */
	private function generalItems(array $plan): array {
		return [
			['label' => 'Algemene dekkingsmiddelen', 'amount' => $this->num($plan, 'generalFundingSources')],
			['label' => 'Overhead', 'amount' => $this->num($plan, 'overhead')],
			['label' => 'Heffing vennootschapsbelasting (VPB)', 'amount' => $this->num($plan, 'vpbCharge')],
			['label' => 'Onvoorzien', 'amount' => $this->num($plan, 'unforeseen')],
		];

	}//end generalItems()

	/**
	 * List the mandatory BBV paragraphs (art. 9), flagging which of the seven
	 * required paragraphs are present.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The BbvStatement object.
	 *
	 * @return void
	 */
	private function buildParagraphs(ReportSection $section, array $statement): void {
		$required = [
			'lokaleHeffingen' => 'Lokale heffingen',
			'weerstandsvermogen' => 'Weerstandsvermogen en risicobeheersing',
			'onderhoudKapitaalgoederen' => 'Onderhoud kapitaalgoederen',
			'financiering' => 'Financing',
			'bedrijfsvoering' => 'Operations',
			'verbondenPartijen' => 'Verbonden partijen',
			'grondbeleid' => 'LandPolicy',
		];

		$present = [];
		foreach (($statement['paragraphs'] ?? []) as $paragraph) {
			$present[strtolower((string)$paragraph)] = true;
		}

		$rows = [];
		foreach ($required as $key => $label) {
			$isPresent = (isset($present[strtolower($key)]) === true || isset($present[strtolower($label)]) === true);
			$rows[$label] = $isPresent === true ? 'Aanwezig' : 'Ontbreekt';
		}

		$this->addDetailsTable($section, $rows);

	}//end buildParagraphs()

	/**
	 * Build the taakvelden overview: estimated revenue and expense per taakveld.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The BbvStatement object.
	 * @param string $currency The presentation currency.
	 *
	 * @return void
	 */
	private function buildTaskFields(ReportSection $section, array $statement, string $currency): void {
		$this->addHeading($section, 'Overzicht taakvelden');

		$taskFields = $statement['taskFields'] ?? [];
		if (is_array($taskFields) === false || $taskFields === []) {
			$this->addNote($section, 'Geen taakvelden opgenomen.');
			return;
		}

		$table = $this->taskFieldTable(section: $section);

		$totalRevenue = 0.0;
		$totalExpense = 0.0;
		foreach ($taskFields as $taskField) {
			if (is_array($taskField) === false) {
				continue;
			}

			$revenue = $this->num($taskField, 'estimatedRevenue');
			$expense = $this->num($taskField, 'estimatedExpense');
			$totalRevenue += $revenue;
			$totalExpense += $expense;

			$table->addRow();
			$table->addCell(4)->addText($this->str($taskField, 'code'), 'value');
			$table->addCell(4)->addText($this->str($taskField, 'name'), 'value');
			$table->addCell(4)->addText($this->money($revenue, $currency), 'amount', ['alignment' => 'end']);
			$table->addCell(4)->addText($this->money($expense, $currency), 'amount', ['alignment' => 'end']);
		}

		$this->addTaskFieldTotal(table: $table, totals: ['revenue' => $totalRevenue, 'expense' => $totalExpense, 'currency' => $currency]);

	}//end buildTaakvelden()

	/**
	 * A new taakvelden table with its header row.
	 *
	 * @param ReportSection $section The block accumulator.
	 *
	 * @return ReportTable
	 */
	private function taskFieldTable(ReportSection $section): ReportTable {
		$table = $section->addTable('reportTable');
		$table->addRow();
		foreach (['Code', 'Taakveld', 'Baten', 'Lasten'] as $head) {
			$table->addCell(4, ['bgColor' => self::ACCENT_COLOUR])
				->addText($head, 'tableHeader');
		}

		return $table;

	}//end taskFieldTable()

	/**
	 * The totals row of the taakvelden table.
	 *
	 * @param ReportTable          $table  The taakvelden table.
	 * @param array<string, mixed> $totals revenue, expense and currency.
	 *
	 * @return void
	 */
	private function addTaskFieldTotal(ReportTable $table, array $totals): void {
		$table->addRow();
		$table->addCell(8, ['bgColor' => 'EEF2FF', 'gridSpan' => 2])->addText('Totaal', 'amountBold');
		$table->addCell(4, ['bgColor' => 'EEF2FF'])->addText($this->money($totals['revenue'], $totals['currency']), 'amountBold', ['alignment' => 'end']);
		$table->addCell(4, ['bgColor' => 'EEF2FF'])->addText($this->money($totals['expense'], $totals['currency']), 'amountBold', ['alignment' => 'end']);

	}//end addTaskFieldTotal()

	/**
	 * Build the jaarrekening composition (art. 24): which components are present.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The BbvStatement object.
	 *
	 * @return void
	 */
	private function buildAnnualAccounts(ReportSection $section, array $statement): void {
		$this->addHeading($section, 'Jaarrekening (art. 24 BBV)');

		$annualAccounts = $statement['annualAccounts'] ?? [];
		if (is_array($annualAccounts) === false) {
			$annualAccounts = [];
		}

		$this->addDetailsTable(
			$section,
			[
				'Overzicht van baten en lasten' => $this->yesNo($annualAccounts['overviewRevenueExpenses'] ?? null),
				'Balans' => $this->yesNo($annualAccounts['balance'] ?? null),
				'Rechtmatigheidsverantwoording' => $this->yesNo($annualAccounts['lawfulnessAccountability'] ?? null),
				'Accountantsverklaring' => $this->yesNo($annualAccounts['auditorsStatement'] ?? null),
			]
		);

	}//end buildJaarrekening()

	/**
	 * Build the fixed-assets overview (arts. 59/62): capitalisation + valuation.
	 *
	 * @param ReportSection $section The block accumulator.
	 * @param array<string, mixed> $statement The BbvStatement object.
	 * @param string $currency The presentation currency.
	 *
	 * @return void
	 */
	private function buildFixedAssets(ReportSection $section, array $statement, string $currency): void {
		$this->addHeading($section, 'Vaste activa (art. 59/62 BBV)');

		$assets = $statement['fixedAssets'] ?? [];
		if (is_array($assets) === false || $assets === []) {
			$this->addNote($section, 'Geen vaste activa opgenomen.');
			return;
		}

		$lines = [];
		$total = 0.0;
		foreach ($assets as $asset) {
			if (is_array($asset) === false) {
				continue;
			}

			$line = $this->fixedAssetLine(asset: $asset);
			$total += $line['amount'];
			$lines[] = $line;
		}

		$this->addAmountTable(
			$section,
			'Vast actief',
			'Verkrijgings-/vervaardigingsprijs',
			$lines,
			['label' => 'Totaal vaste activa', 'amount' => $total],
			$currency
		);

	}//end buildFixedAssets()

	/**
	 * The amount-table line of one fixed asset: its name, kind and
	 * capitalisation, and its acquisition cost.
	 *
	 * @param array<string, mixed> $asset The fixed asset.
	 *
	 * @return array{label: string, amount: float}
	 */
	private function fixedAssetLine(array $asset): array {
		$kind = $this->str($asset, 'kind') === 'intangible' ? 'immaterieel' : 'materieel';
		$capit = (($asset['capitalised'] ?? false) === true ? 'geactiveerd' : 'niet geactiveerd');
		return [
			'label' => $this->str($asset, 'name') . ' (' . $kind . ', ' . $capit . ')',
			'amount' => $this->num($asset, 'acquisitionCost'),
		];

	}//end fixedAssetLine()

	/**
	 * Resolve the BbvStatement object for the report. A statementId / bbvStatementId
	 * in the context is honoured first; otherwise the statement whose fiscalYear
	 * matches the period (and, when given, administration) is picked, falling
	 * back to the first available. Returns an empty array when none match.
	 *
	 * @param array<string, mixed> $context `{ period, administrationId, statementId? }`.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveStatement(array $context): array {
		$explicit = $this->explicitStatement($context);
		if ($explicit !== null) {
			return $explicit;
		}

		$candidates = $this->statementCandidates($this->str($context, 'administrationId'));
		if ($candidates === []) {
			return [];
		}

		return $this->statementForPeriod($candidates, $this->str($context, 'period')) ?? $this->preferredStatement($candidates);
	}//end resolveStatement()

	/**
	 * The statement the context names by id (statementId, bbvStatementId or
	 * objectId), or null when it names none or it is not found.
	 *
	 * @param array<string, mixed> $context The report context.
	 *
	 * @return array<string, mixed>|null
	 */
	private function explicitStatement(array $context): array|null {
		$id = $this->str($context, 'statementId', 'bbvStatementId', 'objectId');
		if ($id === '') {
			return null;
		}

		return $this->loadObject('BbvStatement', $id);
	}//end explicitStatement()

	/**
	 * The administration's statements, or every statement when it has none
	 * (or no administration is given).
	 *
	 * @param string $administrationId The administration, or ''.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function statementCandidates(string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$candidates = $this->loadObjects('BbvStatement', $filters);
		if ($candidates === [] && $filters !== []) {
			$candidates = $this->loadObjects('BbvStatement');
		}

		return $candidates;
	}//end statementCandidates()

	/**
	 * The first statement whose fiscalYear is the period's year, or null.
	 *
	 * The BBV fiscalYear is a number; the year is read from every digit of
	 * the period.
	 *
	 * @param array<int, array<string, mixed>> $candidates The candidate statements.
	 * @param string                           $period     The report period, or ''.
	 *
	 * @return array<string, mixed>|null
	 */
	private function statementForPeriod(array $candidates, string $period): array|null {
		$year = preg_replace('/[^0-9]/', '', $period);
		if ($year === '') {
			return null;
		}

		foreach ($candidates as $candidate) {
			if ((string)(int)$this->num($candidate, 'fiscalYear') === (string)(int)$year) {
				return $candidate;
			}
		}

		return null;
	}//end statementForPeriod()

	/**
	 * A jaarstukken document when present (preferred over a begroting),
	 * else the first statement.
	 *
	 * @param array<int, array<string, mixed>> $candidates The candidate statements (non-empty).
	 *
	 * @return array<string, mixed>
	 */
	private function preferredStatement(array $candidates): array {
		foreach ($candidates as $candidate) {
			if ($this->str($candidate, 'documentType') === 'jaarstukken') {
				return $candidate;
			}
		}

		return $candidates[0];
	}//end preferredStatement()

	/**
	 * Map a documentType enum to a Dutch label.
	 *
	 * @param string $type The documentType value.
	 *
	 * @return string
	 */
	private function documentTypeLabel(string $type): string {
		return match ($type) {
			'begroting' => 'Begroting',
			'jaarstukken' => 'Jaarstukken',
			default => ($type !== '' ? $type : 'Jaarstukken'),
		};

	}//end documentTypeLabel()

	/**
	 * Map an entityType enum to a Dutch label.
	 *
	 * @param string $type The entityType value.
	 *
	 * @return string
	 */
	private function entityTypeLabel(string $type): string {
		return match ($type) {
			'municipality' => 'Gemeente',
			'province' => 'Provincie',
			'waterAuthority' => 'Waterschap',
			default => $type,
		};

	}//end entityTypeLabel()

	/**
	 * Join a list of scalar values into a comma-separated string.
	 *
	 * @param mixed $list The list (array) value.
	 *
	 * @return string
	 */
	private function joinList(mixed $list): string {
		if (is_array($list) === false || $list === []) {
			return '';
		}

		return implode(', ', array_map('strval', $list));
	}//end joinList()

	/**
	 * Render a boolean as Ja / Nee (empty string when null).
	 *
	 * @param mixed $value The boolean-ish value.
	 *
	 * @return string
	 */
	private function yesNo(mixed $value): string {
		if ($value === null) {
			return '';
		}

		return ((bool)$value === true) ? 'Ja' : 'Nee';
	}//end yesNo()
}//end class
