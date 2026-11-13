<?php

/**
 * Open item clearing on a balance account (afletteren).
 *
 * GET  /api/v1/open-items/accounts/{accountId}     what is open on the account
 * POST /api/v1/open-items/clear                    clear `lineIds` into a group
 * POST /api/v1/open-items/groups/{groupId}/undo    undo a clearing
 *
 * Every method resolves the administration of what it touches first and checks
 * the caller's membership of it (ADR-005): an account, line or group outside
 * the caller's administrations answers 404, the same as one that does not
 * exist. Clearing and undoing also need the right to post in it (403).
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
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Ledger\OpenItemClearingRefusedException;
use OCA\Shillinq\Service\Ledger\OpenItemClearingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Open item clearing endpoints.
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 */
class OpenItemClearingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param OpenItemClearingService      $service     The clearing service.
	 * @param AdministrationContextService $context     Administration access check.
	 * @param IUserSession                 $userSession The signed-in user.
	 * @param IL10N                        $l10n        Translations for messages.
	 * @param LoggerInterface              $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly OpenItemClearingService $service,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * What is open on an account: uncleared lines, groups that do not net to zero, suggested pairs.
	 *
	 * @param string $accountId The Account uuid.
	 *
	 * @return JSONResponse 200 with the overview, 404 for an unknown or foreign account.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-001, REQ-LOIC-003, REQ-LOIC-004)
	 */
	#[NoAdminRequired]
	public function overview(string $accountId): JSONResponse {
		try {
			$account = $this->service->findAccount(accountId: $accountId);
			if ($this->context->canAccess(administrationId: (string)($account['administrationId'] ?? '')) === false) {
				return $this->notFound();
			}

			return new JSONResponse(data: $this->service->overview(account: $account));
		} catch (OutOfBoundsException $e) {
			return $this->notFound();
		} catch (Throwable $e) {
			return $this->failed(what: 'overview', e: $e);
		}

	}//end overview()

	/**
	 * Clear the selected lines into one group.
	 *
	 * @return JSONResponse 200 with the group, 401, 403, 404 or 422 with a message.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002, REQ-LOIC-004)
	 */
	#[NoAdminRequired]
	public function clear(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$lineIds = $this->request->getParam('lineIds');
		if (is_array($lineIds) === false) {
			return new JSONResponse(data: ['message' => $this->l10n->t('Select at least two lines to clear.')], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$administrationId = $this->service->administrationOfLines(lineIds: $lineIds);
			$denied = $this->writeDenied(administrationId: $administrationId);
			if ($denied !== null) {
				return $denied;
			}

			$reason = $this->request->getParam('reason');
			if (is_string($reason) === false) {
				$reason = '';
			}

			$group = $this->service->clear(lineIds: $lineIds, actor: $user->getUID(), reason: trim($reason));

			return new JSONResponse(data: $group);
		} catch (OutOfBoundsException $e) {
			return $this->notFound();
		} catch (OpenItemClearingRefusedException $e) {
			return $this->refused(e: $e);
		} catch (Throwable $e) {
			return $this->failed(what: 'clear', e: $e);
		}

	}//end clear()

	/**
	 * Undo a clearing: its lines are open again.
	 *
	 * @param string $groupId The ClearingGroup uuid.
	 *
	 * @return JSONResponse 200 with the released lines, 401, 403 or 404.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	#[NoAdminRequired]
	public function undo(string $groupId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$group = $this->service->findGroup(groupId: $groupId);
			$denied = $this->writeDenied(administrationId: (string)($group['administrationId'] ?? ''));
			if ($denied !== null) {
				return $denied;
			}

			return new JSONResponse(data: $this->service->undo(group: $group));
		} catch (OutOfBoundsException $e) {
			return $this->notFound();
		} catch (Throwable $e) {
			return $this->failed(what: 'undo', e: $e);
		}

	}//end undo()

	/**
	 * The response that refuses a write in the administration, or null when it is allowed.
	 *
	 * A non-member gets the masked 404; a member without the right to post gets 403.
	 *
	 * @param string $administrationId The administration of the lines or group.
	 *
	 * @return JSONResponse|null
	 */
	private function writeDenied(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->notFound();
		}

		if ($this->context->canPostJournalEntry(administrationId: $administrationId) === false) {
			return new JSONResponse(
				data: ['message' => $this->l10n->t('You may not clear lines in this administration.')],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;

	}//end writeDenied()

	/**
	 * The 422 for a clearing the service refused, in the caller's language.
	 *
	 * @param OpenItemClearingRefusedException $e The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refused(OpenItemClearingRefusedException $e): JSONResponse {
		return new JSONResponse(
			data: ['message' => $this->l10n->t($e->getTemplate(), $e->getParameters())],
			statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
		);

	}//end refused()

	/**
	 * Log an unexpected failure and answer 500 without detail.
	 *
	 * @param string    $what Which endpoint failed.
	 * @param Throwable $e    The failure.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $what, Throwable $e): JSONResponse {
		$this->logger->error('OpenItemClearingController: ' . $what . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			data: ['message' => $this->l10n->t('The open items could not be processed.')],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end failed()

	/**
	 * The masked 404.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t('Not found')], statusCode: Http::STATUS_NOT_FOUND);

	}//end notFound()
}//end class
