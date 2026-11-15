<?php

/**
 * Effective Version Picker
 *
 * Picks the latest-effective version of a dated record (RateRecord,
 * RetainerSchedule) that brackets a date — shared by {@see RateCardResolver}
 * and {@see RetainerResolver}, which differ only in the name of the field
 * that closes a version.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Latest-effective version selection over ISO-dated records.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
class EffectiveVersionPicker {

	/**
	 * Construct the picker.
	 *
	 * @param string $endField The field that closes a version (`expiresAt`, `endDate`).
	 */
	public function __construct(
		private readonly string $endField,
	) {
	}//end __construct()

	/**
	 * The version that applies on `$date` with the highest effectiveDate.
	 *
	 * Versions with a future effectiveDate, or an end already past, are
	 * skipped; among the remainder the highest effectiveDate wins and a tie
	 * keeps the first one seen.
	 *
	 * @param array<int,array<string,mixed>> $records Candidate versions.
	 * @param string                         $date    ISO date the version should apply on.
	 *
	 * @return array<string,mixed>|null The winning version, or null when none apply.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function pick(array $records, string $date): array|null {
		$best = null;
		foreach ($records as $record) {
			if ($this->applies(record: $record, date: $date) === true
				&& ($best === null || (string)($best['effectiveDate'] ?? '') < (string)($record['effectiveDate'] ?? ''))
			) {
				$best = $record;
			}
		}

		return $best;
	}//end pick()

	/**
	 * Whether a version is in effect on a date.
	 *
	 * @param array<string,mixed> $record One version.
	 * @param string              $date   ISO date.
	 *
	 * @return boolean
	 */
	private function applies(array $record, string $date): bool {
		$effective = (string)($record['effectiveDate'] ?? '');
		$end = (string)($record[$this->endField] ?? '');

		return ($effective === '' || ($effective > $date) === false) && ($end === '' || ($end < $date) === false);
	}//end applies()
}//end class
