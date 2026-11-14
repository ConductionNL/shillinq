<?php

/**
 * Executable GloBE information return checks
 *
 * Predicates over a GlobeInformationReturn that `CbcrCheckRunner` has
 * joined with `_jurisdictions`: each jurisdiction of the group with
 * `safeHarbour` (a test passed) and `computationApproved` (design D7,
 * REQ-CBC-012).
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * The GloBE information return checks.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 */
final class GirChecks implements CheckProvider {
	/**
	 * The checks for GlobeInformationReturn.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function checks(): array {
		return [
			'GlobeInformationReturn' => [
				'gir-jurisdiction-covered' => static fn (array $o): bool => self::everyJurisdictionCovered(o: $o),
				'gir-no-missing-elements' => static fn (array $o): bool => ((array)($o['missingElements'] ?? [])) === [],
			],
		];
	}//end checks()

	/**
	 * No test data is seeded for these checks: they judge joined records, not one object.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * Whether every jurisdiction passed a safe harbour test or has an approved computation.
	 *
	 * @param array<string,mixed> $o The joined return.
	 *
	 * @return bool
	 */
	private static function everyJurisdictionCovered(array $o): bool {
		foreach ((array)($o['_jurisdictions'] ?? []) as $jurisdiction) {
			if (($jurisdiction['safeHarbour'] ?? false) !== true && ($jurisdiction['computationApproved'] ?? false) !== true) {
				return false;
			}
		}

		return true;
	}//end everyJurisdictionCovered()
}//end class
