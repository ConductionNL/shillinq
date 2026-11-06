<?php

/**
 * Feed Declaration Validator
 *
 * Checks lib/Settings/feeds.json, the read-only datasets shillinq declares
 * for integriq to serve to an outside BI tool (reporting-data-delivery
 * REQ-RDD-003). A dataset MUST be bound to one administration through the
 * credential, name a schema the register declares, and list only fields that
 * schema has. A dataset without the binding would hand every
 * administration's books to whoever holds one credential, so it is refused.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Feed;

/**
 * Validates the feed declaration.
 */
class FeedDeclarationValidator {

	public const REQUIRED_BINDING = 'credential.administrationId';

	/**
	 * The problems in a declaration; empty when it is valid.
	 *
	 * @param array<string, mixed>                $declaration The decoded feeds.json.
	 * @param array<string, array<int, string>>   $schemaFields Property names per schema slug.
	 *
	 * @return array<int, string> One sentence per problem.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function problems(array $declaration, array $schemaFields): array {
		$problems = [];
		$datasets = $declaration['datasets'] ?? null;
		if (is_array($datasets) === false || $datasets === []) {
			return ['The declaration lists no datasets.'];
		}

		$keys = [];
		foreach ($datasets as $index => $dataset) {
			$key = (string)($dataset['key'] ?? '');
			if ($key === '') {
				$problems[] = 'Dataset ' . $index . ' has no key.';
				continue;
			}

			if (in_array($key, $keys, true) === true) {
				$problems[] = 'Dataset ' . $key . ' is declared twice.';
			}

			$keys[]   = $key;
			$problems = array_merge($problems, $this->datasetProblems(key: $key, dataset: (array)$dataset, schemaFields: $schemaFields));
		}

		return $problems;

	}//end problems()

	/**
	 * The problems of one dataset.
	 *
	 * @param string                            $key          The dataset key.
	 * @param array<string, mixed>              $dataset      The dataset.
	 * @param array<string, array<int, string>> $schemaFields Property names per schema slug.
	 *
	 * @return array<int, string> The problems.
	 */
	private function datasetProblems(string $key, array $dataset, array $schemaFields): array {
		$problems = [];
		if ((string)(($dataset['binding'] ?? [])['administrationId'] ?? '') !== self::REQUIRED_BINDING) {
			$problems[] = 'Dataset ' . $key . ' is not bound to one administration through the credential.';
		}

		$schema = (string)($dataset['schema'] ?? '');
		if (isset($schemaFields[$schema]) === false) {
			$problems[] = 'Dataset ' . $key . ' names schema ' . $schema . ', which the register does not declare.';
			return $problems;
		}

		$fields = $dataset['fields'] ?? [];
		if (is_array($fields) === false || $fields === []) {
			$problems[] = 'Dataset ' . $key . ' lists no fields.';
			return $problems;
		}

		foreach ($fields as $field) {
			if (in_array((string)$field, $schemaFields[$schema], true) === false) {
				$problems[] = 'Dataset ' . $key . ' lists field ' . $field . ', which ' . $schema . ' does not have.';
			}
		}

		return $problems;

	}//end datasetProblems()
}//end class
