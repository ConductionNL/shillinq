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

		// --- Aanhef (salutation / introduction) ---
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

		// --- Samenvatting (management summary) ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Samenvatting');
		$objectsChecked = (int)($audit['objectsChecked'] ?? 0);
		$objectsCompliant = (int)($audit['objectsCompliant'] ?? 0);
		$withViolations = (int)($audit['objectsWithViolations'] ?? 0);
		$compliancePct = $objectsChecked > 0 ? round(($objectsCompliant / $objectsChecked) * 100, 1) : 0.0;

		$this->addDetailsTable(
			$section,
			[
				'Normenkader (versie)' => (string)($audit['catalogueVersion'] ?? ''),
				'Regels in corpus' => (string)((int)($audit['corpusTotal'] ?? 0)),
				'Machinaal controleerbaar' => (string)((int)($audit['machineCheckable'] ?? 0)),
				'Afdwingbare regels' => (string)((int)($audit['enforceableRules'] ?? 0)),
				'Dekkingsgraad' => $this->pct($audit['coveragePct'] ?? 0) . ' %',
				'Objecten gecontroleerd' => (string)$objectsChecked,
				'Objecten zonder bevindingen' => (string)$objectsCompliant,
				'Objecten met bevindingen' => (string)$withViolations,
				'Compliancegraad' => $this->pct($compliancePct) . ' %',
			]
		);

		// --- Bevindingen naar ernst (severity breakdown) ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Bevindingen naar ernst');
		$severity = $audit['violationsBySeverity'] ?? [];
		if (is_array($severity) === false) {
			$severity = [];
		}

		$this->addAmountTable(
			$section,
			'Ernst',
			'Aantal',
			[
				['label' => 'Verplicht (mandatory)', 'amount' => (int)($severity['mandatory'] ?? 0)],
				['label' => 'Voorwaardelijk (conditional)', 'amount' => (int)($severity['conditional'] ?? 0)],
				['label' => 'Aanbevolen (recommended)', 'amount' => (int)($severity['recommended'] ?? 0)],
			],
			[
				'label' => 'Totaal bevindingen',
				'amount' => ((int)($severity['mandatory'] ?? 0) + (int)($severity['conditional'] ?? 0) + (int)($severity['recommended'] ?? 0)),
			],
			''
		);

		// --- Bevindingen per object-type ---
		$types = $audit['types'] ?? [];
		if (is_array($types) === true && $types !== []) {
			$section->addTextBreak(1);
			$this->addHeading($section, 'Bevindingen per object-type');
			$typeLines = [];
			foreach ($types as $typeName => $stat) {
				if (is_array($stat) === false) {
					continue;
				}

				$typeLines[] = [
					'label' => (string)$typeName . ' (gecontroleerd: ' . (int)($stat['checked'] ?? 0) . ', met bevindingen: ' . (int)($stat['withViolations'] ?? 0) . ')',
					'amount' => (int)($stat['violations'] ?? 0),
				];
			}

			$this->addAmountTable($section, 'Object-type', 'Bevindingen', $typeLines, null, '');
		}

		// --- Meest geschonden regels (top violated rules) ---
		$top = $audit['topViolatedRules'] ?? [];
		if (is_array($top) === true && $top !== []) {
			$section->addTextBreak(1);
			$this->addHeading($section, 'Belangrijkste bevindingen');
			$this->addParagraph(
				$section,
				'De onderstaande regels zijn het vaakst overtreden en verdienen prioriteit bij het herstel.'
			);

			$topLines = [];
			foreach ($top as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$topLines[] = [
					'label' => (string)($entry['ruleId'] ?? ''),
					'amount' => (int)($entry['count'] ?? 0),
				];
			}

			$this->addAmountTable($section, 'Regel', 'Aantal', $topLines, null, '');
		}//end if

		// --- Slot (closing) ---
		$section->addTextBreak(1);
		$this->addHeading($section, 'Aanbeveling');
		if ($withViolations === 0 && $objectsChecked > 0) {
			$this->addParagraph(
				$section,
				'Op basis van het machinaal controleerbare normenkader zijn geen bevindingen geconstateerd. '
				. 'De administratie voldoet aan de getoetste normen.'
			);
		} else {
			$this->addParagraph(
				$section,
				'Wij adviseren de geconstateerde bevindingen op te lossen, te beginnen bij de verplichte (mandatory) '
				. 'bevindingen, en de interne beheersing zodanig in te richten dat herhaling wordt voorkomen.'
			);
		}

		$this->addNote(
			$section,
			'Deze management letter is automatisch gegenereerd uit de regelmotor (RuleAuditService). '
			. 'De dekkingsgraad geeft aan welk deel van het machinaal controleerbare corpus vandaag daadwerkelijk wordt afgedwongen.'
		);

	}//end build()

	/**
	 * Run the compliance audit, resolving RuleAuditService from the server
	 * container (generators take no constructor args). Fail-soft: an empty audit
	 * shape is returned when the service is unavailable, so the letter still
	 * renders with zeroed figures and a note.
	 *
	 * @param array<string, mixed> $context `{ administrationId, period, ... }`.
	 *
	 * @return array<string, mixed> The RuleAuditService report.
	 */
	private function runAudit(array $context): array {
		try {
			$service = \OCP\Server::get(RuleAuditService::class);
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
