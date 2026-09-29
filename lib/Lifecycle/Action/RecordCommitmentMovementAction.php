<?php

/**
 * Record Commitment Movement Action
 *
 * The executor of the `record-mutatie` action the Commitment lifecycle
 * declares (planning-commitment-year-end, REQ-PCYE-001, REQ-PCYE-003). On
 * `aangaan` (kind committed) it records the commitment in its movements and
 * raises the outstanding commitments of the matching budgets; on `afsluiten`
 * (kind closed) it releases what remains. The commitment itself is returned
 * unchanged. Nothing was registered under this action before, so every
 * `aangaan` failed.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCP\IUserSession;

/**
 * Writes the committed or closed movement of a commitment.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class RecordCommitmentMovementAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param CommitmentLedger $ledger      Movements, lines and budgets.
	 * @param IUserSession     $userSession The acting user.
	 */
	public function __construct(
		private readonly CommitmentLedger $ledger,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Record the movement the declared kind names.
	 *
	 * @param array<string,mixed> $objectData   The commitment after the transition.
	 * @param array<string,mixed> $previousData The commitment before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters, with `kind`.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The commitment, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-1.2
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$user = $this->userSession->getUser();
		$uid = 'system';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		$kind = (string)($parameters['kind'] ?? 'committed');
		if ($kind === 'closed') {
			$this->ledger->closed(commitment: $objectData, user: $uid);
			return $objectData;
		}

		$this->ledger->committed(commitment: $objectData, user: $uid);
		return $objectData;
	}//end execute()
}//end class
