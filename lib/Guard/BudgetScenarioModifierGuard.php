<?php

/**
 * Budget Scenario Modifier Guard
 *
 * ADR-031 exception-path save precondition for the `BudgetScenarioModifier`
 * schema (`lib/Settings/register.d/budget-scenarios.json`, REQ-BSC-004).
 * Validates, before any `BudgetScenarioModifier` is persisted:
 *
 *   1. Per-type required-field consistency — a `RECURRING_*` modifier needs
 *      `targetRecurId`; `RECURRING_AMOUNT_CHANGE` additionally needs
 *      `newStandardAmount`; `LEDGER_AMOUNT_DELTA` needs `targetLedgerGroupId`
 *      AND `amountDeltaCents` (`design.md` §4a).
 *   2. The one unresolvable-conflict rule: two `RECURRING_*` modifiers in the
 *      SAME `scenarioId` targeting the SAME `targetRecurId` have no
 *      well-defined combined meaning (does the amount change first and then
 *      the row ends, or does ending make the amount change moot?) — rejected
 *      outright, fail-closed. At most one `RECURRING_*` modifier per
 *      `targetRecurId` per scenario (`design.md` §5a). Every other pairing —
 *      different `recurId`s, or a `RECURRING_*` alongside a
 *      `LEDGER_AMOUNT_DELTA` — is allowed to coexist; the evaluator sums them
 *      additively, order-independent (`design.md` §5b), so this guard does
 *      not need to reason about ordering at all.
 *
 * Registered under its literal `Class::method` tag in `Application.php`
 * (`register()`, via the shared `RegisterRequiresGuardAdapter`) — a
 * `requires`/`preconditions.save` string containing `::` can never resolve
 * through Nextcloud's container without an explicit alias
 * (shillinq#425/#433's documented defect class); unlike the pre-existing,
 * still-unregistered `CashflowRecurringGuard`/`ProgrammaLinkGuard`
 * `preconditions.save` tags (that fleet-wide gap is filed separately as
 * shillinq#433 and intentionally not fixed here), THIS guard is new code
 * added by `budget-scenarios`, so it is registered so it actually enforces
 * at runtime rather than merely existing as an unreachable class.
 *
 * ADR-031 exception reason: the same-`targetRecurId` check is a cross-object
 * uniqueness/conflict constraint (does any OTHER sibling
 * `BudgetScenarioModifier` in the same scenario already target this
 * `recurId`) — a declarative lifecycle DSL cannot express a query across
 * sibling objects, matching every other guard already in this codebase's
 * exception-path inventory (e.g. `AnnualBudgetDefaultGuard`, `BudgetBlocker`,
 * `DualGaapGuard`).
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Save precondition guard for the `BudgetScenarioModifier` schema per
 * REQ-BSC-004.
 *
 * Fail-closed: any lookup exception denies the save (CWE-863 / OWASP
 * A01:2021).
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 */
class BudgetScenarioModifierGuard {
	/**
	 * The BudgetScenarioModifier schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_MODIFIER = 'BudgetScenarioModifier';

	/**
	 * Modifier types that target a CashflowRecurring row by recurId.
	 *
	 * @var array<int,string>
	 */
	private const RECURRING_TYPES = ['RECURRING_END', 'RECURRING_AMOUNT_CHANGE'];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Save precondition for the BudgetScenarioModifier schema.
	 *
	 * Returns true only when the per-type required fields are present AND no
	 * sibling modifier in the same scenario already targets the same
	 * `targetRecurId`. Fail-closed: returns false on any exception (denies
	 * the save) per CWE-863.
	 *
	 * @param array<string, mixed> $modifier The BudgetScenarioModifier object being saved.
	 *
	 * @return bool True when the modifier may be saved.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
	 */
	public function validateOnSave(array $modifier): bool {
		try {
			if ($this->hasConsistentRequiredFields(modifier: $modifier) === false) {
				return false;
			}

			return $this->hasNoSameRecurIdConflict(modifier: $modifier);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetScenarioModifierGuard: validateOnSave failed — denying save (fail-closed)',
				[
					'scenarioId' => ($modifier['scenarioId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end validateOnSave()

	/**
	 * Per-`modifierType` required-field consistency (`design.md` §4a).
	 *
	 * @param array<string, mixed> $modifier The modifier object array.
	 *
	 * @return bool True when every field required for this modifier's type is present.
	 */
	private function hasConsistentRequiredFields(array $modifier): bool {
		$type = (string)($modifier['modifierType'] ?? '');

		if (in_array($type, self::RECURRING_TYPES, true) === true) {
			if ($this->isNonEmptyString(value: ($modifier['targetRecurId'] ?? null)) === false) {
				$this->logger->info(
					'BudgetScenarioModifierGuard: RECURRING_* modifier missing targetRecurId — denying save',
					['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]
				);
				return false;
			}
		}

		if ($type === 'RECURRING_AMOUNT_CHANGE' && is_numeric($modifier['newStandardAmount'] ?? null) === false) {
			$this->logger->info(
				'BudgetScenarioModifierGuard: RECURRING_AMOUNT_CHANGE missing newStandardAmount — denying save',
				['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]
			);
			return false;
		}

		if ($type === 'LEDGER_AMOUNT_DELTA') {
			if ($this->isNonEmptyString(value: ($modifier['targetLedgerGroupId'] ?? null)) === false) {
				$this->logger->info(
					'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing targetLedgerGroupId — denying save',
					['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]
				);
				return false;
			}

			if (is_numeric($modifier['amountDeltaCents'] ?? null) === false) {
				$this->logger->info(
					'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing amountDeltaCents — denying save',
					['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]
				);
				return false;
			}
		}

		return true;

	}//end hasConsistentRequiredFields()

	/**
	 * No sibling `BudgetScenarioModifier` in the same scenario may already
	 * target the same `targetRecurId` (REQ-BSC-004, `design.md` §5a).
	 * Non-`RECURRING_*` modifiers (i.e. `LEDGER_AMOUNT_DELTA`, which carries
	 * no `targetRecurId`) never conflict under this rule.
	 *
	 * @param array<string, mixed> $modifier The modifier object being saved.
	 *
	 * @return bool True when no conflicting sibling exists.
	 */
	private function hasNoSameRecurIdConflict(array $modifier): bool {
		$type = (string)($modifier['modifierType'] ?? '');
		if (in_array($type, self::RECURRING_TYPES, true) === false) {
			return true;
		}

		$targetRecurId = (string)($modifier['targetRecurId'] ?? '');
		if ($targetRecurId === '') {
			// Already denied by hasConsistentRequiredFields(); nothing further to check.
			return true;
		}

		$scenarioId = (string)($modifier['scenarioId'] ?? '');
		if ($scenarioId === '') {
			return true;
		}

		$currentId = ($modifier['id'] ?? $modifier['@self']['id'] ?? null);

		$siblings = $this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA_MODIFIER)
			->findAll(
				[
					'filters' => [
						'scenarioId' => $scenarioId,
						'targetRecurId' => $targetRecurId,
					],
				]
			);

		foreach ($siblings as $sibling) {
			$siblingType = (string)($sibling['modifierType'] ?? '');
			if (in_array($siblingType, self::RECURRING_TYPES, true) === false) {
				continue;
			}

			$siblingId = ($sibling['id'] ?? $sibling['@self']['id'] ?? null);
			if ($currentId !== null && $siblingId === $currentId) {
				// The record being saved itself — not a conflict.
				continue;
			}

			$this->logger->info(
				'BudgetScenarioModifierGuard: another RECURRING_* modifier already targets this '
				. 'recurId in this scenario — denying save',
				[
					'scenarioId' => $scenarioId,
					'targetRecurId' => $targetRecurId,
					'conflictingId' => $siblingId,
				]
			);
			return false;
		}

		return true;

	}//end hasNoSameRecurIdConflict()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return bool True when $value is a non-empty string.
	 */
	private function isNonEmptyString(mixed $value): bool {
		return (is_string($value) === true && $value !== '');
	}//end isNonEmptyString()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
