<?php

/**
 * WBSO caller check
 *
 * Whether the caller of a WBSO v1 API request is logged in and holds a role.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Authentication and role checks for the WBSO API.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
final class WbsoCallerCheck {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session.
	 * @param WbsoRbacResolver $rbac The WBSO roles of the caller.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly WbsoRbacResolver $rbac,
	) {
	}//end __construct()

	/**
	 * A 401 when no user is logged in, otherwise null.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function authenticationRefusal(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;

	}//end authenticationRefusal()

	/**
	 * A 403 with the refusal when the caller holds none of the roles, otherwise null.
	 *
	 * @param array<int,string> $roles The roles that allow the action.
	 * @param string $refusal The refusal message.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function roleRefusal(array $roles, string $refusal): ?JSONResponse {
		if ($this->rbac->hasAny($roles) === false) {
			return new JSONResponse(['error' => $refusal], Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end roleRefusal()
}//end class
