<?php

/**
 * Subject costs per type report generator
 *
 * "Zaakkosten per zaaktype": the cost of the hours booked on the objects of
 * each configured cost source, grouped per object type for a month, quarter
 * or year. It reads the same rows as the Iv3 report's case cost section, so
 * over a period the two totals are equal.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;

/**
 * Renders the per-type case cost grouping as CSV.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */
final class SubjectCostsPerTypeReportGenerator implements ReportGeneratorInterface {

	/**
	 * Constructor.
	 *
	 * @param SubjectCostReport $subjectCosts The case cost sections.
	 */
	public function __construct(
		private readonly SubjectCostReport $subjectCosts,
	) {
	}//end __construct()

	/**
	 * The catalogue id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function reportType(): string {
		return 'subject-costs-per-type';
	}//end reportType()

	/**
	 * The formats.
	 *
	 * @return array<int, string> The formats.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function supportedFormats(): array {
		return ['csv'];
	}//end supportedFormats()

	/**
	 * The grouping for the context's period as CSV.
	 *
	 * @param array<string, mixed> $context The report context (period, administrationId).
	 * @param string $format The format; only CSV is rendered.
	 *
	 * @return GeneratedFile The CSV.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$period = self::contextValue(context: $context, key: 'period');
		$sections = $this->sections(context: $context);

		$handle = fopen('php://temp', 'r+');
		foreach ($this->subjectCosts->typeCsvLines(sections: $sections, period: $period) as $line) {
			fputcsv($handle, $line);
		}

		rewind($handle);
		$content = (string)stream_get_contents($handle);
		fclose($handle);

		$name = 'subject-costs-per-type';
		if ($period !== '') {
			$name .= '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $period);
		}

		return new GeneratedFile(fileName: $name . '.csv', mimeType: 'text/csv', format: 'csv', content: $content);
	}//end generate()

	/**
	 * The per-type sections for the context, for a page.
	 *
	 * @param array<string, mixed> $context The report context (period, administrationId).
	 *
	 * @return array<int, array<string, mixed>> The sections.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public function sections(array $context): array {
		return $this->subjectCosts->perType(
			period: self::contextValue(context: $context, key: 'period'),
			administrationId: self::contextValue(context: $context, key: 'administrationId')
		);
	}//end sections()

	/**
	 * A context value as a trimmed string.
	 *
	 * @param array<string, mixed> $context The context.
	 * @param string $key The key.
	 *
	 * @return string The value, or ''.
	 */
	private static function contextValue(array $context, string $key): string {
		$value = ($context[$key] ?? '');
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end contextValue()
}//end class
