<?php

/**
 * WBSO action guard
 *
 * The access steps every WBSO v1 API action takes before it does any work.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Runs the WBSO access steps in their fixed order: an authenticated caller,
 * the role the action needs, the administration of the request and, for an
 * action that books, posting rights in it.
 *
 * Each step returns the resolved administration id, or the refusal.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
final class WbsoActionGuard {
	/**
	 * Constructor.
	 *
	 * @param WbsoCallerCheck $caller Authentication and role checks.
	 * @param WbsoAdministrationScope $scope The administration of the request.
	 */
	public function __construct(
		private readonly WbsoCallerCheck $caller,
		private readonly WbsoAdministrationScope $scope,
	) {
	}//end __construct()

	/**
	 * Admit any authenticated caller to an administration they belong to.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function read(): string|JSONResponse {
		$refusal = $this->caller->authenticationRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->administration();

	}//end read()

	/**
	 * Admit a caller holding one of the roles to an administration they belong to.
	 *
	 * @param array<int,string> $roles The roles that allow the action.
	 * @param string $refusal The refusal for a caller without them.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function write(array $roles, string $refusal): string|JSONResponse {
		$denied = $this->caller->authenticationRefusal() ?? $this->caller->roleRefusal(roles: $roles, refusal: $refusal);
		if ($denied !== null) {
			return $denied;
		}

		return $this->administration();

	}//end write()

	/**
	 * Admit a caller holding one of the roles to an administration they may
	 * book journal entries in.
	 *
	 * @param array<int,string> $roles The roles that allow the action.
	 * @param string $refusal The refusal for a caller without them.
	 *
	 * @return string|JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function book(array $roles, string $refusal): string|JSONResponse {
		$administrationId = $this->write(roles: $roles, refusal: $refusal);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		if ($this->scope->canPost(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Posting rights in this administration required'], Http::STATUS_FORBIDDEN);
		}

		return $administrationId;

	}//end book()

	/**
	 * The administration of the request, or a masked 404.
	 *
	 * @return string|JSONResponse
	 */
	private function administration(): string|JSONResponse {
		$administrationId = $this->scope->resolve();
		if ($administrationId === null) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return $administrationId;

	}//end administration()
}//end class
