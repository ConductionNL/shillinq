<?php

/**
 * Budget Scenario Reader
 *
 * The OpenRegister half of `budget-scenarios`' evaluation pipeline
 * (REQ-BSC-007). {@see BudgetScenarioEvaluator} does the arithmetic (pure, no
 * store access); this class is the only one that talks to the store —
 * mirroring the {@see BudgetVsActualsReader}/{@see BudgetVsActualsCalculator}
 * split this wave already established.
 *
 * ## Query budget: exactly 5 `findAll()` calls, independent of scope
 *
 *  1. `BudgetScenario.findAll([administrationId])` — once.
 *  2. `BudgetScenarioModifier.findAll([scenarioId: {in: [...]}])` — once, the
 *     `SpendAnalyticsService.php:183` `in`-filter precedent, scoped to every
 *     scenario id resolved in step 1.
 *  3. `CashflowRecurring.findAll([administrationId])` — once.
 *  4. `BudgetLine.findAll([annualBudgetId: {in: [...]}])` — once.
 *  5. `LedgerGroup.findAll([administrationId])` — once.
 *
 * This is the same batched shape `budget-core-schema design.md` §6b and
 * `budget-projection-engine design.md` §7b independently arrived at for
 * their own readers (`design.md` §6c). A PHPUnit regression test asserts
 * this exact bound, regardless of the number of modifiers or LedgerGroups in
 * scope.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads every schema `BudgetScenarioEvaluator` needs, batched to exactly 5
 * `findAll()` calls (REQ-BSC-007).
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-007
 */
class BudgetScenarioReader {
	/**
	 * BudgetScenario schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_SCENARIO = 'BudgetScenario';

	/**
	 * BudgetScenarioModifier schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_MODIFIER = 'BudgetScenarioModifier';

	/**
	 * CashflowRecurring schema slug (budget-known-costs's own dated primitive).
	 *
	 * @var string
	 */
	public const SCHEMA_CASHFLOW_RECURRING = 'CashflowRecurring';

	/**
	 * BudgetLine schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET_LINE = 'BudgetLine';

	/**
	 * LedgerGroup schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_LEDGER_GROUP = 'LedgerGroup';

	/**
	 * Construct the reader.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Load every read `BudgetScenarioEvaluator` needs for one
	 * `administrationId`, batched to exactly 5 `findAll()` calls total.
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param list<string> $annualBudgetIds The AnnualBudget ids to load BudgetLines for; empty = none loaded.
	 *
	 * @return array{
	 *     scenarios: list<array<string,mixed>>,
	 *     modifiersByScenarioId: array<string,list<array<string,mixed>>>,
	 *     cashflowRecurringRows: list<array<string,mixed>>,
	 *     budgetLines: list<array<string,mixed>>,
	 *     ledgerGroups: list<array<string,mixed>>,
	 * } The assembled context {@see BudgetScenarioEvaluator} consumes.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-007
	 */
	public function loadContext(string $administrationId, array $annualBudgetIds): array {
		$scenarios = $this->query(schema: self::SCHEMA_SCENARIO, filters: ['administrationId' => $administrationId]);

		$scenarioIds = [];
		foreach ($scenarios as $scenario) {
			$id = (string)($scenario['id'] ?? $scenario['@self']['id'] ?? '');
			if ($id !== '') {
				$scenarioIds[] = $id;
			}
		}

		$modifiers = [];
		if ($scenarioIds !== []) {
			$modifiers = $this->query(
				schema: self::SCHEMA_MODIFIER,
				filters: ['scenarioId' => ['in' => $scenarioIds]]
			);
		}

		$modifiersByScenarioId = [];
		foreach ($modifiers as $modifier) {
			$scenarioId = (string)($modifier['scenarioId'] ?? '');
			if ($scenarioId === '') {
				continue;
			}

			$modifiersByScenarioId[$scenarioId][] = $modifier;
		}

		$cashflowRecurringRows = $this->query(
			schema: self::SCHEMA_CASHFLOW_RECURRING,
			filters: ['administrationId' => $administrationId]
		);

		$budgetLines = [];
		if ($annualBudgetIds !== []) {
			$budgetLines = $this->query(
				schema: self::SCHEMA_BUDGET_LINE,
				filters: ['annualBudgetId' => ['in' => $annualBudgetIds]]
			);
		}

		$ledgerGroups = $this->query(
			schema: self::SCHEMA_LEDGER_GROUP,
			filters: ['administrationId' => $administrationId]
		);

		return [
			'scenarios' => $scenarios,
			'modifiersByScenarioId' => $modifiersByScenarioId,
			'cashflowRecurringRows' => $cashflowRecurringRows,
			'budgetLines' => $budgetLines,
			'ledgerGroups' => $ledgerGroups,
		];

	}//end loadContext()

	/**
	 * Resolve every AnnualBudget id for one administration + fiscal year —
	 * a helper for `BudgetScenarioController::evaluate()` to build the
	 * `$annualBudgetIds` argument `loadContext()` needs. A SEPARATE query
	 * from `loadContext()`'s own 5-call budget (REQ-BSC-007 scopes that
	 * bound to the reads `BudgetScenarioEvaluator` itself consumes, not to
	 * resolving which AnnualBudget ids are in scope in the first place).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param int $fiscalYear The fiscal year to scope the read to.
	 *
	 * @return list<string> The matching AnnualBudget ids.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
	 */
	public function resolveAnnualBudgetIds(string $administrationId, int $fiscalYear): array {
		$rows = $this->query(
			schema: 'AnnualBudget',
			filters: ['administrationId' => $administrationId, 'fiscalYear' => $fiscalYear]
		);

		$ids = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? $row['@self']['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;

	}//end resolveAnnualBudgetIds()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * schema must not stop evaluation from computing whatever it can.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetScenarioReader: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;

	}//end query()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
