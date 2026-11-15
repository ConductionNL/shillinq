<?php

/**
 * Contract of BudgetScenarioDefaultPromoter
 *
 * The public surface of BudgetScenarioDefaultPromoter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of BudgetScenarioDefaultPromoter.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetScenarioDefaultPromoterInterface {

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
	public function promote(string $scenarioId): array;
}//end interface
