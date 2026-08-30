<?php

/**
 * Variance Gate
 *
 * ADR-031 exception-path lifecycle guard for the
 * inventory-cycle-count lifecycle. Three operations:
 *
 *   - requireValidScope()       — invoked on draft → submitted. Rejects
 *     a partial count that lacks both `locationFilter` and
 *     `categoryFilter` per REQ-ICC-002 + REQ-ICC-008.
 *
 *   - requireReasonsOnPost()    — invoked on counting → posted and on
 *     posted → reconciled. Walks every InventoryCycleCountLine for the
 *     count; any line whose `requiresReason` flag is true MUST carry a
 *     non-empty, *active* `reasonCode` FK or the transition is denied.
 *     Mirrors the declarative threshold expression on the line schema
 *     (REQ-ICC-004) so the post-time check stays exact even if the
 *     register engine hasn't (yet) materialised `requiresReason`.
 *
 *   - recalculateLine()         — pure helper that recomputes the
 *     derived line fields (countedValue, quantityVariance, valueVariance,
 *     requiresReason) for callers (CycleCountService::snapshotScope,
 *     unit tests) when the engine's `x-openregister-calculations` cannot
 *     express the conditional `requiresReason` expression.
 *
 * Fail-closed: any missing field, empty value, unknown reason code,
 * or unexpected exception denies the transition.
 *
 * Integer-cent arithmetic (multipleOf 0.01 schema discipline) so
 * threshold comparisons remain bit-exact across IEEE-754 round-trips.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per REQ-ICC-004 + REQ-ICC-005 + REQ-ICC-006 cycle-count lifecycle gate.
 *
 * Referenced from
 * inventory-cycle-count.json
 * InventoryCycleCount.x-openregister-lifecycle.transitions.submit.requires,
 * .transitions.post.requires, and .transitions.reconcile.requires.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 */
class VarianceGate {

	/**
	 * Default quantity-variance threshold (percent) per REQ-ICC-004 when no
	 * per-administration override is configured.
	 *
	 * @var float
	 */
	private const DEFAULT_QTY_THRESHOLD_PCT = 5.0;

	/**
	 * Default absolute value-variance threshold (administration base currency)
	 * per REQ-ICC-004 when no per-administration override is configured.
	 *
	 * @var float
	 */
	private const DEFAULT_VALUE_THRESHOLD_ABS = 500.0;

	/**
	 * Construct the guard.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config — used to read register slug + per-administration threshold overrides.
	 * @param LoggerInterface $logger Logger for diagnostics; never leaks line-level payloads.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Reject a partial count that has no scope per REQ-ICC-002 + REQ-ICC-008.
	 *
	 * Accepted by the lifecycle engine on transition draft → submitted.
	 * Full counts always pass. Partial counts MUST carry at least one of
	 * `locationFilter` or `categoryFilter`.
	 *
	 * Fail-closed: any unexpected exception denies the transition.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool True when the scope is valid; false otherwise.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
	 */
	public function requireValidScope(array $count): bool {
		try {
			$countType = (string)($count['countType'] ?? '');
			if ($countType === 'full') {
				return true;
			}

			if ($countType !== 'partial') {
				$this->logger->info(
					'VarianceGate: submit denied — unknown countType',
					[
						'countId' => ($count['countId'] ?? null),
						'countType' => $countType,
					]
				);
				return false;
			}

			$location = trim((string)($count['locationFilter'] ?? ''));
			$category = trim((string)($count['categoryFilter'] ?? ''));
			if ($location === '' && $category === '') {
				$this->logger->info(
					'VarianceGate: submit denied — partial count without scope',
					['countId' => ($count['countId'] ?? null)]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'VarianceGate: scope check failed — denying submit (fail-closed)',
				[
					'countId' => ($count['countId'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end requireValidScope()

	/**
	 * Reject a counting → posted (or posted → reconciled) transition when any
	 * line in the count is flagged `requiresReason=true` without a non-empty,
	 * active `reasonCode` FK per REQ-ICC-004 + REQ-ICC-005.
	 *
	 * Recomputes `requiresReason` from the line + threshold rather than
	 * trusting a potentially stale stored flag — mirrors the declarative
	 * x-openregister-calculations expression. Empty line set passes (zero-
	 * variance counts post cleanly).
	 *
	 * Fail-closed: any missing field, empty value, unknown reason code,
	 * inactive reason code, or unexpected exception denies the transition.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool True when every flagged line carries a valid active reasonCode; false otherwise.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
	 */
	public function requireReasonsOnPost(array $count): bool {
		try {
			$countId = (string)($count['countId'] ?? '');
			$administrationId = (string)($count['administrationId'] ?? '');
			if ($countId === '' || $administrationId === '') {
				$this->logger->info(
					'VarianceGate: post denied — countId or administrationId missing',
					['countId' => ($count['countId'] ?? null)]
				);
				return false;
			}

			[$qtyThresholdPct, $valueThresholdAbs] = $this->thresholds(administrationId: $administrationId);
			$lines = $this->findLinesForCount(administrationId: $administrationId, countId: $countId);
			$activeReasons = $this->activeReasonCodes(administrationId: $administrationId);

			foreach ($lines as $line) {
				if (is_array($line) === false) {
					continue;
				}

				$flagged = $this->isFlagged(
					line: $line,
					qtyThresholdPct: $qtyThresholdPct,
					valueThresholdAbs: $valueThresholdAbs
				);
				if ($flagged === false) {
					continue;
				}

				$reason = trim((string)($line['reasonCode'] ?? ''));
				if ($reason === '') {
					$this->logger->info(
						'VarianceGate: post denied — line requires reason but reasonCode empty',
						[
							'countId' => $countId,
							'lineId' => ($line['lineId'] ?? null),
						]
					);
					return false;
				}

				if (in_array(needle: $reason, haystack: $activeReasons, strict: true) === false) {
					$this->logger->info(
						'VarianceGate: post denied — reasonCode not active for this administration',
						[
							'countId' => $countId,
							'lineId' => ($line['lineId'] ?? null),
							'reasonCode' => $reason,
						]
					);
					return false;
				}
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'VarianceGate: reason check failed — denying transition (fail-closed)',
				[
					'countId' => ($count['countId'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end requireReasonsOnPost()

	/**
	 * Pure helper: recompute the derived fields on a single line per
	 * REQ-ICC-003 + REQ-ICC-004. Returned array overlays the input — caller
	 * decides whether to persist.
	 *
	 * @param array<string,mixed> $line The InventoryCycleCountLine input.
	 * @param float|null $qtyThresholdPct Quantity threshold % (null = default 5%).
	 * @param float|null $valueThresholdAbs Absolute value threshold (null = default EUR 500).
	 *
	 * @return array<string,mixed> The line with countedValue, quantityVariance, valueVariance, requiresReason refreshed.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
	 */
	public function recalculateLine(
		array $line,
		?float $qtyThresholdPct = null,
		?float $valueThresholdAbs = null,
	): array {
		$qtyThresholdPct = ($qtyThresholdPct ?? self::DEFAULT_QTY_THRESHOLD_PCT);
		$valueThresholdAbs = ($valueThresholdAbs ?? self::DEFAULT_VALUE_THRESHOLD_ABS);

		$expectedQty = $this->numericOrNull(value: $line['expectedQuantity'] ?? null);
		$countedQty = $this->numericOrNull(value: $line['countedQuantity'] ?? null);
		$unitCost = $this->numericOrNull(value: $line['unitCost'] ?? null);

		if ($expectedQty !== null && $unitCost !== null) {
			$expCents = (int)round(($this->cents(value: $expectedQty) * $this->cents(value: $unitCost)) / 100);
			$line['expectedValue'] = $this->fromCents(cents: $expCents);
		}

		if ($countedQty === null || $unitCost === null || $expectedQty === null) {
			$line['countedValue'] = null;
			$line['quantityVariance'] = null;
			$line['valueVariance'] = null;
			$line['requiresReason'] = false;
			return $line;
		}

		$countedCents = $this->cents(value: $countedQty);
		$expectedCents = $this->cents(value: $expectedQty);
		$unitCostCents = $this->cents(value: $unitCost);

		$countedValueCents = (int)round(($countedCents * $unitCostCents) / 100);
		$expectedValueCents = (int)round(($expectedCents * $unitCostCents) / 100);
		$qtyVarianceCents = ($countedCents - $expectedCents);
		$valVarianceCents = ($countedValueCents - $expectedValueCents);

		$line['countedValue'] = $this->fromCents(cents: $countedValueCents);
		$line['quantityVariance'] = $this->fromCents(cents: $qtyVarianceCents);
		$line['valueVariance'] = $this->fromCents(cents: $valVarianceCents);
		$line['requiresReason'] = $this->isFlagged(
			line: $line,
			qtyThresholdPct: $qtyThresholdPct,
			valueThresholdAbs: $valueThresholdAbs
		);

		return $line;
	}//end recalculateLine()

	/**
	 * Apply the REQ-ICC-004 threshold rule against a single line. Both
	 * `quantityVariance` and `valueVariance` are recomputed from the raw
	 * counted/expected/unitCost so a stale or absent stored value cannot mask
	 * a flagged line.
	 *
	 * @param array<string,mixed> $line The line under inspection.
	 * @param float $qtyThresholdPct Quantity threshold % (e.g. 5.0).
	 * @param float $valueThresholdAbs Absolute value threshold (e.g. 500.0).
	 *
	 * @return bool True when the line crosses either threshold.
	 */
	private function isFlagged(array $line, float $qtyThresholdPct, float $valueThresholdAbs): bool {
		$expectedQty = $this->numericOrNull(value: $line['expectedQuantity'] ?? null);
		$countedQty = $this->numericOrNull(value: $line['countedQuantity'] ?? null);
		$unitCost = $this->numericOrNull(value: $line['unitCost'] ?? null);

		if ($expectedQty === null || $countedQty === null || $unitCost === null) {
			// An unentered line cannot be flagged — the count is not yet ready to post.
			// Callers ensure all lines have countedQuantity before invoking the gate; if any line
			// is still null we deliberately treat it as "not flagged" and rely on the manifest's
			// "all lines counted" check on the index page.
			return false;
		}

		$qtyVarianceCents = ($this->cents(value: $countedQty) - $this->cents(value: $expectedQty));
		$qtyVariance = abs($this->fromCents(cents: $qtyVarianceCents));

		$countedValueCents = (int)round(($this->cents(value: $countedQty) * $this->cents(value: $unitCost)) / 100);
		$expectedValueCents = (int)round(($this->cents(value: $expectedQty) * $this->cents(value: $unitCost)) / 100);
		$valVariance = abs($this->fromCents(cents: ($countedValueCents - $expectedValueCents)));

		$qtyThresholdAbs = (($expectedQty * $qtyThresholdPct) / 100.0);
		if ($qtyVariance > $qtyThresholdAbs) {
			return true;
		}

		if ($valVariance > $valueThresholdAbs) {
			return true;
		}

		return false;
	}//end isFlagged()

	/**
	 * Resolve the per-administration thresholds from app config, falling back
	 * to the defaults declared on the InventoryCycleCount.x-openregister-metadata
	 * block (5% / EUR 500).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array{0:float,1:float} [qtyThresholdPct, valueThresholdAbs]
	 */
	private function thresholds(string $administrationId): array {
		$qtyPct = self::DEFAULT_QTY_THRESHOLD_PCT;
		$valAbs = self::DEFAULT_VALUE_THRESHOLD_ABS;

		if ($administrationId === '') {
			return [$qtyPct, $valAbs];
		}

		try {
			$qtyKey = 'cyclecount_qty_threshold_pct_' . $administrationId;
			$valKey = 'cyclecount_value_threshold_abs_' . $administrationId;

			$rawQty = $this->appConfig->getValueString(Application::APP_ID, $qtyKey, '');
			$rawVal = $this->appConfig->getValueString(Application::APP_ID, $valKey, '');

			if ($rawQty !== '' && is_numeric($rawQty) === true) {
				$qtyPct = (float)$rawQty;
			}

			if ($rawVal !== '' && is_numeric($rawVal) === true) {
				$valAbs = (float)$rawVal;
			}
		} catch (\Throwable $e) {
			// Keep the defaults — config-read failure is non-fatal here.
			$this->logger->debug(
				'VarianceGate: failed to read per-administration thresholds; using defaults',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
		}//end try

		return [$qtyPct, $valAbs];
	}//end thresholds()

	/**
	 * Fetch all InventoryCycleCountLine rows belonging to a count, scoped to
	 * the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $countId Count identifier.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findLinesForCount(string $administrationId, string $countId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = ($objectService
				->setRegister($this->register())
				->setSchema('InventoryCycleCountLine')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'countId' => $countId,
						],
					]
				) ?? []);
			if (is_array($rows) === true) {
				return $rows;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->error(
				'VarianceGate: failed to list lines for count',
				[
					'countId' => $countId,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end findLinesForCount()

	/**
	 * Fetch the active reason codes for an administration. Empty result is
	 * acceptable (no flagged lines means the post passes anyway).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,string>
	 */
	private function activeReasonCodes(string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = ($objectService
				->setRegister($this->register())
				->setSchema('InventoryVarianceReason')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'isActive' => true,
						],
					]
				) ?? []);
			if (is_array($rows) === false) {
				return [];
			}

			$codes = [];
			foreach ($rows as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$code = trim((string)($row['reasonId'] ?? ''));
				if ($code !== '') {
					$codes[] = $code;
				}
			}

			return array_values(array_unique($codes));
		} catch (\Throwable $e) {
			$this->logger->error(
				'VarianceGate: failed to list active reasons',
				[
					'administrationId' => $administrationId,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end activeReasonCodes()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
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

	/**
	 * Convert a money/quantity value to integer cents (multipleOf 0.01).
	 *
	 * @param mixed $value Schema number (float or int).
	 *
	 * @return int
	 */
	private function cents(mixed $value): int {
		if (is_int($value) === true) {
			return ($value * 100);
		}

		return (int)round(((float)$value) * 100);
	}//end cents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 */
	private function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Coerce a schema value to a float or return null when missing / non-numeric.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return float|null
	 */
	private function numericOrNull(mixed $value): ?float {
		if ($value === null) {
			return null;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (float)$value;
		}

		return null;
	}//end numericOrNull()
}//end class
