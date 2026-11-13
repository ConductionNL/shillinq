<?php

/**
 * Subject Cost Source Configuration
 *
 * The configured cost sources: objects in another app whose booked hours are
 * costed and classified by the object's type.
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

/**
 * Reads the cost sources from `lib/Settings/subject-cost-sources.json`.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-a-cost-source-is-configuration-and-an-absent-app-is-shown-not-zero-req-iv3-011
 */
class SubjectCostSourceConfiguration {

	/**
	 * The keys every source must carry as a non-empty string.
	 *
	 * @var array<int, string>
	 */
	public const REQUIRED = [
		'id',
		'app',
		'appName',
		'register',
		'schema',
		'typeSchema',
		'typeProperty',
		'taskFieldProperty',
		'typeTitleProperty',
		'missingPerTaskField',
		'missingPerType',
	];

	/**
	 * Constructor.
	 *
	 * @param string|null $file The configuration file; defaults to the shipped one.
	 */
	public function __construct(
		private readonly ?string $file = null,
	) {
	}//end __construct()

	/**
	 * The configured sources. A source missing a required key is left out.
	 *
	 * @return array<int, array<string, string>> The sources.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-a-cost-source-is-configuration-and-an-absent-app-is-shown-not-zero-req-iv3-011
	 */
	public function sources(): array {
		$file = ($this->file ?? __DIR__ . '/../../Settings/subject-cost-sources.json');
		$raw = @file_get_contents($file);
		if ($raw === false) {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || is_array($decoded['sources'] ?? null) === false) {
			return [];
		}

		$sources = [];
		foreach ($decoded['sources'] as $source) {
			$valid = self::valid(source: $source);
			if ($valid !== null) {
				$sources[] = $valid;
			}
		}

		return $sources;
	}//end sources()

	/**
	 * One source with every required key as a trimmed string, or null.
	 *
	 * @param mixed $source The configured entry.
	 *
	 * @return array<string, string>|null The source, or null when incomplete.
	 */
	private static function valid(mixed $source): ?array {
		if (is_array($source) === false) {
			return null;
		}

		$valid = [];
		foreach (self::REQUIRED as $key) {
			$value = ($source[$key] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				return null;
			}

			$valid[$key] = trim($value);
		}

		return $valid;
	}//end valid()
}//end class
