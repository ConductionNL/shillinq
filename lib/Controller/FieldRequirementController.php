<?php

/**
 * FieldRequirementController: the field list shown on a required field's page.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the fields of a requirement's record type, marked always, administration or no.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
class FieldRequirementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request      The request.
	 * @param AdministrationContextService $context      The caller's administrations.
	 * @param FieldRequirements            $requirements The requirements.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly FieldRequirements $requirements,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/field-requirements/{id}/fields: the record type's fields for one requirement.
	 *
	 * @param string $id The requirement.
	 *
	 * @return JSONResponse `{rows}`, 401, or a masked 404 for another administration's requirement.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	#[NoAdminRequired]
	public function fields(string $id): JSONResponse {
		$requirement = $this->authorizeRequirement(id: $id);
		if ($requirement instanceof JSONResponse) {
			return $requirement;
		}

		return new JSONResponse(['rows' => $this->requirements->formFields(requirement: $requirement)]);

	}//end fields()

	/**
	 * The requirement when the caller may see its administration; otherwise the error response.
	 *
	 * @param string $id The requirement.
	 *
	 * @return array<string, mixed>|JSONResponse The requirement, 401 or a masked 404.
	 */
	private function authorizeRequirement(string $id): array|JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$requirement = $this->requirements->find(id: $id);
		if ($requirement === null
			|| $this->context->canAccess(administrationId: (string)($requirement['administrationId'] ?? '')) === false
		) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return $requirement;

	}//end authorizeRequirement()
}//end class
