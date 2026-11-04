<?php

/**
 * Management letter (bevindingenrapport) report generator
 *
 * Produces a management letter / findings report, rendered as editable DOCX/ODT
 * or as a dompdf PDF from the default in-code layout. The findings come from the
 * real compliance posture: RuleAuditService runs the machine-checkable rule
 * corpus over the live bookkeeping objects and returns coverage, the objects
 * checked, the violations grouped by severity and the top violated rules. The
 * letter turns that audit into a structured set of findings with a management
 * summary, a severity breakdown and per-rule detail.
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
use OCA\Shillinq\Service\RuleAuditService;
use Throwable;

/**
 * Renders a management letter from the RuleAuditService compliance findings.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class ManagementLetterReportGenerator extends AbstractDocumentReportGenerator {
	/**
	 * The catalogue report-type id this generator produces.
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'management-letter';
	}//end reportType()

	/**
	 * Cover title for the document.
	 *
	 * @return string
	 */
	protected function documentTitle(): string {
		return 'Management letter';
	}//end documentTitle()

	/**
	 * Build the management-letter body: summary, severity breakdown, findings.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
	 *
	 * @return void
	 */
	protected function build(ReportSection $section, array $context): void {
		$audit = $this->runAudit($context);

		$this->addCover($section, 'Management letter', 'Bevindingenrapport — compliance & interne beheersing', $context);
		$this->writeSalutation(section: $section);
		$this->writeSummary(section: $section, audit: $audit);
		$this->writeSeverityBreakdown(section: $section, audit: $audit);
		$this->writeTypeBreakdown(section: $section, audit: $audit);
		$this->writeTopViolatedRules(section: $section, audit: $audit);
		$this->writeRecommendation(section: $section, audit: $audit);

		$this->addNote(
			$section,
			'Deze management letter is automatisch gegenereerd uit de regelmotor (RuleAuditService). '
			. 'De dekkingsgraad geeft aan welk deel van het machinaal controleerbare corpus vandaag daadwerkelijk wordt afgedwongen.'
		);

	}//end build()

	/**
	 * Aanhef: the salutation and introduction.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 *
	 * @return void
	 */
	private function writeSalutation(ReportSection $section): void {
		$this->addParagraph(
			$section,
			'Geachte directie,'
		);
		$this->addParagraph(
			$section,
			'In het kader van de doorlopende controle op de financiële administratie hebben wij de boekhouding '
			. 'getoetst aan het machinaal controleerbare normenkader. Onderstaand treft u onze bevindingen aan, '
			. 'geordend naar ernst, met een samenvatting van de mate waarin de administratie aan de normen voldoet.'
		);
	}//end writeSalutation()

	/**
	 * Samenvatting: the management summary table.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return void
	 */
	private function writeSummary(ReportSection $section, array $audit): void {
		$section->addTextBreak(1);
		$this->addHeading($section, 'Samenvatting');
		$this->addDetailsTable($section, $this->summaryRows(audit: $audit));
	}//end writeSummary()

	/**
	 * The rows of the management summary table.
	 *
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return array<string, string>
	 */
	private function summaryRows(array $audit): array {
		return [
			'Normenkader (versie)' => (string)($audit['catalogueVersion'] ?? ''),
			'Regels in corpus' => (string)$this->auditInt(audit: $audit, key: 'corpusTotal'),
			'Machinaal controleerbaar' => (string)$this->auditInt(audit: $audit, key: 'machineCheckable'),
			'Afdwingbare regels' => (string)$this->auditInt(audit: $audit, key: 'enforceableRules'),
			'Dekkingsgraad' => $this->pct($audit['coveragePct'] ?? 0) . ' %',
			'Objecten gecontroleerd' => (string)$this->auditInt(audit: $audit, key: 'objectsChecked'),
			'Objecten zonder bevindingen' => (string)$this->auditInt(audit: $audit, key: 'objectsCompliant'),
			'Objecten met bevindingen' => (string)$this->auditInt(audit: $audit, key: 'objectsWithViolations'),
			'Compliancegraad' => $this->pct($this->compliancePct(audit: $audit)) . ' %',
		];
	}//end summaryRows()

	/**
	 * The share of checked objects without findings, in percent with one decimal.
	 *
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return float
	 */
	private function compliancePct(array $audit): float {
		$objectsChecked = $this->auditInt(audit: $audit, key: 'objectsChecked');
		if ($objectsChecked > 0) {
			return round(($this->auditInt(audit: $audit, key: 'objectsCompliant') / $objectsChecked) * 100, 1);
		}

		return 0.0;
	}//end compliancePct()

	/**
	 * Bevindingen naar ernst: the severity breakdown.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return void
	 */
	private function writeSeverityBreakdown(ReportSection $section, array $audit): void {
		$section->addTextBreak(1);
		$this->addHeading($section, 'Bevindingen naar ernst');
		$severity = $audit['violationsBySeverity'] ?? [];
		if (is_array($severity) === false) {
			$severity = [];
		}

		$lines = [
			['label' => 'Verplicht (mandatory)', 'amount' => $this->auditInt(audit: $severity, key: 'mandatory')],
			['label' => 'Voorwaardelijk (conditional)', 'amount' => $this->auditInt(audit: $severity, key: 'conditional')],
			['label' => 'Aanbevolen (recommended)', 'amount' => $this->auditInt(audit: $severity, key: 'recommended')],
		];
		$total = ['label' => 'Totaal bevindingen', 'amount' => array_sum(array_column($lines, 'amount'))];
		$this->addAmountTable($section, 'Ernst', 'Aantal', $lines, $total, '');
	}//end writeSeverityBreakdown()

	/**
	 * Bevindingen per object-type, when the audit lists types.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return void
	 */
	private function writeTypeBreakdown(ReportSection $section, array $audit): void {
		$types = $audit['types'] ?? [];
		if (is_array($types) === false || $types === []) {
			return;
		}

		$section->addTextBreak(1);
		$this->addHeading($section, 'Bevindingen per object-type');
		$this->addAmountTable($section, 'Object-type', 'Bevindingen', $this->typeLines(types: $types), null, '');
	}//end writeTypeBreakdown()

	/**
	 * One table line per object type: its findings, labelled with the checked counts.
	 *
	 * @param array<mixed> $types The audit's per-type statistics.
	 *
	 * @return array<int, array{label: string, amount: int}>
	 */
	private function typeLines(array $types): array {
		$typeLines = [];
		foreach (array_filter($types, 'is_array') as $typeName => $stat) {
			$typeLines[] = [
				'label' => (string)$typeName . ' (gecontroleerd: ' . $this->auditInt(audit: $stat, key: 'checked')
					. ', met bevindingen: ' . $this->auditInt(audit: $stat, key: 'withViolations') . ')',
				'amount' => $this->auditInt(audit: $stat, key: 'violations'),
			];
		}

		return $typeLines;
	}//end typeLines()

	/**
	 * Belangrijkste bevindingen: the most violated rules, when listed.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return void
	 */
	private function writeTopViolatedRules(ReportSection $section, array $audit): void {
		$top = $audit['topViolatedRules'] ?? [];
		if (is_array($top) === false || $top === []) {
			return;
		}

		$section->addTextBreak(1);
		$this->addHeading($section, 'Belangrijkste bevindingen');
		$this->addParagraph($section, 'De onderstaande regels zijn het vaakst overtreden en verdienen prioriteit bij het herstel.');

		$topLines = [];
		foreach (array_filter($top, 'is_array') as $entry) {
			$topLines[] = ['label' => (string)($entry['ruleId'] ?? ''), 'amount' => $this->auditInt(audit: $entry, key: 'count')];
		}

		$this->addAmountTable($section, 'Regel', 'Aantal', $topLines, null, '');
	}//end writeTopViolatedRules()

	/**
	 * Aanbeveling: the closing recommendation.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $audit The RuleAuditService report.
	 *
	 * @return void
	 */
	private function writeRecommendation(ReportSection $section, array $audit): void {
		$section->addTextBreak(1);
		$this->addHeading($section, 'Aanbeveling');
		$text = 'Wij adviseren de geconstateerde bevindingen op te lossen, te beginnen bij de verplichte (mandatory) '
			. 'bevindingen, en de interne beheersing zodanig in te richten dat herhaling wordt voorkomen.';
		if ($this->auditInt(audit: $audit, key: 'objectsWithViolations') === 0 && $this->auditInt(audit: $audit, key: 'objectsChecked') > 0) {
			$text = 'Op basis van het machinaal controleerbare normenkader zijn geen bevindingen geconstateerd. '
				. 'De administratie voldoet aan de getoetste normen.';
		}

		$this->addParagraph($section, $text);
	}//end writeRecommendation()

	/**
	 * An integer figure of an audit report (0 when absent).
	 *
	 * @param array<string, mixed> $audit The audit report or one of its sub-arrays.
	 * @param string $key The figure's key.
	 *
	 * @return int
	 */
	private function auditInt(array $audit, string $key): int {
		return (int)($audit[$key] ?? 0);
	}//end auditInt()

	/**
	 * Run the compliance audit, resolving RuleAuditService through the container
	 * the base class is handed. Fail-soft: an empty audit shape is returned when
	 * the service is unavailable, so the letter still renders with zeroed
	 * figures and a note.
	 *
	 * @param array<string, mixed> $context `{ administrationId, period, ... }`.
	 *
	 * @return array<string, mixed> The RuleAuditService report.
	 */
	private function runAudit(array $context): array {
		try {
			$service = $this->container->get(RuleAuditService::class);
			if ($service instanceof RuleAuditService) {
				$auditContext = [];
				$administrationId = $this->str($context, 'administrationId');
				if ($administrationId !== '') {
					$auditContext['administrationId'] = $administrationId;
				}

				return $service->audit($auditContext);
			}
		} catch (Throwable $e) {
			// Fall through to the empty shape below.
		}

		return [
			'catalogueVersion' => '',
			'corpusTotal' => 0,
			'machineCheckable' => 0,
			'enforceableRules' => 0,
			'coveragePct' => 0.0,
			'types' => [],
			'objectsChecked' => 0,
			'objectsCompliant' => 0,
			'objectsWithViolations' => 0,
			'violationsBySeverity' => ['mandatory' => 0, 'conditional' => 0, 'recommended' => 0],
			'topViolatedRules' => [],
		];

	}//end runAudit()

	/**
	 * Format a percentage value (already 0..100) with one decimal.
	 *
	 * @param float|int|string|null $value The percentage.
	 *
	 * @return string
	 */
	private function pct(float|int|string|null $value): string {
		$number = is_numeric($value) === true ? (float)$value : 0.0;
		return number_format($number, 1, ',', '.');
	}//end pct()
}//end class
