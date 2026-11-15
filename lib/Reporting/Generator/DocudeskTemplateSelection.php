<?php

/**
 * Picks the single docudesk template of a report's category, failing closed.
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
 * @spec exclude The reporting capability has no canonical spec (tracked in #525),
 *       as for AbstractDocumentReportGenerator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

/**
 * Template discovery outcome for one report type in one docudesk namespace
 * (REQ-RVD-004): zero, several or an id-less match is refused.
 */
final class DocudeskTemplateSelection {

	/**
	 * Construct the selection.
	 *
	 * @param string $reportType The ReportCatalogue report-type id.
	 * @param string $namespace The docudesk template namespace.
	 */
	public function __construct(
		private readonly string $reportType,
		private readonly string $namespace,
	) {

	}//end __construct()

	/**
	 * The one matching template's id, or the refusal.
	 *
	 * @param array<int,array<string,mixed>> $rows The discovered templates.
	 * @param string $category The report's template category.
	 *
	 * @return array{templateId: string|null, error: string|null}
	 */
	public function single(array $rows, string $category): array {
		$matches = $this->inCategory(rows: $rows, category: $category);
		if (count($matches) === 0) {
			return ['templateId' => null, 'error' => $this->noTemplateError(category: $category)];
		}

		if (count($matches) > 1) {
			return ['templateId' => null, 'error' => $this->ambiguousError(matches: $matches)];
		}

		$id = (string)($matches[0]['id'] ?? $matches[0]['@self']['id'] ?? '');
		if ($id === '') {
			return ['templateId' => null, 'error' => 'The matched docudesk template has no id.'];
		}

		return ['templateId' => $id, 'error' => null];

	}//end single()

	/**
	 * The templates whose category is the report's.
	 *
	 * @param array<int,array<string,mixed>> $rows The discovered templates.
	 * @param string $category The report's template category.
	 *
	 * @return array<int,array<string,mixed>> The matching templates.
	 */
	private function inCategory(array $rows, string $category): array {
		$matches = [];
		foreach ($rows as $template) {
			if ((string)($template['category'] ?? '') === $category) {
				$matches[] = $template;
			}
		}

		return $matches;

	}//end inCategory()

	/**
	 * The refusal when no template carries the report's category.
	 *
	 * @param string $category The report's template category.
	 *
	 * @return string The error message.
	 */
	private function noTemplateError(string $category): string {
		return sprintf(
			'No docudesk template found for "%s" in namespace "%s" (category "%s"); generation is refused.',
			$this->reportType,
			$this->namespace,
			$category
		);

	}//end noTemplateError()

	/**
	 * The refusal when several templates carry the report's category.
	 *
	 * @param array<int,array<string,mixed>> $matches The matching templates.
	 *
	 * @return string The error message.
	 */
	private function ambiguousError(array $matches): string {
		$ids = array_map(
			static fn (array $t): string => (string)($t['id'] ?? ($t['@self']['id'] ?? '?')),
			$matches
		);

		return sprintf(
			'Multiple docudesk templates (%s) for "%s" in namespace "%s"; generation is refused '
			. '(never guess between templates that produce official documents).',
			implode(', ', $ids),
			$this->reportType,
			$this->namespace
		);

	}//end ambiguousError()
}//end class
