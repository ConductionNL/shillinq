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
use OCA\Shillinq\Kernel\AppIdentity;
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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

		$userId = trim((string)$this->request->getParam('userId', ''));
		$refusal = $this->manageRefusal(administrationId: $administrationId) ?? $this->userRefusal(userId: $userId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->added(grant: new MemberGrant(administrationId: $administrationId, userId: $userId, grantedBy: $grantedBy));
	}//end create()

	/**
	 * The refusal for a caller who may not manage the administration: a
	 * masked 404 when they cannot even see it, else a 403.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller is an owner.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function manageRefusal(string $administrationId): ?JSONResponse {
		if ($this->managementAccess->canManage(administrationId: $administrationId) === true) {
			return null;
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			['error' => 'forbidden', 'message' => $this->l10n->t('Only an owner of this administration can add members.')],
			Http::STATUS_FORBIDDEN
		);
	}//end manageRefusal()

	/**
	 * The 400 unless the user to add exists.
	 *
	 * @param string $userId The trimmed user id.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function userRefusal(string $userId): ?JSONResponse {
		if ($userId === '' || $this->userManager->userExists($userId) === false) {
			return new JSONResponse(
				['error' => 'unknown-user', 'message' => $this->l10n->t('Choose an existing user.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end userRefusal()

	/**
	 * Add the membership: 201 with it, or the failure's answer.
	 *
	 * @param MemberGrant $grant The authorised grant.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function added(MemberGrant $grant): JSONResponse {
		try {
			$membership = $this->addedMembership(grant: $grant);
		} catch (\Throwable $e) {
			return $this->addFailed(e: $e);
		}

		return new JSONResponse($membership, Http::STATUS_CREATED);
	}//end added()

	/**
	 * Write the membership with the posted role, dates and rights.
	 *
	 * @param MemberGrant $grant The authorised grant.
	 *
	 * @return array<string, mixed> The membership.
	 */
	private function addedMembership(MemberGrant $grant): array {
		return $this->memberships->addMember(
			administrationId: $grant->administrationId,
			userId: $grant->userId,
			role: trim((string)$this->request->getParam('role', '')),
			grantedBy: $grant->grantedBy,
			options: $this->membershipOptions(),
		);
	}//end addedMembership()

	/**
	 * The posted validity dates and rights, as given.
	 *
	 * @return array<string, mixed>
	 */
	private function membershipOptions(): array {
		return [
			'validFrom' => $this->request->getParam('validFrom'),
			'validUntil' => $this->request->getParam('validUntil'),
			'mayPostJournalEntries' => $this->request->getParam('mayPostJournalEntries'),
			'mayCloseFiscalYear' => $this->request->getParam('mayCloseFiscalYear'),
		];
	}//end membershipOptions()

	/**
	 * Answer a failed write: 400 for an invalid membership, 409 for an
	 * existing member, else a logged 500.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function addFailed(\Throwable $e): JSONResponse {
		if ($e instanceof InvalidArgumentException) {
			return new JSONResponse(
				['error' => 'invalid-membership', 'message' => $this->l10n->t('Choose a role, and write dates as YYYY-MM-DD.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($e instanceof DomainException) {
			return new JSONResponse(
				['error' => 'already-member', 'message' => $this->l10n->t('This user is already a member of this administration.')],
				Http::STATUS_CONFLICT
			);
		}

		return $this->saveFailed(e: $e);
	}//end addFailed()

	/**
	 * Log an unexpected failure and answer 500.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function saveFailed(\Throwable $e): JSONResponse {
		$this->logger->error('AdministrationMemberController: failed to add a member', ['exception' => $e->getMessage()]);
		return new JSONResponse(['error' => 'save-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end saveFailed()
}//end class
