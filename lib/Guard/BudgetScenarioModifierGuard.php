<?php

/**
 * Budget Scenario Modifier Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
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
	 * Logged when another RECURRING_* modifier in the scenario targets the same recurId.
	 *
	 * @var string
	 */
	private const CONFLICT_MESSAGE = 'BudgetScenarioModifierGuard: another RECURRING_* modifier already targets this '
		. 'recurId in this scenario — denying save';

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
				['scenarioId' => ($modifier['scenarioId'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end validateOnSave()

	/**
	 * Per-`modifierType` required-field consistency (`design.md` §4a).
	 *
	 * @param array<string, mixed> $modifier The modifier object array.
	 *
	 * @return bool True when every field required for this modifier's type is present.
	 */
	private function hasConsistentRequiredFields(array $modifier): bool {
		$missing = self::missingFieldMessage(modifier: $modifier);
		if ($missing === null) {
			return true;
		}

		$this->logger->info($missing, ['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]);
		return false;
	}//end hasConsistentRequiredFields()

	/**
	 * The refusal message for the first field this modifier's type requires but lacks.
	 *
	 * @param array<string, mixed> $modifier The modifier object array.
	 *
	 * @return string|null The message, or null when every required field is present.
	 */
	private static function missingFieldMessage(array $modifier): ?string {
		$type = (string)($modifier['modifierType'] ?? '');
		if (in_array($type, self::RECURRING_TYPES, true) === true) {
			return self::missingRecurringField(modifier: $modifier, type: $type);
		}

		if ($type === 'LEDGER_AMOUNT_DELTA') {
			return self::missingLedgerField(modifier: $modifier);
		}

		return null;
	}//end missingFieldMessage()

	/**
	 * RECURRING_* needs a targetRecurId; RECURRING_AMOUNT_CHANGE also a numeric newStandardAmount.
	 *
	 * @param array<string, mixed> $modifier The modifier object array.
	 * @param string               $type     Its modifierType.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private static function missingRecurringField(array $modifier, string $type): ?string {
		if (self::isNonEmptyString(value: ($modifier['targetRecurId'] ?? null)) === false) {
			return 'BudgetScenarioModifierGuard: RECURRING_* modifier missing targetRecurId — denying save';
		}

		if ($type === 'RECURRING_AMOUNT_CHANGE' && is_numeric($modifier['newStandardAmount'] ?? null) === false) {
			return 'BudgetScenarioModifierGuard: RECURRING_AMOUNT_CHANGE missing newStandardAmount — denying save';
		}

		return null;
	}//end missingRecurringField()

	/**
	 * LEDGER_AMOUNT_DELTA needs a targetLedgerGroupId and a numeric amountDeltaCents.
	 *
	 * @param array<string, mixed> $modifier The modifier object array.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private static function missingLedgerField(array $modifier): ?string {
		if (self::isNonEmptyString(value: ($modifier['targetLedgerGroupId'] ?? null)) === false) {
			return 'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing targetLedgerGroupId — denying save';
		}

		if (is_numeric($modifier['amountDeltaCents'] ?? null) === false) {
			return 'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing amountDeltaCents — denying save';
		}

		return null;
	}//end missingLedgerField()

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
		$filter = self::siblingFilter(modifier: $modifier);
		if ($filter === null) {
			return true;
		}

		$currentId = self::objectId(record: $modifier);
		$conflict = self::firstConflict(siblings: $this->siblingsMatching(filter: $filter), currentId: $currentId);
		if ($conflict === null) {
			return true;
		}

		$this->logger->info(self::CONFLICT_MESSAGE, $filter + ['conflictingId' => self::objectId(record: $conflict)]);
		return false;
	}//end hasNoSameRecurIdConflict()

	/**
	 * The sibling filter for a RECURRING_* modifier with a targetRecurId and a scenario, else null
	 * (a missing targetRecurId is already denied by hasConsistentRequiredFields()).
	 *
	 * @param array<string, mixed> $modifier The modifier object being saved.
	 *
	 * @return array{scenarioId: string, targetRecurId: string}|null
	 */
	private static function siblingFilter(array $modifier): ?array {
		$type = (string)($modifier['modifierType'] ?? '');
		$targetRecurId = (string)($modifier['targetRecurId'] ?? '');
		$scenarioId = (string)($modifier['scenarioId'] ?? '');
		if (in_array($type, self::RECURRING_TYPES, true) === false || $targetRecurId === '' || $scenarioId === '') {
			return null;
		}

		return ['scenarioId' => $scenarioId, 'targetRecurId' => $targetRecurId];
	}//end siblingFilter()

	/**
	 * The modifiers in the scenario that target the recurId.
	 *
	 * @param array{scenarioId: string, targetRecurId: string} $filter The sibling filter.
	 *
	 * @return array<int, mixed>
	 */
	private function siblingsMatching(array $filter): array {
		return $this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA_MODIFIER)
			->findAll(['filters' => $filter]);
	}//end siblingsMatching()

	/**
	 * The first RECURRING_* sibling that is not the record being saved itself.
	 *
	 * @param array<int, mixed> $siblings  The siblings.
	 * @param mixed             $currentId The saved record's id, or null for a new one.
	 *
	 * @return mixed The conflicting sibling, or null.
	 */
	private static function firstConflict(array $siblings, mixed $currentId): mixed {
		foreach ($siblings as $sibling) {
			if (in_array((string)($sibling['modifierType'] ?? ''), self::RECURRING_TYPES, true) === false) {
				continue;
			}

			if ($currentId !== null && self::objectId(record: $sibling) === $currentId) {
				continue;
			}

			return $sibling;
		}

		return null;
	}//end firstConflict()

	/**
	 * A record's id, or its @self id.
	 *
	 * @param mixed $record The record.
	 *
	 * @return mixed
	 */
	private static function objectId(mixed $record): mixed {
		return ($record['id'] ?? $record['@self']['id'] ?? null);
	}//end objectId()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return bool True when $value is a non-empty string.
	 */
	private static function isNonEmptyString(mixed $value): bool {
		return (is_string($value) === true && $value !== '');
	}//end isNonEmptyString()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
