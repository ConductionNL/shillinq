<?php

/**
 * Administration Member Controller
 *
 * POST /api/administrations/{administrationId}/members: an owner of the
 * administration, or a Nextcloud admin, adds a member.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\AdministrationMembershipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The owner's path for adding members to an administration.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
class AdministrationMemberController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AdministrationMembershipService $memberships Membership writes.
	 * @param AdministrationContextService $administrationContext The caller and their memberships.
	 * @param AdministrationManagementAccess $managementAccess Owner or admin check.
	 * @param IUserManager $userManager Existence check for the user to add.
	 * @param IL10N $l10n Translated refusals.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationMembershipService $memberships,
		private readonly AdministrationContextService $administrationContext,
		private readonly AdministrationManagementAccess $managementAccess,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Add a member: `{userId, role, validFrom?, validUntil?, mayPostJournalEntries?, mayCloseFiscalYear?}`.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse 201 with the membership; 400, 403, 404 or 409 otherwise.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	#[NoAdminRequired]
	public function create(string $administrationId): JSONResponse {
		$grantedBy = $this->administrationContext->currentUserId();
		if ($grantedBy === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->managementAccess->canManage(administrationId: $administrationId) === false) {
			if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(
				['error' => 'forbidden', 'message' => $this->l10n->t('Only an owner of this administration can add members.')],
				Http::STATUS_FORBIDDEN
			);
		}

		$userId = trim((string)$this->request->getParam('userId', ''));
		if ($userId === '' || $this->userManager->userExists($userId) === false) {
			return new JSONResponse(
				['error' => 'unknown-user', 'message' => $this->l10n->t('Choose an existing user.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$membership = $this->memberships->addMember(
				administrationId: $administrationId,
				userId: $userId,
				role: trim((string)$this->request->getParam('role', '')),
				grantedBy: $grantedBy,
				options: [
					'validFrom' => $this->request->getParam('validFrom'),
					'validUntil' => $this->request->getParam('validUntil'),
					'mayPostJournalEntries' => $this->request->getParam('mayPostJournalEntries'),
					'mayCloseFiscalYear' => $this->request->getParam('mayCloseFiscalYear'),
				],
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				['error' => 'invalid-membership', 'message' => $this->l10n->t('Choose a role, and write dates as YYYY-MM-DD.')],
				Http::STATUS_BAD_REQUEST
			);
		} catch (DomainException $e) {
			return new JSONResponse(
				['error' => 'already-member', 'message' => $this->l10n->t('This user is already a member of this administration.')],
				Http::STATUS_CONFLICT
			);
		} catch (\Throwable $e) {
			$this->logger->error('AdministrationMemberController: failed to add a member', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'save-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($membership, Http::STATUS_CREATED);
	}//end create()
}//end class
