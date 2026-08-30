<?php

/**
 * Tolerance Profile Service
 *
 * Server-authoritative tolerance evaluation for the 3-way matching engine
 * (REQ-PO3W-006, REQ-TOL-001). Owns:
 *
 *  - getApplicableProfile(): most-specific scope resolution
 *    (supplier > category > gl_account > global) returning the first active
 *    ToleranceProfile that matches the candidate hierarchy, or null when no
 *    profile is configured (the matching engine then falls back to a
 *    pass-through profile that auto-approves only exact matches).
 *  - evaluateWithinTolerance(): "more permissive" comparison of a signed
 *    cents-delta against the profile's priceToleranceAmount (cents) OR
 *    priceTolerancePercentage (basis points of the expected cents value),
 *    succeeding when EITHER threshold is satisfied.
 *  - evaluateQuantityVariance(): proportional comparison of a quantity
 *    delta against quantityTolerancePercentage (basis points of the
 *    expected quantity, integer-thousandths arithmetic).
 *  - evaluateDateVariance(): absolute day-count comparison of a delivery
 *    date delta against dateToleranceDays.
 *
 * Money + quantity arithmetic stays integer (cents + thousandths) to avoid
 * float drift across the matching engine; tolerance percentages are basis
 * points (1/10000) per the slice-01 ToleranceProfile schema.
 *
 * All reads go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject) — the methods `findObject` /
 * `createFromArray` / `deleteFromId` do NOT exist and are never used
 * ([[or-objectservice-api]]).
 *
 * The administration scope is asserted by the matching engine before
 * resolving a profile (ADR-005 IDOR-safe); this service trusts the caller
 * supplies a vetted administrationId.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Slice 06 — most-specific ToleranceProfile resolution and per-field
 * tolerance evaluation.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 */
class ToleranceProfileService {

	/**
	 * Schema slug for the ToleranceProfile register (declared in slice 01).
	 *
	 * @var string
	 */
	public const SCHEMA_TOLERANCE_PROFILE = 'ToleranceProfile';

	/**
	 * Active profile status — retired profiles remain historically
	 * referenced but no longer apply to new matches.
	 *
	 * @var string
	 */
	private const STATUS_ACTIVE = 'active';

	/**
	 * Scope precedence — narrower scopes override broader ones
	 * (REQ-PO3W-006). Order matters: the first hit wins.
	 *
	 * @var array<int,string>
	 */
	private const SCOPE_PRIORITY = ['supplier', 'category', 'gl_account', 'global'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService
	 *                                      is fetched lazily so unit tests can
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the most-specific applicable ToleranceProfile for a match
	 * candidate (REQ-PO3W-006).
	 *
	 * Precedence: supplier > category > gl_account > global. The first
	 * active profile whose scope tuple matches the candidate hierarchy
	 * wins; broader scopes are only consulted when no narrower profile
	 * exists. Retired profiles are skipped.
	 *
	 * The candidate hierarchy is supplied by the matching engine — it
	 * extracts supplierId from the SupplierInvoice, productCategory from
	 * the matched PO line (when present) and glAccount from the same
	 * source. Any of these may legitimately be empty (e.g. invoices with
	 * no PO yet) in which case the corresponding scope tier is skipped.
	 *
	 * Returns null when no active profile matches; the matching engine
	 * then applies a pass-through "exact-only" comparison so unconfigured
	 * deployments never silently auto-approve mismatched invoices.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,string|null> $candidate Candidate hierarchy:
	 *                                             `supplierId`,
	 *                                             `productCategory`,
	 *                                             `glAccount`.
	 *
	 * @return array<string,mixed>|null The applicable ToleranceProfile or null.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function getApplicableProfile(string $administrationId, array $candidate): ?array {
		if ($administrationId === '') {
			return null;
		}

		$profiles = $this->findAll(
			schema: self::SCHEMA_TOLERANCE_PROFILE,
			filters: ['administrationId' => $administrationId]
		);
		if ($profiles === []) {
			return null;
		}

		// Group active profiles by scope so the precedence walk can read
		// the right bucket in O(1).
		$byScope = ['supplier' => [], 'category' => [], 'gl_account' => [], 'global' => []];
		foreach ($profiles as $profile) {
			$status = (string)($profile['status'] ?? '');
			if ($status !== self::STATUS_ACTIVE) {
				continue;
			}

			$scope = (string)($profile['scope'] ?? '');
			if (isset($byScope[$scope]) === false) {
				continue;
			}

			$byScope[$scope][] = $profile;
		}

		foreach (self::SCOPE_PRIORITY as $scope) {
			$needle = $this->scopeReferenceFromCandidate(scope: $scope, candidate: $candidate);
			foreach ($byScope[$scope] as $profile) {
				if ($scope === 'global') {
					// Global profiles ignore scopeReference; the first
					// active one wins.
					return $profile;
				}

				if ($needle === '') {
					continue;
				}

				$reference = (string)($profile['scopeReference'] ?? '');
				if ($reference !== '' && $reference === $needle) {
					return $profile;
				}
			}
		}

		return null;
	}//end getApplicableProfile()

	/**
	 * Decide whether a signed cents delta is within the profile's price
	 * tolerance (REQ-PO3W-004 "more permissive" rule).
	 *
	 * Two thresholds may apply:
	 *
	 *  - priceToleranceAmount: absolute cents (integer);
	 *  - priceTolerancePercentage: basis points of the expected cents
	 *    value (1/10000) — so 50 = 0.5 %.
	 *
	 * Either may be null. The comparison succeeds when EITHER threshold
	 * is satisfied (the more permissive one), or when both are null and
	 * the delta is exactly zero (no tolerance configured = exact match
	 * only).
	 *
	 * @param int $expectedCents Expected amount in cents.
	 * @param int $actualCents Actual amount in cents.
	 * @param array<string,mixed>|null $profile Applicable ToleranceProfile (or null).
	 *
	 * @return bool True when within tolerance.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function evaluateWithinTolerance(int $expectedCents, int $actualCents, ?array $profile): bool {
		$deltaAbsCents = abs($actualCents - $expectedCents);
		if ($deltaAbsCents === 0) {
			return true;
		}

		if ($profile === null) {
			return false;
		}

		$absoluteThreshold = ($profile['priceToleranceAmount'] ?? null);
		if (is_numeric($absoluteThreshold) === true && (int)$absoluteThreshold >= 0) {
			if ($deltaAbsCents <= (int)$absoluteThreshold) {
				return true;
			}
		}

		$bps = ($profile['priceTolerancePercentage'] ?? null);
		if (is_numeric($bps) === true && (int)$bps >= 0) {
			$expectedAbs = abs($expectedCents);
			// Threshold in cents = expected × bps / 10000, rounded half-up
			// so a delta exactly at the boundary still passes.
			$thresholdCents = (int)round((($expectedAbs * (int)$bps) / 10000), 0, PHP_ROUND_HALF_UP);
			if ($deltaAbsCents <= $thresholdCents) {
				return true;
			}
		}

		return false;
	}//end evaluateWithinTolerance()

	/**
	 * Decide whether a quantity delta (thousandths) is within the
	 * profile's quantityTolerancePercentage (basis points of the expected
	 * thousandths).
	 *
	 * @param int $expectedThousandths Expected quantity, integer thousandths.
	 * @param int $actualThousandths Actual quantity, integer thousandths.
	 * @param array<string,mixed>|null $profile Applicable ToleranceProfile (or null).
	 *
	 * @return bool True when within tolerance.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function evaluateQuantityVariance(int $expectedThousandths, int $actualThousandths, ?array $profile): bool {
		$deltaAbs = abs($actualThousandths - $expectedThousandths);
		if ($deltaAbs === 0) {
			return true;
		}

		if ($profile === null) {
			return false;
		}

		$bps = ($profile['quantityTolerancePercentage'] ?? null);
		if (is_numeric($bps) === false || (int)$bps < 0) {
			return false;
		}

		$expectedAbs = abs($expectedThousandths);
		$thresholdMilli = (int)round((($expectedAbs * (int)$bps) / 10000), 0, PHP_ROUND_HALF_UP);

		return $deltaAbs <= $thresholdMilli;
	}//end evaluateQuantityVariance()

	/**
	 * Decide whether a date delta (in days) is within the profile's
	 * dateToleranceDays.
	 *
	 * @param int $deltaDays Signed delta in days (actual - expected).
	 * @param array<string,mixed>|null $profile Applicable ToleranceProfile (or null).
	 *
	 * @return bool True when within tolerance.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function evaluateDateVariance(int $deltaDays, ?array $profile): bool {
		$deltaAbs = abs($deltaDays);
		if ($deltaAbs === 0) {
			return true;
		}

		if ($profile === null) {
			return false;
		}

		$tolerance = ($profile['dateToleranceDays'] ?? null);
		if (is_numeric($tolerance) === false || (int)$tolerance < 0) {
			return false;
		}

		return $deltaAbs <= (int)$tolerance;
	}//end evaluateDateVariance()

	/**
	 * Pull the candidate-hierarchy reference for a scope tier.
	 *
	 * @param string $scope Scope name.
	 * @param array<string,string|null> $candidate Candidate hierarchy.
	 *
	 * @return string
	 */
	private function scopeReferenceFromCandidate(string $scope, array $candidate): string {
		if ($scope === 'global') {
			return '';
		}

		$key = match ($scope) {
			'supplier' => 'supplierId',
			'category' => 'productCategory',
			'gl_account' => 'glAccount',
			default => '',
		};

		if ($key === '') {
			return '';
		}

		return trim((string)($candidate[$key] ?? ''));
	}//end scopeReferenceFromCandidate()

	/**
	 * Fetch all matching records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ToleranceProfileService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
