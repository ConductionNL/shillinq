<?php

/**
 * Contract of BudgetImpactEmitter
 *
 * The public surface of BudgetImpactEmitter that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BudgetImpactEmitter.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetImpactEmitterInterface {

	/**
	 * Emit the `obligation.activated` CloudEvent (REQ-007).
	 *
	 * Idempotency: subscribers MUST treat `bronReferentie` + `eventName`
	 * as the dedup key so retries from the listener (after a network
	 * failure on the openconnector outbound source) do not double-count
	 * the committed expense.
	 *
	 * @param array<string, mixed> $commitment Activated Commitment payload.
	 * @param array<string, mixed> $source Source TenderNedProcurement payload (dossier URL).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md#req-007
	 */
	public function emitActivated(array $commitment, array $source = []): void;

	/**
	 * Emit the `milestone.completed` CloudEvent (Task 5.3 / REQ-005).
	 *
	 * Carries the OrderFulfilment identifier, the linked obligation,
	 * the approval marker, and the bewijsstuk count so downstream
	 * subscribers (audit-trail consumer + budget-utilisation widget) can
	 * reconcile without re-fetching the OR record.
	 *
	 * @param array<string, mixed> $oplevering Completed OrderFulfilment payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md#req-005
	 */
	public function emitMilestoneCompleted(array $oplevering): void;
}//end interface
