<?php

/**
 * BADO Controleprotocol Controller
 *
 * Tier-3 BADO (Besluit Accountantscontrole Decentrale Overheden) audit
 * aggregation API (REQ-006, REQ-007). Exposes a single GET endpoint that
 * returns the server-computed per-topic finding aggregation and the
 * mechanically-derived proposed opinion for one Controleprotocol, used by the
 * Audit Verklaringen view to render the aggregation table + proposed opinion
 * before the auditor signs. The endpoint is available to any authenticated user
 * (#[NoAdminRequired]); the protocol id is validated AND the caller is authorised
 * for the protocol's owning organisation here
 * (AdministrationContextService::canAccess(), ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph used to say the id "is validated and reads are delegated to
 * OpenRegister's ObjectService, which enforces multitenancy / RBAC, so no
 * cross-organisation audit data leaks". Validation was a character-class regex,
 * no organisation term reached OpenRegister, and a schema with no
 * `authorization` block grants everything — so the seven-schema audit bundle,
 * including the proposed audit opinion, was readable for any protocol id.
 * The aggregation + opinion are read-only
 * (computed) — the protocol/finding/verklaring lifecycle is driven by the
 * OpenRegister object lifecycle, not by this controller.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AccountantsdossierExportService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/bado/aggregation — per-topic finding aggregation + proposed opinion.
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 */
class BadoControleprotocolController extends Controller {
	/**
	 * Constructor for the BadoControleprotocolController.
	 *
	 * @param IRequest $request The request object.
	 * @param BadoControleprotocolService $service The BADO aggregation + opinion service.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 * @param IUserSession $userSession The user session for authentication guard.
	 * @param AccountantsdossierExportService $exporter The accountantsdossier PDF/A exporter (Task 16).
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly BadoControleprotocolService $service,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly AccountantsdossierExportService $exporter,
		private readonly AdministrationContextService $context,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return the per-topic finding aggregation + proposed opinion (REQ-006, REQ-007).
	 *
	 * Query parameters:
	 *  - protocol_id (required) the Controleprotocol id to aggregate.
	 *
	 * Returns HTTP 200 with { protocolId, materialityAmount, topics, proposedOpinion }
	 * on success; HTTP 400 on a missing/malformed parameter; HTTP 500 (without a
	 * stack trace) on an unexpected aggregation failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	#[NoAdminRequired]
	public function aggregation(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$protocolId = trim((string)$this->request->getParam('protocol_id', ''));

		if ($protocolId === '') {
			return new JSONResponse(
				['error' => 'protocol_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Reject obviously malformed identifiers before touching the data layer
		// (REQ-001) — protocol identifiers are short slugs / UUIDs.
		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $protocolId) !== 1) {
			return new JSONResponse(
				['error' => 'protocol_id must be a valid protocol identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// ADR-005 / REQ-MA-001. The regex above validates the id's SHAPE; this
		// authorises the caller for the tenant that owns it. The protocol's
		// organisationId is the only tenant field in the bundle — the six child
		// schemas carry none and hang off the protocol's FK.
		$error = $this->requireAccessibleProtocol(protocolId: $protocolId);
		if ($error !== null) {
			return $error;
		}

		try {
			$result = $this->service->computeAggregation(protocolId: $protocolId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolController: failed to compute audit aggregation',
				['protocolId' => $protocolId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to compute audit aggregation'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end aggregation()

	/**
	 * Export the accountantsdossier bundle for a Controleprotocol (REQ-010, Task 16).
	 *
	 * Builds the deterministic 7-schema bundle (Controleprotocol header +
	 * ToleranceMatrix + Materialiteit + AuditSample + AuditFinding +
	 * VerklaringDraft + SiSaAssurance), assembles the PDF/A-oriented HTML
	 * summary + manifest with SHA-256 anchor + ISO 8601 timestamp + retention
	 * marker, writes the bundle to a ZIP archive in the system temp directory
	 * and delegates the PKIO signature to the configured signer.
	 *
	 * Query parameters:
	 *  - protocol_id (required) the Controleprotocol id to export.
	 *
	 * Returns HTTP 200 with the export envelope on success; HTTP 400 on a
	 * missing/malformed parameter; HTTP 404 when the protocol cannot be
	 * resolved; HTTP 500 (without a stack trace) on an unexpected failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	#[NoAdminRequired]
	public function exportAccountantsdossier(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$protocolId = trim((string)$this->request->getParam('protocol_id', ''));

		if ($protocolId === '') {
			return new JSONResponse(
				['error' => 'protocol_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $protocolId) !== 1) {
			return new JSONResponse(
				['error' => 'protocol_id must be a valid protocol identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// ADR-005 / REQ-MA-001. The regex above validates the id's SHAPE; this
		// authorises the caller for the tenant that owns it. The protocol's
		// organisationId is the only tenant field in the bundle — the six child
		// schemas carry none and hang off the protocol's FK.
		$error = $this->requireAccessibleProtocol(protocolId: $protocolId);
		if ($error !== null) {
			return $error;
		}

		try {
			$envelope = $this->exporter->exportDossier(protocolId: $protocolId);
		} catch (\RuntimeException $e) {
			$this->logger->info(
				'BadoControleprotocolController: accountantsdossier export rejected',
				['protocolId' => $protocolId, 'reason' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Accountantsdossier not available for this protocol'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolController: failed to export accountantsdossier',
				['protocolId' => $protocolId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to export accountantsdossier'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($envelope, Http::STATUS_OK);
	}//end exportAccountantsdossier()

	/**
	 * Authorise the caller for the tenant that owns a Controleprotocol (ADR-005).
	 *
	 * A protocol that does not exist and a protocol belonging to an organisation
	 * the caller has no membership for are deliberately indistinguishable: both
	 * answer 404, so the endpoint never confirms the protocol exists.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return JSONResponse|null A 404 response when refused, null when allowed.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	private function requireAccessibleProtocol(string $protocolId): ?JSONResponse {
		try {
			$organisationId = $this->service->organisationIdFor(protocolId: $protocolId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolController: failed to resolve protocol tenant',
				['protocolId' => $protocolId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Controleprotocol not found'], Http::STATUS_NOT_FOUND);
		}

		// A protocol with no organisationId is refused: canAccess() already
		// fails closed on '' (AdministrationContextService:220).
		if ($organisationId === null || $this->context->canAccess(administrationId: $organisationId) === false) {
			return new JSONResponse(['error' => 'Controleprotocol not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleProtocol()
}//end class
