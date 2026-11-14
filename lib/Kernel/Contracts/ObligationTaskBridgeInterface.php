<?php

/**
 * Contract of ObligationTaskBridge
 *
 * The public surface of ObligationTaskBridge that other components call, so they depend
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

/**
 * Contract of ObligationTaskBridge.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObligationTaskBridgeInterface {

	/**
	 * Create (or link) an NC Tasks VTODO / Deck card for an obligation.
	 *
	 * Honestly attempts to resolve a calendar/task backend and write a VTODO
	 * with the obligation's title, due date, and assignee. On success returns
	 * the task URI with taskLinkStatus = 'linked'. When no backend is
	 * resolvable, or the write fails, the method degrades fail-closed: it logs
	 * the concrete reason and returns taskLinkStatus = 'failed' — it does NOT
	 * throw, so obligation CRUD is never blocked (REQ-CLM-003).
	 *
	 * Compliance-deadline-calendar extension (REQ-CDC-005): in addition to
	 * the VTODO, the bridge publishes an additive deadline VEVENT via
	 * {@see publishDeadlineEvent()} so the contract deadline also appears
	 * on the calendar. The result gains the additive `eventUri` +
	 * `eventLinkStatus` keys; the original `taskUri` + `taskLinkStatus`
	 * contract is unchanged.
	 *
	 * @param array<string,mixed> $obligation The ContractObligation field map.
	 *
	 * @return array{taskUri: ?string, taskLinkStatus: string, eventUri: ?string, eventLinkStatus: string} Bridge result.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function createTaskForObligation(array $obligation): array;
}//end interface
