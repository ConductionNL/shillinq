<?php

/**
 * Inventory Posting Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\AppIdentity;
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
	 * The InventoryGLConfig account FK fields (REQ-CG-001).
	 *
	 * @var array<int,string>
	 */
	private const ACCOUNT_FK_FIELDS = [
		'cogsAccountNumber',
		'inventoryAssetAccountNumber',
		'grIrClearingAccountNumber',
		'inventoryAdjustmentAccountNumber',
	];

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
			return $this->postingAllowed(valuation: $valuation);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: canPost failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canPost()

	/**
	 * A unit cost is known and an active GL config exists for the administration.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 *
	 * @return bool True when posting may proceed.
	 */
	private function postingAllowed(array $valuation): bool {
		$unitCost = ($valuation['unitCost'] ?? null);
		if ($unitCost === null) {
			return $this->skipPosting(valuation: $valuation, reason: 'unitCost_missing');
		}

		$config = $this->resolveActiveConfig(
			administrationId: (string)($valuation['administrationId'] ?? '')
		);

		if ($config === null) {
			return $this->skipPosting(valuation: $valuation, reason: 'config_missing');
		}

		if (((bool)($config['isActive'] ?? false)) === false) {
			return $this->skipPosting(valuation: $valuation, reason: 'posting_disabled');
		}

		return true;

	}//end postingAllowed()

	/**
	 * Emit the structured posting-skipped warning and deny.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 * @param string $reason The skip reason.
	 *
	 * @return false Always.
	 */
	private function skipPosting(array $valuation, string $reason): bool {
		$this->logger->warning(
			'InventoryPostingGuard: posting skipped — ' . $reason,
			[
				'valuationId' => $this->valuationId(valuation: $valuation),
				'administrationId' => (string)($valuation['administrationId'] ?? ''),
				'reason' => $reason,
			]
		);
		return false;

	}//end skipPosting()

	/**
	 * The valuation's id, from `id` or `@self.id`.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 *
	 * @return string The id, or ''.
	 */
	private function valuationId(array $valuation): string {
		return (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? ''));

	}//end valuationId()

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

			return $this->deltaPostable(valuation: $valuation);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: canPostVariance failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canPostVariance()

	/**
	 * The valuation carries a parseable, non-zero inventory delta.
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 *
	 * @return bool True when the variance may be posted.
	 */
	private function deltaPostable(array $valuation): bool {
		$delta = $this->deltaQuantity(valuation: $valuation);
		if ($delta === null) {
			$this->logger->warning(
				'InventoryPostingGuard: countVariance posting denied — missing variance / actualQuantity',
				[
					'valuationId' => $this->valuationId(valuation: $valuation),
					'reason' => 'variance_missing',
				]
			);
			return false;
		}

		if ($delta === 0) {
			$this->logger->info(
				'InventoryPostingGuard: countVariance posting skipped — zero delta',
				[
					'valuationId' => $this->valuationId(valuation: $valuation),
					'reason' => 'zero_variance',
				]
			);
			return false;
		}

		return true;

	}//end deltaPostable()

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
			return $this->accountsResolve(proposed: $proposed);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryPostingGuard: accountExists failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end accountExists()

	/**
	 * Every present account FK resolves to an Account in the administration.
	 *
	 * @param array<string,mixed> $proposed The proposed InventoryGLConfig record.
	 *
	 * @return bool False at the first dangling FK.
	 */
	private function accountsResolve(array $proposed): bool {
		$administrationId = (string)($proposed['administrationId'] ?? '');
		if ($administrationId === '') {
			return true;
		}

		foreach (self::ACCOUNT_FK_FIELDS as $field) {
			$accountNumber = (string)($proposed[$field] ?? '');
			if ($accountNumber !== '' && $this->accountMissing(accountNumber: $accountNumber, administrationId: $administrationId) === true) {
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
		}

		return true;

	}//end accountsResolve()

	/**
	 * Whether no Account with this number exists in the administration.
	 *
	 * @param string $accountNumber The account number.
	 * @param string $administrationId The administration id.
	 *
	 * @return bool True when the account does not resolve.
	 */
	private function accountMissing(string $accountNumber, string $administrationId): bool {
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

		return $matches === [];

	}//end accountMissing()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
