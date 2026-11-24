<?php

/**
 * Executable country-by-country report checks
 *
 * Predicates over a CbcrReturn that `CbcrCheckRunner` has joined with the
 * records the file is made from: `_entities` (each group entity with its
 * `figures` for the year, or null), `_summaries` (the jurisdiction
 * summaries). Severities come from lib/Standards/rules/cbcr-pillar2.json:
 * mandatory blocks Generate XML and submission, recommended warns (design
 * D7, REQ-CBC-012).
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
 * The CbC report checks.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 */
final class CbcrChecks implements CheckProvider {
	/**
	 * The residual above which reconciliation items must explain the difference, in EUR.
	 *
	 * @var float
	 */
	public const RESIDUAL_LIMIT = 1000000.0;

	/**
	 * The largest share by which tangible assets may differ from the fixed asset register.
	 *
	 * @var float
	 */
	public const ASSET_REGISTER_TOLERANCE = 0.01;

	/**
	 * The checks for CbcrReturn.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function checks(): array {
		return [
			'CbcrReturn' => [
				'cbc-figures-every-entity' => static fn (array $o): bool => self::everyEntity(
					o: $o,
					test: static fn (array $entity): bool => is_array($entity['figures'] ?? null) === true
				),
				'cbc-accounts-mapped' => static fn (array $o): bool => self::everyEntity(
					o: $o,
					test: static fn (array $entity): bool => ((array)($entity['figures']['unmappedAccounts'] ?? [])) === []
				),
				'cbc-entity-tin' => static fn (array $o): bool => self::everyEntity(o: $o, test: [self::class, 'hasTin']),
				'cbc-jurisdiction-activity' => static fn (array $o): bool => self::everySummary(
					o: $o,
					test: static fn (array $summary): bool => ((array)($summary['mainBusinessActivities'] ?? [])) !== []
				),
				'cbc-revenue-total' => static fn (array $o): bool => self::everySummary(o: $o, test: [self::class, 'revenueAddsUp']),
				'cbc-residual-explained' => static fn (array $o): bool => (abs((float)($o['reconciliationResidual'] ?? 0)) <= self::RESIDUAL_LIMIT
					|| ((array)($o['reconciliationItems'] ?? [])) !== []),
				'cbc-figures-from-books' => static fn (array $o): bool => self::everyEntity(
					o: $o,
					test: static fn (array $entity): bool => in_array((string)($entity['figures']['source'] ?? 'books'), ['entered', 'imported'], true) === false
				),
				'cbc-tangible-assets-register' => static fn (array $o): bool => self::everyEntity(o: $o, test: [self::class, 'assetsMatchRegister']),
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
	 * Whether an entity has a TIN, or NOTIN with a reason.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function hasTin(array $entity): bool {
		$tin = strtoupper(trim((string)($entity['tin'] ?? '')));
		if ($tin === 'NOTIN') {
			return trim((string)($entity['tinMissingReason'] ?? '')) !== '';
		}

		return $tin !== '';
	}//end hasTin()

	/**
	 * Whether an entity's tangible assets agree with its fixed asset register within 1%.
	 *
	 * An entity without a register value (no administration here, or no assets
	 * in the register) or without a figure for tangible assets is not judged.
	 *
	 * @param array<string,mixed> $entity The entity, with `figures` and `fixedAssetBookValue`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function assetsMatchRegister(array $entity): bool {
		$register = ($entity['fixedAssetBookValue'] ?? null);
		$figure   = ($entity['figures']['tangibleAssetsOtherThanCash'] ?? null);
		if (is_numeric($register) === false || is_numeric($figure) === false) {
			return true;
		}

		$difference = abs((float)$figure - (float)$register);

		return $difference <= 0.01 || $difference <= (abs((float)$register) * self::ASSET_REGISTER_TOLERANCE);
	}//end assetsMatchRegister()

	/**
	 * Whether a summary's total revenue, when stated, is unrelated plus related within a cent.
	 *
	 * @param array<string,mixed> $summary The summary.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function revenueAddsUp(array $summary): bool {
		if (is_numeric($summary['totalRevenue'] ?? null) === false) {
			return true;
		}

		$parts = (float)($summary['unrelatedPartyRevenue'] ?? 0) + (float)($summary['relatedPartyRevenue'] ?? 0);

		return abs($parts - (float)$summary['totalRevenue']) <= 0.01;
	}//end revenueAddsUp()

	/**
	 * Whether every joined entity passes the test.
	 *
	 * @param array<string,mixed> $o    The joined return.
	 * @param callable            $test The test per entity.
	 *
	 * @return bool
	 */
	private static function everyEntity(array $o, callable $test): bool {
		foreach ((array)($o['_entities'] ?? []) as $entity) {
			if (is_array($entity) === false || $test($entity) !== true) {
				return false;
			}
		}

		return true;
	}//end everyEntity()

	/**
	 * Whether every joined summary passes the test.
	 *
	 * @param array<string,mixed> $o    The joined return.
	 * @param callable            $test The test per summary.
	 *
	 * @return bool
	 */
	private static function everySummary(array $o, callable $test): bool {
		foreach ((array)($o['_summaries'] ?? []) as $summary) {
			if (is_array($summary) === false || $test($summary) !== true) {
				return false;
			}
		}

		return true;
	}//end everySummary()
}//end class
