<?php

/**
 * Task Field Rollup
 *
 * Rolls a 2023 refinement taakveld code up to its pre-2023 parent.
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
 * Reads `aggregatesUnder` from the municipal taakveld catalogue seed.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-bbv-compliance/spec.md#requirement-the-taakveld-catalogue-holds-the-2023-refinement-codes-and-their-parent-req-bbv-013
 */
class TaskFieldRollup {

	/**
	 * Code => parent code, loaded once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $parents = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $file The catalogue seed; defaults to the shipped municipal one.
	 */
	public function __construct(
		private readonly ?string $file = null,
	) {
	}//end __construct()

	/**
	 * The code a taakveld is reported under: its parent when it rolls up, else itself.
	 *
	 * @param string $code The taakveld code as stored on the type.
	 *
	 * @return string The code to report under.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
	 */
	public function reportedUnder(string $code): string {
		$code = trim($code);

		return ($this->parents()[$code] ?? $code);
	}//end reportedUnder()

	/**
	 * Every code that rolls up, with its parent.
	 *
	 * @return array<string, string> Code => parent.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-bbv-compliance/spec.md#requirement-the-taakveld-catalogue-holds-the-2023-refinement-codes-and-their-parent-req-bbv-013
	 */
	public function parents(): array {
		if ($this->parents !== null) {
			return $this->parents;
		}

		$this->parents = [];
		$file = ($this->file ?? __DIR__ . '/../../Settings/seeds/bbv-taakvelden-gemeente-2025.json');
		if (is_readable($file) === false) {
			return $this->parents;
		}

		$decoded = json_decode((string)file_get_contents($file), true);
		foreach ((array)($decoded['taskFields'] ?? []) as $taskField) {
			$code = trim((string)($taskField['code'] ?? ''));
			$parent = trim((string)($taskField['aggregatesUnder'] ?? ''));
			if ($code !== '' && $parent !== '') {
				$this->parents[$code] = $parent;
			}
		}

		return $this->parents;
	}//end parents()
}//end class
