<?php

/**
 * Shillinq Administration Template Controller
 *
 * Saves an administration as an office template, lists the templates for the
 * setup wizard and seeds an administration from one.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Office templates of a chart of accounts. Every administration-scoped call
 * answers a masked 404 to a user without access (ADR-005).
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */
class AdministrationTemplateController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param AdministrationContextService $context   Who may do what in which administration.
	 * @param ChartTemplateService         $templates The templates.
	 * @param LoggerInterface              $logger    Where failures are logged.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly ChartTemplateService $templates,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The active office templates, for the setup wizard's template step.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['results' => $this->templates->activeTemplates()], Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error('AdministrationTemplateController: failed to list templates', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to list templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Save the administration's chart of accounts and dimensions as a template.
	 *
	 * @param string $id The administration.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
	 */
	#[NoAdminRequired]
	public function saveAs(string $id): JSONResponse {
		$refused = $this->refusal(administrationId: $id, mustPost: false);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$template = $this->templates->saveAsTemplate(administrationId: trim($id), name: (string)$this->request->getParam('name', ''));
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(['error' => $invalid->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('AdministrationTemplateController: failed to save template', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to save the template'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($template, Http::STATUS_CREATED);
	}//end saveAs()

	/**
	 * Seed the administration from a template; needs the right to post in it.
	 *
	 * @param string $id The administration.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	#[NoAdminRequired]
	public function seed(string $id): JSONResponse {
		$refused = $this->refusal(administrationId: $id, mustPost: true);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$report = $this->templates->seedFromTemplate(templateId: trim((string)$this->request->getParam('templateId', '')), administrationId: trim($id));
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(['error' => $invalid->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('AdministrationTemplateController: failed to seed from template', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to seed from the template'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($report, Http::STATUS_OK);
	}//end seed()

	/**
	 * The response refusing the call, or null when the user may make it.
	 *
	 * @param string $administrationId The administration.
	 * @param bool   $mustPost         Whether the user needs the right to post in it.
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(string $administrationId, bool $mustPost): ?JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim($administrationId);
		try {
			$allowed = ($administrationId !== '' && $this->context->canAccess(administrationId: $administrationId) === true);
			if ($allowed === true && $mustPost === true) {
				$allowed = $this->context->canPostJournalEntry(administrationId: $administrationId);
			}
		} catch (Throwable $e) {
			$this->logger->error('AdministrationTemplateController: failed to check access', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to check access'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			// Masked: never confirm that an administration exists.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end refusal()
}//end class
