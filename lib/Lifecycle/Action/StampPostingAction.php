<?php

/**
 * Shillinq StampPostingAction
 *
 * Handler declared first on the GLTransaction `post` transition. It persists
 * the stamps a post gives a ledger transaction (see PostingStamps): the lock,
 * the retention date, the integrity flag and the post on the audit trail,
 * with the posting user. RuleComplianceGuard judges the entry as these
 * stamps leave it, so the posted entry keeps what it was judged by (#516).
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
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Lifecycle\PostingStamps;
use OCP\IUserSession;

/**
 * Stamps a GLTransaction as posted.
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
 */
class StampPostingAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Supplies the posting user.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp the transitioning transaction.
	 *
	 * @param array<string,mixed> $objectData The transaction after its state moved to posted.
	 * @param array<string,mixed> $previousData The transaction before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (none).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The stamped transaction.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$user = $this->userSession->getUser();
		$uid = 'system';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		return (new PostingStamps())->apply(transaction: $objectData, user: $uid, now: new DateTimeImmutable());
	}//end execute()
}//end class
