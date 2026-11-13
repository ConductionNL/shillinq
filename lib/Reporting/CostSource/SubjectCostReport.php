<?php

/**
 * Subject Cost Report
 *
 * The case cost sections of the Iv3 report and of the per-type report, as
 * data for a page and as CSV rows, from one read of the cost sources.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\CostSource
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

namespace OCA\Shillinq\Reporting\CostSource;

use OCP\IL10N;

/**
 * Builds the per-taakveld and per-type case cost sections.
 *
 * A source whose app is missing gives its message and no groups and no
 * totals, so nothing renders as EUR 0.00.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
 */
class SubjectCostReport {

	/**
	 * Constructor.
	 *
	 * @param ClassifiedSubjectCostSource $source The cost source rows.
	 * @param TaskFieldRollup $rollup Rolls refinement codes up to their parent.
	 * @param IL10N $l10n Translates the missing-app messages.
	 */
	public function __construct(
		private readonly ClassifiedSubjectCostSource $source,
		private readonly TaskFieldRollup $rollup,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The sections grouped per taakveld.
	 *
	 * @param string $period The period.
	 * @param string $administrationId The administration, or '' for all.
	 *
	 * @return array<int, array{source: string, message: string|null, groups: array<int, array<string, mixed>>, totals: array<string, int|float>|null}>
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
	 */
	public function perTaskField(string $period, string $administrationId = ''): array {
		return $this->sections(period: $period, administrationId: $administrationId, perType: false);
	}//end perTaskField()

	/**
	 * The sections grouped per type.
	 *
	 * @param string $period The period.
	 * @param string $administrationId The administration, or '' for all.
	 *
	 * @return array<int, array{source: string, message: string|null, groups: array<int, array<string, mixed>>, totals: array<string, int|float>|null}>
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public function perType(string $period, string $administrationId = ''): array {
		return $this->sections(period: $period, administrationId: $administrationId, perType: true);
	}//end perType()

	/**
	 * CSV lines for the per-taakveld sections, header first.
	 *
	 * @param array<int, array<string, mixed>> $sections The sections of perTaskField().
	 *
	 * @return array<int, array<int, string>> The lines.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
	 */
	public function taskFieldCsvLines(array $sections): array {
		$lines = [['subjectCostsPerTaskField', 'objects', 'hours', 'cost', 'incomplete', 'types']];
		foreach ($sections as $section) {
			if ($section['message'] !== null) {
				$lines[] = [$section['message']];
				continue;
			}

			foreach ($section['groups'] as $group) {
				$taskField = $group['taskField'];
				if ($taskField === '') {
					$taskField = $this->l10n->t('No task field');
				}

				$lines[] = [
					$taskField,
					(string)$group['objects'],
					self::hours(hours: $group['hours']),
					self::money(cents: $group['costCents']),
					(string)count($group['incomplete']),
					implode('; ', $group['types']),
				];
			}
		}

		return $lines;
	}//end taskFieldCsvLines()

	/**
	 * CSV lines for the per-type sections, header first.
	 *
	 * @param array<int, array<string, mixed>> $sections The sections of perType().
	 * @param string $period The period, written on every line.
	 *
	 * @return array<int, array<int, string>> The lines.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public function typeCsvLines(array $sections, string $period): array {
		$lines = [['period', 'type', 'objects', 'hours', 'cost', 'incomplete']];
		foreach ($sections as $section) {
			if ($section['message'] !== null) {
				$lines[] = [$period, $section['message']];
				continue;
			}

			foreach ($section['groups'] as $group) {
				$title = $group['typeTitle'];
				if ($title === '') {
					$title = $this->l10n->t('No type');
				}

				$lines[] = [
					$period,
					$title,
					(string)$group['objects'],
					self::hours(hours: $group['hours']),
					self::money(cents: $group['costCents']),
					(string)count($group['incomplete']),
				];
			}

			$totals = $section['totals'];
			$lines[] = [
				$period,
				$this->l10n->t('Total'),
				(string)$totals['objects'],
				self::hours(hours: $totals['hours']),
				self::money(cents: $totals['costCents']),
				(string)$totals['incomplete'],
			];
		}//end foreach

		return $lines;
	}//end typeCsvLines()

	/**
	 * Read the sources once and group them.
	 *
	 * @param string $period The period.
	 * @param string $administrationId The administration, or '' for all.
	 * @param bool $perType Group per type instead of per taakveld.
	 *
	 * @return array<int, array{source: string, message: string|null, groups: array<int, array<string, mixed>>, totals: array<string, int|float>|null}>
	 */
	private function sections(string $period, string $administrationId, bool $perType): array {
		$administrationIds = null;
		if (trim($administrationId) !== '') {
			$administrationIds = [trim($administrationId)];
		}

		$sections = [];
		foreach ($this->source->read(period: $period, administrationIds: $administrationIds) as $read) {
			$message = $this->message(read: $read, perType: $perType);
			if ($message !== null) {
				$sections[] = ['source' => $read['source']['id'], 'message' => $message, 'groups' => [], 'totals' => null];
				continue;
			}

			$groups = SubjectCostGrouping::perTaskField(rows: $read['rows'], rollup: $this->rollup);
			if ($perType === true) {
				$groups = SubjectCostGrouping::perType(rows: $read['rows']);
			}

			$sections[] = [
				'source' => $read['source']['id'],
				'message' => null,
				'groups' => $groups,
				'totals' => SubjectCostGrouping::totals(groups: $groups),
			];
		}//end foreach

		return $sections;
	}//end sections()

	/**
	 * The message for a source whose app is missing, or null.
	 *
	 * @param array<string, mixed> $read The read source.
	 * @param bool $perType Whether this is the per-type report.
	 *
	 * @return string|null The message.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-a-cost-source-is-configuration-and-an-absent-app-is-shown-not-zero-req-iv3-011
	 */
	private function message(array $read, bool $perType): ?string {
		if ($read['missingKind'] === 'hours') {
			return $this->l10n->t('Hours come from Humaniq. Humaniq is not installed.');
		}

		if ($read['missingKind'] === 'source') {
			$key = 'missingPerTaskField';
			if ($perType === true) {
				$key = 'missingPerType';
			}

			return $this->l10n->t($read['source'][$key]);
		}

		return null;
	}//end message()

	/**
	 * Cents as a decimal amount.
	 *
	 * @param int $cents The cents.
	 *
	 * @return string The amount, e.g. `1500.00`.
	 */
	private static function money(int $cents): string {
		return number_format(($cents / 100), 2, '.', '');
	}//end money()

	/**
	 * Hours with two decimals.
	 *
	 * @param float $hours The hours.
	 *
	 * @return string The hours.
	 */
	private static function hours(float $hours): string {
		return number_format($hours, 2, '.', '');
	}//end hours()
}//end class
