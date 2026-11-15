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
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationManagementAccessInterface;
use OCA\Shillinq\Kernel\Contracts\StandardsPolicyServiceInterface;
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
	 * @param StandardsPolicyServiceInterface $policies Policy read and write.
	 * @param AdministrationContextServiceInterface $administrationContext Membership check.
	 * @param AdministrationManagementAccessInterface $managementAccess Owner or admin check.
	 * @param IL10N $l10n Translated refusals.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly StandardsPolicyServiceInterface $policies,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly AdministrationManagementAccessInterface $managementAccess,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
			return $this->notFound();
		}

		return $this->loadedPolicy(administrationId: $administrationId, canManage: $canManage);
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
		$refusal = $this->managementRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$frameworks = $this->request->getParam('frameworks', null);
		if (is_array($frameworks) === false) {
			return new JSONResponse(['error' => 'invalid-frameworks'], Http::STATUS_BAD_REQUEST);
		}

		return $this->savedPolicy(administrationId: $administrationId, frameworks: array_values($frameworks));
	}//end update()

	/**
	 * The masked 404 for a non-member, the translated 403 for a member who is no owner, or null for an owner.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse|null
	 */
	private function managementRefusal(string $administrationId): JSONResponse|null {
		if ($this->managementAccess->canManage(administrationId: $administrationId) === true) {
			return null;
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return $this->notFound();
		}

		return new JSONResponse(
			['error' => 'forbidden', 'message' => $this->l10n->t('Only an owner of this administration can change its standards policy.')],
			Http::STATUS_FORBIDDEN
		);
	}//end managementRefusal()

	/**
	 * Load the policy and answer it, or the 500 when loading fails.
	 *
	 * @param string $administrationId The administration.
	 * @param bool   $canManage        Whether the caller may change it.
	 *
	 * @return JSONResponse
	 */
	private function loadedPolicy(string $administrationId, bool $canManage): JSONResponse {
		try {
			$policy = $this->policies->policyFor(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('StandardsPolicyController: failed to load the policy', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'load-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['administrationId' => $administrationId, 'frameworks' => $policy['frameworks'], 'canEdit' => $canManage]);
	}//end loadedPolicy()

	/**
	 * Save the frameworks and answer the saved policy, the translated 400 or the 500.
	 *
	 * @param string $administrationId The administration.
	 * @param array  $frameworks       The submitted frameworks, re-indexed.
	 *
	 * @return JSONResponse
	 */
	private function savedPolicy(string $administrationId, array $frameworks): JSONResponse {
		try {
			$policy = $this->policies->savePolicy(administrationId: $administrationId, frameworks: $frameworks);
		} catch (InvalidArgumentException $e) {
			$message = $this->l10n->t('Each framework needs a key, an on or off state and a position.');
			return new JSONResponse(['error' => 'invalid-frameworks', 'message' => $message], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('StandardsPolicyController: failed to save the policy', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'save-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['administrationId' => $administrationId, 'frameworks' => $policy['frameworks'], 'canEdit' => true]);
	}//end savedPolicy()

	/**
	 * The masked not-found answer.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
