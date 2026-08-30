<?php

/**
 * Inventory Posting Guard
 *
 * ADR-031 exception path lifecycle guard for the perpetual inventory
 * GL-posting envelope (inventory-cogs-posting REQ-CG-002 / REQ-CG-003 /
 * REQ-CG-004). Four predicates:
 *
 *   - {@see self::canPost()} — gate REQ-CG-002 (saleDispatch) and
 *     REQ-CG-003 (goodsReceipt). Denies the lifecycle action when
 *     `unitCost` is null OR no active `InventoryGLConfig` exists for
 *     the administration. The denied paths emit a structured warning
 *     (`unitCost_missing`, `posting_disabled`, `config_missing`) so the
 *     operator UI can surface the gap. No partial GL entry is written.
 *
 *   - {@see self::canPostVariance()} — gate REQ-CG-004 (countVariance).
 *     Adds a zero-delta short-circuit on top of {@see self::canPost()}
 *     so a count that confirms book quantity produces no GLTransaction.
 *
 *   - {@see self::direction()} — REQ-CG-004 direction resolver. Returns
 *     `'positive'` when the delta (actualQuantity - bookQuantity) is
 *     positive, `'negative'` when negative. Routed inline by the
 *     declarative lifecycle DSL because the engine cannot express
 *     sign conditionals natively today (design.md D4 + ADR-031
 *     exception path).
 *
 *   - {@see self::accountExists()} — REQ-CG-001 FK validation. Verifies
 *     every account number on the proposed `InventoryGLConfig` resolves
 *     to an existing `Account` record within the same administration.
 *     Used by the `x-openregister-validations` rules so an
 *     `InventoryGLConfig` cannot save with dangling FKs.
 *
 * All predicates are fail-closed: any unexpected exception denies the
 * transition / validation. Money discipline: integer-cent arithmetic is
 * the responsibility of the posting orchestrator
 * ({@see \OCA\Shillinq\Service\CogsPosterService}); this guard does not
 * touch monetary amounts — it routes the lifecycle action.
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
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-7
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-8
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle guard for the inventory-cogs-posting envelope. ADR-031
 * exception path: the declarative DSL cannot express the four
 * predicates (active-config lookup, sign conditional, FK existence,
 * null-unitCost short-circuit) inline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/inventory-cogs-posting/spec.md
 */
class InventoryPostingGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for structured-warning + fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * REQ-CG-002 / REQ-CG-003 gate: permit the lifecycle posting when
	 * the InventoryValuation snapshot carries a non-null unitCost AND
	 * an active InventoryGLConfig is present for its administration.
	 *
	 * Skip reasons (structured warning emitted, action denied):
	 *   - `unitCost_missing` — unitCost is null (valuation not yet run).
	 *   - `posting_disabled` — InventoryGLConfig.isActive == false.
	 *   - `config_missing`   — no InventoryGLConfig exists for the
	 *                          administration.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record proposed for posting.
	 *
	 * @return bool True when posting may proceed; false on every skip
	 *              or unexpected error.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-7
	 */
	public function canPost(array $valuation): bool {
		try {
			$unitCost = ($valuation['unitCost'] ?? null);
			if ($unitCost === null) {
				$this->logger->warning(
					'InventoryPostingGuard: posting skipped — unitCost_missing',
					[
						'valuationId' => (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? '')),
						'administrationId' => (string)($valuation['administrationId'] ?? ''),
						'reason' => 'unitCost_missing',
					]
				);
				return false;
			}

			$config = $this->resolveActiveConfig(
				administrationId: (string)($valuation['administrationId'] ?? '')
			);

			if ($config === null) {
				$this->logger->warning(
					'InventoryPostingGuard: posting skipped — config_missing',
					[
						'valuationId' => (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? '')),
						'administrationId' => (string)($valuation['administrationId'] ?? ''),
						'reason' => 'config_missing',
					]
				);
				return false;
			}

			if (((bool)($config['isActive'] ?? false)) === false) {
				$this->logger->warning(
					'InventoryPostingGuard: posting skipped — posting_disabled',
					[
						'valuationId' => (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? '')),
						'administrationId' => (string)($valuation['administrationId'] ?? ''),
						'reason' => 'posting_disabled',
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: canPost failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canPost()

	/**
	 * REQ-CG-004 gate: permit the count-variance lifecycle posting when
	 * (a) {@see self::canPost()} would permit and (b) the delta is
	 * non-zero. A zero-delta count produces no GLTransaction per
	 * REQ-CG-004 "Zero variance produces no GL entry" scenario.
	 *
	 * The proposed valuation MUST carry an integer `variance` field
	 * (actualQuantity - bookQuantity) OR an `actualQuantity` +
	 * `bookQuantity` pair from which the delta is derived. When both
	 * are absent the guard denies the action (fail-closed).
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record proposed for posting.
	 *
	 * @return bool True when posting may proceed; false on zero delta,
	 *              missing delta, or any {@see self::canPost()} skip.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
	 */
	public function canPostVariance(array $valuation): bool {
		try {
			if ($this->canPost(valuation: $valuation) === false) {
				return false;
			}

			$delta = $this->deltaQuantity(valuation: $valuation);
			if ($delta === null) {
				$this->logger->warning(
					'InventoryPostingGuard: countVariance posting denied — missing variance / actualQuantity',
					[
						'valuationId' => (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? '')),
						'reason' => 'variance_missing',
					]
				);
				return false;
			}

			if ($delta === 0) {
				$this->logger->info(
					'InventoryPostingGuard: countVariance posting skipped — zero delta',
					[
						'valuationId' => (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? '')),
						'reason' => 'zero_variance',
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: canPostVariance failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canPostVariance()

	/**
	 * REQ-CG-004 direction resolver — returns `'positive'` for a stock
	 * increase (Dr Inventory Asset / Cr Inventory Adjustment) and
	 * `'negative'` for a stock decrease (Dr Inventory Adjustment / Cr
	 * Inventory Asset). The lifecycle DSL invokes this method to route
	 * to the matching `accountRouting` block (positiveVariance vs.
	 * negativeVariance). ADR-031 exception path (design.md D4): the
	 * engine cannot express sign conditionals declaratively today.
	 *
	 * Zero is normalised to `'negative'` for defensive correctness,
	 * though {@see self::canPostVariance()} short-circuits zero deltas
	 * before the engine reaches this method.
	 *
	 * @param int $delta The signed inventory delta (actualQuantity - bookQuantity)
	 *                   in whole units (the spec uses integer count units; the
	 *                   guard does NOT take monetary cents — the declarative
	 *                   action computes `|delta| * unitCost` separately).
	 *
	 * @return string `'positive'` when delta > 0; `'negative'` otherwise.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
	 */
	public function direction(int $delta): string {
		if ($delta > 0) {
			return 'positive';
		}

		return 'negative';
	}//end direction()

	/**
	 * REQ-CG-001 FK invariant: every account number on the proposed
	 * InventoryGLConfig MUST resolve to an existing Account record
	 * within the same administration.
	 *
	 * Iterates the four FK fields (cogsAccountNumber,
	 * inventoryAssetAccountNumber, grIrClearingAccountNumber,
	 * inventoryAdjustmentAccountNumber); when any is set but does not
	 * resolve to an Account row with matching administrationId the
	 * guard returns false (validation fails). Empty / missing FKs are
	 * accepted here — the schema-level `required` enforcement catches
	 * those before this guard runs.
	 *
	 * Fail-closed on exception (denies the save).
	 *
	 * @param array<string,mixed> $proposed The proposed InventoryGLConfig record.
	 *
	 * @return bool True when every present FK resolves; false on any dangling FK or error.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-5
	 */
	public function accountExists(array $proposed): bool {
		try {
			$administrationId = (string)($proposed['administrationId'] ?? '');
			if ($administrationId === '') {
				return true;
			}

			$fields = [
				'cogsAccountNumber',
				'inventoryAssetAccountNumber',
				'grIrClearingAccountNumber',
				'inventoryAdjustmentAccountNumber',
			];


			foreach ($fields as $field) {
				$accountNumber = (string)($proposed[$field] ?? '');
				if ($accountNumber === '') {
					continue;
				}

				$matches = $this->objectService
					->setRegister($this->register())
					->setSchema('Account')
					->findAll(
						[
							'filters' => [
								'accountNumber' => $accountNumber,
								'administrationId' => $administrationId,
							],
							'limit' => 1,
						]
					);

				if ($matches === []) {
					$this->logger->info(
						'InventoryPostingGuard: account FK does not resolve',
						[
							'administrationId' => $administrationId,
							'field' => $field,
							'accountNumber' => $accountNumber,
						]
					);
					return false;
				}
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: accountExists failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end accountExists()

	/**
	 * Resolve the active InventoryGLConfig for an administration, or
	 * null if none exists.
	 *
	 * @param string $administrationId The administration to look up.
	 *
	 * @return array<string,mixed>|null The config payload, or null when absent.
	 */
	private function resolveActiveConfig(string $administrationId): ?array {
		if ($administrationId === '') {
			return null;
		}

		$matches = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryGLConfig')
			->findAll(
				[
					'filters' => ['administrationId' => $administrationId],
					'limit' => 1,
				]
			);

		if ($matches === []) {
			return null;
		}

		$row = $matches[0];
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end resolveActiveConfig()

	/**
	 * Extract the inventory delta from the proposed valuation. Accepts
	 * either an explicit `variance` field or the
	 * `actualQuantity` + `bookQuantity` pair. Returns null when neither
	 * is parseable.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 *
	 * @return int|null The signed delta in whole units, or null when absent.
	 */
	private function deltaQuantity(array $valuation): ?int {
		if (isset($valuation['variance']) === true && is_numeric($valuation['variance']) === true) {
			return (int)$valuation['variance'];
		}

		$actual = ($valuation['actualQuantity'] ?? null);
		$book = ($valuation['bookQuantity'] ?? null);
		if (is_numeric($actual) === true && is_numeric($book) === true) {
			return ((int)$actual - (int)$book);
		}

		return null;
	}//end deltaQuantity()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
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
