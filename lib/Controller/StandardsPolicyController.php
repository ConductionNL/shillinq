<?php

/**
 * Standards Policy Controller
 *
 * Reads and saves one administration's accounting standards policy.
 *  - GET /api/administrations/{administrationId}/standards-policy: members of the administration.
 *  - PUT /api/administrations/{administrationId}/standards-policy: its owners, or a Nextcloud admin.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\StandardsPolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The per-administration standards policy endpoints.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */
class StandardsPolicyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param StandardsPolicyService $policies Policy read and write.
	 * @param AdministrationContextService $administrationContext Membership check.
	 * @param AdministrationManagementAccess $managementAccess Owner or admin check.
	 * @param IL10N $l10n Translated refusals.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly StandardsPolicyService $policies,
		private readonly AdministrationContextService $administrationContext,
		private readonly AdministrationManagementAccess $managementAccess,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The administration's policy, and whether the caller may change it.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
	 */
	#[NoAdminRequired]
	public function show(string $administrationId): JSONResponse {
		$canManage = $this->managementAccess->canManage(administrationId: $administrationId);
		if ($canManage === false && $this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$policy = $this->policies->policyFor(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('StandardsPolicyController: failed to load the policy', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'load-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'administrationId' => $administrationId,
				'frameworks' => $policy['frameworks'],
				'canEdit' => $canManage,
			]
		);
	}//end show()

	/**
	 * Save the administration's frameworks (owners and Nextcloud admins).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
	 */
	#[NoAdminRequired]
	public function update(string $administrationId): JSONResponse {
		if ($this->managementAccess->canManage(administrationId: $administrationId) === false) {
			if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(
				['error' => 'forbidden', 'message' => $this->l10n->t('Only an owner of this administration can change its standards policy.')],
				Http::STATUS_FORBIDDEN
			);
		}

		$frameworks = $this->request->getParam('frameworks', null);
		if (is_array($frameworks) === false) {
			return new JSONResponse(['error' => 'invalid-frameworks'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$policy = $this->policies->savePolicy(administrationId: $administrationId, frameworks: array_values($frameworks));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				['error' => 'invalid-frameworks', 'message' => $this->l10n->t('Each framework needs a key, an on or off state and a position.')],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error('StandardsPolicyController: failed to save the policy', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'save-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'administrationId' => $administrationId,
				'frameworks' => $policy['frameworks'],
				'canEdit' => true,
			]
		);
	}//end update()
}//end class
