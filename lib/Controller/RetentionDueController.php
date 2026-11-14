<?php

/**
 * Retention Due Controller
 *
 * The list of records whose retention period has ended (REQ-RET-015), read
 * by the "Due for destruction" page and the Retention periods dashboard.
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
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\RetentionDueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /api/retention/due[?administrationId=…]
 *
 * Authenticated (#[NoAdminRequired]) and authorised per administration here,
 * through AdministrationContextService::canAccess() (ADR-005): an
 * administration the caller may not read answers the same 404 as one that
 * does not exist. Without `administrationId` the caller's active
 * administration is used.
 *
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
 */
class RetentionDueController extends Controller {

	/**
	 * What an administration id may look like.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request The request.
	 * @param AdministrationContextServiceInterface $context Who the caller is and which administrations they may read.
	 * @param RetentionDueService                   $due     Lists the records past their retention end date.
	 * @param ITimeFactory                          $time    Today.
	 * @param LoggerInterface                       $logger  Where a failed read is reported.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextServiceInterface $context,
		private readonly RetentionDueService $due,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The records of one administration whose retention period has ended.
	 *
	 * @return JSONResponse 200 `{administrationId, results, total}`; 400 for a malformed id;
	 *                      401 anonymous; 404 for an administration the caller may not read
	 *                      or that does not exist; 500 without detail.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->requestedAdministrationId();
		if ($administrationId === '') {
			return new JSONResponse(['administrationId' => null, 'results' => [], 'total' => 0]);
		}

		$refusal = $this->refusalFor(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->listDue(administrationId: $administrationId);
	}//end index()

	/**
	 * The administration asked for, else the caller's active one, else ''.
	 *
	 * @return string The administration id, '' when there is none.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	private function requestedAdministrationId(): string {
		$administrationId = trim((string) $this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = (string) ($this->context->buildContext()['activeAdministrationId'] ?? '');
		}

		return $administrationId;
	}//end requestedAdministrationId()

	/**
	 * The 400 for a malformed id or the 404 for an administration the caller may not read.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may read it.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	private function refusalFor(string $administrationId): ?JSONResponse {
		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			// 404, never 403: a 403 would confirm the administration exists.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end refusalFor()

	/**
	 * The records due for one readable administration, or a 500 without detail.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return JSONResponse 200 `{administrationId, results, total}`, or 500.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	private function listDue(string $administrationId): JSONResponse {
		try {
			$results = $this->due->dueFor(administrationId: $administrationId, today: $this->time->now());
		} catch (Throwable $e) {
			$this->logger->error(
				'[RetentionDueController] Could not list the records due for destruction: ' . $e->getMessage(),
				['administrationId' => $administrationId]
			);
			return new JSONResponse(['error' => 'Could not list the records due for destruction'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['administrationId' => $administrationId, 'results' => $results, 'total' => count($results)]);
	}//end listDue()
}//end class
