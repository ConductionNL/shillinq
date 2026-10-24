<?php

/**
 * Import Batch Controller
 *
 * POST /api/import-batches/{id}/confirm-suggestions: confirm every suggested
 * mapping of a batch in one call.
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
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Import\ImportBatchNotFoundException;
use OCA\Shillinq\Service\Import\SuggestedMappingConfirmation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Server actions on an import batch.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportBatchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SuggestedMappingConfirmation $confirmation Confirms the suggested mappings.
	 * @param IUserSession $userSession The session.
	 * @param IL10N $l10n Translated refusals.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SuggestedMappingConfirmation $confirmation,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Confirm every unconfirmed mapping with a target account.
	 *
	 * The batch's administration must be one of the caller's (else 404), and
	 * the batch must be in the mapping state (else 409).
	 *
	 * @param string $id The batch id.
	 *
	 * @return JSONResponse `{ confirmed: <count> }`.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	#[NoAdminRequired]
	public function confirmSuggestions(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$confirmed = $this->confirmation->confirm(batchId: $id);
		} catch (ImportBatchNotFoundException $e) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		} catch (DomainException $e) {
			return new JSONResponse(
				['error' => 'wrong-state', 'message' => $this->l10n->t('Suggestions can only be confirmed while the batch is being mapped.')],
				Http::STATUS_CONFLICT
			);
		} catch (\Throwable $e) {
			$this->logger->error('ImportBatchController: confirming suggestions failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'confirm-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['confirmed' => $confirmed]);
	}//end confirmSuggestions()
}//end class
