<?php

/**
 * Compliance rule-audit data-report generator
 *
 * Renders the compliance auditor's result natively as CSV. It resolves the existing
 * {@see \OCA\Shillinq\Service\RuleAuditService} from the container, runs its
 * read-only audit() over the live bookkeeping objects, and flattens the result into
 * a CSV: a corpus summary header block, one row per checked object type
 * (checked / compliant / with-violations / coverage), and the top violated rules.
 * Rendering is byte-native (fputcsv) — no spreadsheet library is involved.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\RuleAuditService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rule-audit CSV generator that reuses RuleAuditService.
 */
final class RuleAuditReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * Construct the rule-audit report generator.
	 *
	 * @param ContainerInterface $container DI container for lazy RuleAuditService resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'rule-audit';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['csv'];
	}//end supportedFormats()

	/**
	 * Run the rule audit and render the result as CSV.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId?, jurisdiction? }`.
	 * @param string $format Must be 'csv'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$report = $this->runAudit($context);

		$handle = fopen('php://temp', 'r+');

		// --- Summary block ---.
		fputcsv($handle, ['section', 'metric', 'value']);
		fputcsv($handle, ['summary', 'catalogueVersion', (string)($report['catalogueVersion'] ?? '')]);
		fputcsv($handle, ['summary', 'corpusTotal', (string)($report['corpusTotal'] ?? 0)]);
		fputcsv($handle, ['summary', 'machineCheckable', (string)($report['machineCheckable'] ?? 0)]);
		fputcsv($handle, ['summary', 'enforceableRules', (string)($report['enforceableRules'] ?? 0)]);
		fputcsv($handle, ['summary', 'coveragePct', (string)($report['coveragePct'] ?? 0)]);
		fputcsv($handle, ['summary', 'objectsChecked', (string)($report['objectsChecked'] ?? 0)]);
		fputcsv($handle, ['summary', 'objectsCompliant', (string)($report['objectsCompliant'] ?? 0)]);
		fputcsv($handle, ['summary', 'objectsWithViolations', (string)($report['objectsWithViolations'] ?? 0)]);

		$severity = ($report['violationsBySeverity'] ?? []);
		foreach (['mandatory', 'conditional', 'recommended'] as $level) {
			fputcsv($handle, ['severity', $level, (string)($severity[$level] ?? 0)]);
		}

		// --- Per object type ---.
		fputcsv($handle, []);
		fputcsv($handle, ['objectType', 'checked', 'compliant', 'withViolations', 'violations', 'compliancePct']);
		foreach (($report['types'] ?? []) as $type => $stat) {
			if (is_array($stat) === false) {
				continue;
			}

			$checked = (int)($stat['checked'] ?? 0);
			$compliant = (int)($stat['compliant'] ?? 0);
			$compliance = ($checked > 0) ? round((($compliant / $checked) * 100), 1) : 0.0;
			fputcsv(
				$handle,
				[
					(string)$type,
					(string)$checked,
					(string)$compliant,
					(string)($stat['withViolations'] ?? 0),
					(string)($stat['violations'] ?? 0),
					$this->money((float)$compliance),
				]
			);
		}//end foreach

		// --- Top violated rules ---.
		fputcsv($handle, []);
		fputcsv($handle, ['topViolatedRule', 'count']);
		foreach (($report['topViolatedRules'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			fputcsv($handle, [(string)($entry['ruleId'] ?? ''), (string)($entry['count'] ?? 0)]);
		}

		rewind($handle);
		$content = (string)stream_get_contents($handle);
		fclose($handle);

		return new GeneratedFile(
			fileName: $this->fileName('rule-audit', $context, 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: $content,
		);

	}//end generate()

	/**
	 * Resolve RuleAuditService from the container and run its audit.
	 *
	 * Degrades to an empty (well-formed) report when the service cannot be
	 * resolved or the audit fails, so the generator never fatals.
	 *
	 * @param array<string,mixed> $context Report context (passed to audit()).
	 *
	 * @return array<string,mixed>
	 */
	private function runAudit(array $context): array {
		try {
			$service = $this->container->get(RuleAuditService::class);
			return $service->audit($context);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleAuditReportGenerator: audit failed: ' . $e->getMessage());
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
		}

	}//end runAudit()
}//end class
