<?php

/**
 * WBSO administration scope
 *
 * Resolves the administration a WBSO v1 API request works in, and refuses one
 * the caller holds no membership for.
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

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Administration scope for the WBSO v1 controllers.
 *
 * Requires the using class to hold `$request` and `$administrationContext`.
 *
 * @property IRequest $request
 * @property AdministrationContextService $administrationContext
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
trait WbsoAdministrationScopeTrait {

	/**
	 * The administration of this request, only when the caller is a member of it.
	 *
	 * An omitted `administration_id` means the caller's first valid membership.
	 * A malformed id, or one the caller holds no valid membership for, gives null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function resolveAdministration(): ?string {
		$value = trim((string)$this->request->getParam('administration_id', ''));
		if ($value === '') {
			return ($this->administrationContext->accessibleAdministrationIds()[0] ?? null);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $value) !== 1) {
			return null;
		}

		if ($this->administrationContext->canAccess(administrationId: $value) === false) {
			return null;
		}

		return $value;
	}//end resolveAdministration()

	/**
	 * The answer for an administration the caller may not use.
	 *
	 * A 404, so the response does not confirm that the administration exists.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function administrationNotFound(): JSONResponse {
		return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
	}//end administrationNotFound()
}//end trait
