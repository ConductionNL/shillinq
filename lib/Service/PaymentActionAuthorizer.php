<?php

/**
 * Payment Action Authorizer
 *
 * Asking someone for money is a right, not a side effect of being logged in.
 * This class answers one question: does the calling user carry a named payment
 * action. The mapping from action to groups is app config
 * (`paymentActionGroups`), so an administrator grants it without a release.
 *
 * It fails closed. No session, no mapping, a malformed mapping, or a mapping
 * naming no group the caller is in all answer false. An administrator carries
 * every action, which is what keeps a fresh install usable before anyone has
 * configured anything.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003, REQ-SOPR-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Answers whether the calling user carries a named payment action.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
 */
final class PaymentActionAuthorizer {
	/**
	 * Raising a payment request on an object.
	 *
	 * @var string
	 */
	public const ACTION_REQUEST = 'payment.request';

	/**
	 * Administering a request: sending its link, settling it by other means.
	 *
	 * @var string
	 */
	public const ACTION_ADMINISTER = 'payment.administer';

	/**
	 * The app-config key mapping an action to the groups that carry it.
	 *
	 * @var string
	 */
	public const CONFIG_ACTION_GROUPS = 'paymentActionGroups';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the action matrix.
	 * @param IUserSession $userSession The calling user.
	 * @param IGroupManager $groupManager Group membership of the calling user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * True when the calling user carries the action.
	 *
	 * @param string $action The action, one of the ACTION_* constants.
	 *
	 * @return bool True when the caller carries it.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 */
	public function may(string $action): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$decoded = json_decode($this->appConfig->getValueString('shillinq', self::CONFIG_ACTION_GROUPS, ''), true);
		if (is_array($decoded) === false) {
			return false;
		}

		$allowed = ($decoded[$action] ?? []);
		if (is_array($allowed) === false || $allowed === []) {
			return false;
		}

		foreach ($allowed as $group) {
			if (is_string($group) === true && $this->groupManager->isInGroup($user->getUID(), $group) === true) {
				return true;
			}
		}

		return false;
	}//end may()

	/**
	 * The calling user's id, or an empty string when there is no session.
	 *
	 * @return string The user id.
	 */
	public function callerId(): string {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end callerId()
}//end class
