<?php

/**
 * Shillinq XBRL taxonomy lookup
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sbr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Finds the XBRLTaxonomy record effective for a reporting period that carries an entry point.
 *
 * A record is effective when its `effectiveDate` is on or before the end of
 * the period and its `expiryDate` (none means never) is after the start, it
 * is not archived, and its `entryPoints` list the entry point. Of several,
 * the one that took effect last wins.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-an-instance-is-validated-against-its-entry-point-before-submission-req-tdf-003
 */
class XbrlTaxonomyLookup {

	/**
	 * The taxonomy schema.
	 */
	public const SCHEMA = 'XBRLTaxonomy';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The register slug.
	 * @param ObjectServiceInterface $objectService Reads the taxonomy records.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The taxonomy effective for a period that carries an entry point, or null.
	 *
	 * @param string $entryPoint The entry point.
	 * @param string $start      The first day of the period (Y-m-d).
	 * @param string $end        The last day of the period (Y-m-d).
	 *
	 * @return array<string, mixed>|null The taxonomy record.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function effectiveFor(string $entryPoint, string $start, string $end): ?array {
		if ($entryPoint === '' || $start === '' || $end === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		$rows = $this->objectService->setRegister($register)->setSchema(self::SCHEMA)->findAll([]);

		return self::pick(rows: (array) $rows, entryPoint: $entryPoint, start: $start, end: $end);
	}//end effectiveFor()

	/**
	 * Of these taxonomy records, the one effective for the period that carries the entry point.
	 *
	 * @param array<mixed> $rows       The records.
	 * @param string       $entryPoint The entry point.
	 * @param string       $start      The first day of the period.
	 * @param string       $end        The last day of the period.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public static function pick(array $rows, string $entryPoint, string $start, string $end): ?array {
		$best = null;
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === false || self::isEffective(row: $row, entryPoint: $entryPoint, start: $start, end: $end) === false) {
				continue;
			}

			if ($best === null || substr((string) $row['effectiveDate'], 0, 10) > substr((string) $best['effectiveDate'], 0, 10)) {
				$best = $row;
			}
		}

		return $best;
	}//end pick()

	/**
	 * Whether one record is effective for the period and carries the entry point.
	 *
	 * @param array<string, mixed> $row        The record.
	 * @param string               $entryPoint The entry point.
	 * @param string               $start      The first day of the period.
	 * @param string               $end        The last day of the period.
	 *
	 * @return bool True when it is.
	 */
	private static function isEffective(array $row, string $entryPoint, string $start, string $end): bool {
		$entryPoints = ($row['entryPoints'] ?? []);
		if (is_array($entryPoints) === false || in_array($entryPoint, $entryPoints, true) === false) {
			return false;
		}

		if (in_array(strtolower((string) ($row['status'] ?? '')), ['archived'], true) === true) {
			return false;
		}

		$effective = substr(trim((string) ($row['effectiveDate'] ?? '')), 0, 10);
		$expiry    = substr(trim((string) ($row['expiryDate'] ?? '')), 0, 10);
		if ($effective === '' || $effective > substr($end, 0, 10)) {
			return false;
		}

		return $expiry === '' || $expiry > substr($start, 0, 10);
	}//end isEffective()
}//end class
