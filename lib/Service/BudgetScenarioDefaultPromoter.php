<?php

/**
 * Budget Scenario Default Promoter
 *
 * Enforces "only one BudgetScenario can be default" per `administrationId`
 * (REQ-BSC-002), by ATOMIC DEMOTION rather than rejection — a deliberately
 * different enforcement style than `budget-core-schema`'s
 * `AnnualBudgetDefaultGuard`, which REJECTS a second `activate` when a
 * default already exists. A scenario default answers "which what-if does the
 * grid show by default when nobody picked one" — a UI convenience, not a
 * fiscal-year commitment — so promoting scenario B automatically demotes
 * scenario A in the same action (`openspec/changes/budget-scenarios/
 * design.md` §3a).
 *
 * `isDefault` is set via THIS service call, never via an
 * `x-openregister-lifecycle` transition: demoting a DIFFERENT sibling object
 * as a side effect of promoting this one is not expressible as an ADR-031
 * `requires:` precondition, which can only return true/false about the
 * object being transitioned — it needs a service method that performs two
 * writes (`design.md` §3b).
 *
 * ## Not a database transaction — a verified two-write sequence
 *
 * OpenRegister's object store has no documented multi-object transaction
 * primitive this codebase relies on anywhere else (every existing
 * count-abort migrator in this app compensates for the same absence by
 * checking AFTERWARD, never by wrapping writes in a transaction). This class
 * does not claim atomicity it cannot deliver: it demotes the previous
 * default, promotes the target, THEN VERIFIES BY RE-READING that exactly one
 * `BudgetScenario` for the administration now has `isDefault: true`. A
 * concurrent `promote()` call (e.g. two browser tabs) could interleave its
 * own read-then-write sequence between this one's steps, momentarily leaving
 * zero or two defaults — on any verification mismatch this logs an error and
 * surfaces the inconsistency rather than silently resolving it (`design.md`
 * §3b, open question §13.1).
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
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
use RuntimeException;
use Throwable;

/**
 * Atomically-demoting default-scenario promoter (REQ-BSC-002).
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 */
class BudgetScenarioDefaultPromoter {
	/**
	 * The BudgetScenario schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_SCENARIO = 'BudgetScenario';

	/**
	 * Construct the promoter with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger — records the verification-mismatch race window.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Promote `$scenarioId` to `isDefault: true` for its own
	 * `administrationId`, atomically demoting any previously-default sibling
	 * scenario in the same call (REQ-BSC-002).
	 *
	 * Steps (`design.md` §3b):
	 *   1. Read the target scenario (404-equivalent: throws if not found).
	 *   2. Read the current default (`isDefault: true`) BudgetScenario for the
	 *      target's `administrationId`, if any.
	 *   3. If one exists and its id differs from `$scenarioId`, demote it
	 *      (`isDefault: false`).
	 *   4. Promote the target (`isDefault: true`, `status: active` if not
	 *      already active or archived).
	 *   5. Verify, by re-reading, that exactly one BudgetScenario for the
	 *      administration now has `isDefault: true`. On mismatch, logs an
	 *      error and returns a result flagging the inconsistency rather than
	 *      throwing — the promotion itself already happened; the caller
	 *      decides how to surface the race.
	 *
	 * Promoting an already-default scenario is a no-op write (idempotent):
	 * no sibling is touched, and the verification step still runs.
	 *
	 * @param string $scenarioId The BudgetScenario id to promote.
	 *
	 * @return array{scenarioId: string, administrationId: string, demotedScenarioId: ?string, verified: bool, defaultCount: int} The outcome.
	 *
	 * @throws RuntimeException When the target scenario does not exist.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function promote(string $scenarioId): array {
		$target = $this->find(id: $scenarioId);
		if ($target === null) {
			throw new RuntimeException('BudgetScenarioDefaultPromoter: no BudgetScenario with id ' . $scenarioId);
		}

		$administrationId = (string)($target['administrationId'] ?? '');

		$demotedScenarioId = null;
		$currentDefault = $this->findCurrentDefault(administrationId: $administrationId, excludingId: null);
		if ($currentDefault !== null) {
			$currentDefaultId = (string)($currentDefault['id'] ?? $currentDefault['@self']['id'] ?? '');
			if ($currentDefaultId !== '' && $currentDefaultId !== $scenarioId) {
				$this->save(
					object: array_merge($currentDefault, ['isDefault' => false])
				);
				$demotedScenarioId = $currentDefaultId;
			}
		}

		$status = (string)($target['status'] ?? 'draft');
		if ($status === 'draft') {
			$status = 'active';
		}

		$this->save(
			object: array_merge($target, ['isDefault' => true, 'status' => $status])
		);

		$defaultCount = count($this->findAllDefaults(administrationId: $administrationId));
		$verified = ($defaultCount === 1);
		if ($verified === false) {
			$this->logger->error(
				'BudgetScenarioDefaultPromoter: post-promotion verification found '
				. $defaultCount . ' default BudgetScenario(s) for administration '
				. $administrationId . ' (expected exactly 1) — a concurrent promotion may have '
				. 'raced this one; surfacing the inconsistency rather than silently resolving it',
				['administrationId' => $administrationId, 'scenarioId' => $scenarioId, 'defaultCount' => $defaultCount]
			);
		}

		return [
			'scenarioId' => $scenarioId,
			'administrationId' => $administrationId,
			'demotedScenarioId' => $demotedScenarioId,
			'verified' => $verified,
			'defaultCount' => $defaultCount,
		];

	}//end promote()

	/**
	 * Find the current default BudgetScenario for an administration, if any.
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param string|null $excludingId Reserved for future narrowing; unused today.
	 *
	 * @return array<string,mixed>|null The default scenario row, or null when none exists.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Reserved parameter, kept for signature stability.
	 */
	private function findCurrentDefault(string $administrationId, ?string $excludingId): ?array {
		$rows = $this->findAllDefaults(administrationId: $administrationId);
		return ($rows[0] ?? null);

	}//end findCurrentDefault()

	/**
	 * Every BudgetScenario currently carrying `isDefault: true` for an
	 * administration — used both to find the scenario to demote and, after
	 * promotion, to verify exactly one remains.
	 *
	 * @param string $administrationId The administration to scope the read to.
	 *
	 * @return list<array<string,mixed>> The matching rows.
	 */
	private function findAllDefaults(string $administrationId): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema(self::SCHEMA_SCENARIO)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'isDefault' => true,
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetScenarioDefaultPromoter: failed to query current defaults',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		// ObjectService::findAll() returns ObjectEntity INSTANCES, not arrays:
		// it hands its rows to RenderObject::renderEntities(), whose per-row
		// renderEntity() is declared `): ObjectEntity`. find() above already
		// accounts for this by returning `$entity->getObject()`; this path did
		// not, and the docblock claiming `list<array<string,mixed>>` hid it.
		//
		// The consequence was a 500 on every promotion that had to DEMOTE a
		// previous default. The throw point is findCurrentDefault()'s own
		// return type:
		//
		//   TypeError: findCurrentDefault(): Return value must be of type
		//   ?array, ObjectEntity returned
		//
		// PHP rejects it before promote() ever reaches array_merge(), and
		// BudgetScenarioController's `catch (Throwable)` turns it into an
		// unexpected error. It never fired on the FIRST promotion in an
		// administration, because there is nothing to demote then — exactly
		// the pair the e2e trace showed: 200 with `demotedScenarioId: null`,
		// then 500 on the next one.
		$normalised = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$normalised[] = $row;
				continue;
			}

			$normalised[] = $row->getObject();
		}

		// No array_values(): appending to $normalised already produces a list.
		// The original call was needed because findAll()'s own result may be
		// keyed; this loop re-indexes it by construction.
		return $normalised;

	}//end findAllDefaults()

	/**
	 * Find one BudgetScenario by id.
	 *
	 * @param string $id The BudgetScenario id.
	 *
	 * @return array<string,mixed>|null The row, or null when not found.
	 */
	private function find(string $id): ?array {
		try {
			$entity = $this->objectService
				->setRegister($this->register())
				->setSchema(self::SCHEMA_SCENARIO)
				->find(id: $id);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetScenarioDefaultPromoter: failed to read target scenario',
				['scenarioId' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();

	}//end find()

	/**
	 * Persist an object.
	 *
	 * @param array<string,mixed> $object The full object payload to save.
	 *
	 * @return void
	 */
	private function save(array $object): void {
		$this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA_SCENARIO)
			->saveObject(object: $object);

	}//end save()

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
