<?php

/**
 * Shillinq DBA Compliance Marker controller.
 *
 * Server-authoritative endpoints for the DBA capability:
 *  - POST /api/dba/intake               start/save intake (REQ-DBA-001/-003)
 *  - POST /api/dba/intake/score         compute score on draft input (T19)
 *  - POST /api/dba/vbar/check           VBAR uurtarief-toets (REQ-DBA-016, T17)
 *  - POST /api/dba/wba/upload           upload WBA-uitkomst (REQ-DBA-013, T26)
 *  - POST /api/dba/beeindiging          mark opdracht ended (REQ-DBA-018, T27)
 *  - POST /api/dba/inhuur-intake        opdrachtgever-side mirror (REQ-DBA-010, T25)
 *  - POST /api/dba/evidence/consent     AVG opt-in for email archive (REQ-DBA-012, T21)
 *  - POST /api/dba/mode                 set compliance-mode (REQ-DBA-000, T22)
 *  - GET  /api/dba/audit-report/{id}    audit-rapport PDF (REQ-DBA-008, T23)
 *  - POST /api/dba/tussenkomst          configure intermediair-mode (REQ-DBA-017, T28)
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
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Enums\DBAConstants;
use OCA\Shillinq\Guard\DBAOpdrachtGuard;
use OCA\Shillinq\Guard\DBAScoreCalculator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DBA Compliance Marker endpoint façade.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Pre-existing debt
 *     (issue #506): DBA compliance scoring/audit-report surface area is
 *     inherent domain complexity; deferred pending a dedicated refactor.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     Pre-existing debt
 *     (issue #506), see above.
 * @SuppressWarnings(PHPMD.ElseExpression)           Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   security-endpoint-guards
 *     REQ-001: this change adds `AdministrationContextService` (the
 *     per-object tenant guard closing the cross-tenant IDOR on
 *     ensureAdministrationAccess()) and its `IGroupManager` admin-bypass
 *     collaborator, pushing coupling from 16 to 19 — one over this app's
 *     already-raised threshold (13->19, issue #506). Removing either
 *     collaborator removes the guard, so this is not a rule misfire to
 *     work around; it is the real, minimal cost of the fix. Seven
 *     controllers now duplicate the same `groupManager->isAdmin()`
 *     bypass check (BookingNotificationController, CBSSubmissionController,
 *     ComplianceExportController, CalendarController, DBAController,
 *     InventoryMobileScannerController, InventoryScanController);
 *     centralising it into `AdministrationContextService` would remove
 *     this collaborator here too, but is a cross-cutting refactor left
 *     for a dedicated follow-up rather than folded into this security fix.
 */
class DBAController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Nextcloud request.
	 * @param ContainerInterface $container DI container for ObjectService.
	 * @param IAppConfig $appConfig App config.
	 * @param IUserSession $userSession User session.
	 * @param DBAScoreCalculator $scoreCalc Score calculator guard.
	 * @param DBAOpdrachtGuard $assignmentGuard Save-precondition guard.
	 * @param DBAVbarMonitorService $vbarMonitor VBAR monitor service.
	 * @param LoggerInterface $logger Logger.
	 * @param AdministrationContextService $administrationContext Per-administration IDOR guard (ADR-005 Rule 3).
	 * @param IGroupManager $groupManager Nextcloud admin bypass for the administration guard.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly DBAScoreCalculator $scoreCalc,
		private readonly DBAOpdrachtGuard $assignmentGuard,
		private readonly DBAVbarMonitorService $vbarMonitor,
		private readonly LoggerInterface $logger,
		private readonly AdministrationContextService $administrationContext,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Score an intake draft without persisting it (REQ-DBA-003).
	 *
	 * @return JSONResponse The score breakdown.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function scoreIntake(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->jsonBody();
		$total = $this->scoreCalc->computeTotal($body);
		$band = DBAConstants::bandFromScore($total);

		return new JSONResponse(
			[
				'totalScore' => $total,
				'riskLevel' => $band,
				'authorityRelationship' => $this->scoreCalc->subtotalGezag($body),
				'personalLabour' => $this->scoreCalc->subtotalArbeid($body),
				'financialRisk' => $this->scoreCalc->subtotalFinancieel($body),
				'deliverooCriteria' => $this->scoreCalc->subtotalDeliveroo($body),
			]
		);
	}//end scoreIntake()

	/**
	 * Save a new intake + update its parent opdracht's intakeStatus (REQ-DBA-001).
	 *
	 * @return JSONResponse The persisted intake + updated opdracht.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function saveIntake(): JSONResponse {
		$body = $this->jsonBody();
		$assignmentId = (string)($body['assignmentId'] ?? '');
		if ($assignmentId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);

		$total = $this->scoreCalc->computeTotal($body);
		$band = DBAConstants::bandFromScore($total);

		$body['totalScore'] = $total;
		$body['interpretation'] = $band;
		$body['filledBy'] = (string)(($this->userSession->getUser()?->getUID()) ?? '');
		$body['filledOn'] ??= (new DateTimeImmutable())->format('Y-m-d');
		$body['administrationId'] = (string)($assignment['administrationId'] ?? '');

		try {
			$intake = $os->saveObject(object: $body, register: $register, schema: 'DBAIntake');
		} catch (Throwable $e) {
			$this->logger->error('DBA saveIntake failed', ['exception' => $e->getMessage()]);
			return $this->error(message: 'Opslaan intake mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (($body['abbreviatedType'] ?? false) === true) {
			$riskLevel = 'ABBREVIATED_LOW_THRESHOLD';
		} else {
			$riskLevel = $band;
		}

		$assignment['intakeStatus'] = 'INTAKE_COMPLETED';
		$assignment['intakeDate'] = $body['filledOn'];
		$assignment['actueleRisicoscore'] = $total;
		$assignment['riskLevel'] = $riskLevel;
		try {
			$os->saveObject(object: $assignment, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			$this->logger->error('DBA opdracht update failed', ['exception' => $e->getMessage()]);
		}

		return new JSONResponse(['intake' => $intake, 'opdracht' => $assignment]);
	}//end saveIntake()

	/**
	 * Run a VBAR uurtarief-toets on a single factuur line (REQ-DBA-016, T17).
	 *
	 * @return JSONResponse The assessment result.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function vbarCheck(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->jsonBody();
		$amountCents = (int)($body['bedragCents'] ?? 0);
		$hours = (float)($body['hours'] ?? 0.0);
		$assignmentId = (string)($body['assignmentId'] ?? '');
		$administrationId = (string)($body['administrationId'] ?? '');
		$invoiceId = (string)($body['invoiceId'] ?? '');

		$result = $this->vbarMonitor->assess(amountCents: $amountCents, hours: $hours, administrationId: $administrationId);

		if ($result['result'] !== DBAVbarMonitorService::RESULT_OK
			&& $assignmentId !== ''
			&& $invoiceId !== ''
		) {
			$this->vbarMonitor->emitFlag(
				assignmentId: $assignmentId,
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				hourlyRateCents: (int)($result['uurtariefCents'] ?? 0),
				vbarGrensCents: (int)($result['vbarGrensCents'] ?? 0),
			);
		}

		return new JSONResponse($result);
	}//end vbarCheck()

	/**
	 * Upload a WBA-uitkomst for an opdracht (REQ-DBA-013, T26).
	 *
	 * @return JSONResponse The updated opdracht.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function uploadWba(): JSONResponse {
		$body = $this->jsonBody();
		$assignmentId = (string)($body['assignmentId'] ?? '');
		$result = (string)($body['wbaAssessmentResult'] ?? '');
		if ($assignmentId === '' || $result === '') {
			return $this->error(message: 'opdrachtId + wbaBeoordelingResultaat vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);

		$assignment['wbaAssessmentResult'] = $result;
		$assignment['wbaValidTo'] = (new DateTimeImmutable())
			->modify('+' . DBAConstants::WBA_GELDIGHEID_DAGEN . ' days')->format('Y-m-d');
		try {
			$updated = $os->saveObject(object: $assignment, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			return $this->error(message: 'WBA-upload mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['opdracht' => $updated]);
	}//end uploadWba()

	/**
	 * Mark opdracht beeindigd + start retentie-klok (REQ-DBA-018, T27).
	 *
	 * @return JSONResponse The updated opdracht.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function beeindigen(): JSONResponse {
		$body = $this->jsonBody();
		$assignmentId = (string)($body['assignmentId'] ?? '');
		$endDate = (string)($body['actualEndDate'] ?? '');
		if ($assignmentId === '' || $endDate === '') {
			return $this->error(message: 'opdrachtId + feitelijkeEindDatum vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);

		$assignment['intakeStatus'] = 'ENDED';
		$assignment['actualEndDate'] = $endDate;
		$retention = $this->assignmentGuard->computeRetentieDeadline($endDate);
		if ($retention !== null) {
			$assignment['retentionDeadline'] = $retention;
		}

		try {
			$updated = $os->saveObject(object: $assignment, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			return $this->error(message: 'Beeindiging mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['opdracht' => $updated, 'retentionDeadline' => $retention]);
	}//end beeindigen()

	/**
	 * Configure compliance-mode (REQ-DBA-000, T22).
	 *
	 * @return JSONResponse The persisted mode.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function setMode(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->jsonBody();
		$mode = (string)($body['mode'] ?? '');
		$administrationId = (string)($body['administrationId'] ?? '');
		if (in_array(
			$mode,
			[
				DBAConstants::COMPLIANCE_MODE_SOFT,
				DBAConstants::COMPLIANCE_MODE_HARD,
				DBAConstants::COMPLIANCE_MODE_INTERMEDIAIR,
			],
			true
		) === false
		) {
			return $this->error(message: 'Ongeldige compliance-mode', code: Http::STATUS_BAD_REQUEST);
		}

		$key = DBAConstants::CONFIG_PREFIX . 'compliance_mode';
		if ($administrationId !== '') {
			$key .= '.' . $administrationId;
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $mode);
		return new JSONResponse(['mode' => $mode, 'administrationId' => $administrationId]);
	}//end setMode()

	/**
	 * Configure intermediair (tussenkomst) mode on an opdracht (REQ-DBA-017, T28).
	 *
	 * @return JSONResponse The updated opdracht.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function setTussenkomstMode(): JSONResponse {
		$body = $this->jsonBody();
		$assignmentId = (string)($body['assignmentId'] ?? '');
		$enabled = (bool)($body['intermediaryMode'] ?? false);
		if ($assignmentId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);
		$assignment['intermediaryMode'] = $enabled;
		try {
			$updated = $os->saveObject(object: $assignment, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			return $this->error(message: 'Tussenkomst-mode opslaan mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['opdracht' => $updated]);
	}//end setTussenkomstMode()

	/**
	 * Save the email-archive AVG opt-in on the evidence-dossier (REQ-DBA-012, T21).
	 *
	 * @return JSONResponse The updated dossier.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function evidenceConsent(): JSONResponse {
		$body = $this->jsonBody();
		$dossierId = (string)($body['dossierId'] ?? '');
		$optIn = (bool)($body['optIn'] ?? false);
		$consentId = (string)($body['consentRecordId'] ?? '');
		if ($dossierId === '') {
			return $this->error(message: 'dossierId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$dossier = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAEvidenceDossier', id: $dossierId);
		if ($dossier === null) {
			return $this->error(message: 'Dossier niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $dossier);
		$dossier['emailArchiveOptIn'] = $optIn;
		if ($optIn === true && $consentId !== '') {
			$dossier['emailArchiveConsentRecordId'] = $consentId;
		}

		try {
			$updated = $os->saveObject(object: $dossier, register: $register, schema: 'DBAEvidenceDossier');
		} catch (Throwable $e) {
			return $this->error(message: 'Consent opslaan mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['dossier' => $updated]);
	}//end evidenceConsent()

	/**
	 * Save an opdrachtgever-side inhuur-intake (REQ-DBA-010, T25).
	 *
	 * Sets DBAOpdracht.perspective = 'CLIENT' before delegating to saveIntake.
	 *
	 * @return JSONResponse The result.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function inhuurIntake(): JSONResponse {
		$body = $this->jsonBody();
		$assignmentId = (string)($body['assignmentId'] ?? '');
		if ($assignmentId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);
		$assignment['perspective'] = 'CLIENT';
		try {
			$os->saveObject(object: $assignment, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			$this->logger->warning('Inhuur-intake perspectief update failed', ['exception' => $e->getMessage()]);
		}

		return $this->saveIntake();
	}//end inhuurIntake()

	/**
	 * Generate an audit-rapport PDF stub for an opdracht (REQ-DBA-008, T23).
	 *
	 * The current implementation returns a structured JSON-equivalent (TBD: render
	 * via openregister or docudesk PDF-pipeline). The SHA-256 hash of the payload
	 * is appended to the response for audit-trail recording.
	 *
	 * @param string $assignmentId Path parameter; FK to the DBAOpdracht.
	 *
	 * @return JSONResponse|DataDownloadResponse The audit-rapport payload.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function auditReport(string $assignmentId): JSONResponse|DataDownloadResponse {
		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$assignment = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $assignmentId);
		if ($assignment === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(assignment: $assignment);

		$intake = null;
		try {
			$intakeRows = $os->setRegister($register)->setSchema('DBAIntake')->findAll(
				[
					'filters' => ['assignmentId' => $assignmentId],
					'limit' => 1,
				]
			);
			foreach ($intakeRows as $row) {
				if (is_array($row) === true) {
					$intake = $row;
				} elseif (method_exists($row, 'getObject') === true) {
					$intake = $row->getObject();
				} else {
					$intake = null;
				}

				break;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Audit-report intake fetch failed', ['exception' => $e->getMessage()]);
		}//end try

		$flags = [];
		try {
			$flagRows = $os->setRegister($register)->setSchema('DBARisicoflag')->findAll(
				[
					'filters' => ['assignmentId' => $assignmentId],
					'limit' => 500,
				]
			);
			foreach ($flagRows as $row) {
				if (is_array($row) === true) {
					$arr = $row;
				} elseif (method_exists($row, 'getObject') === true) {
					$arr = $row->getObject();
				} else {
					$arr = null;
				}

				if (is_array($arr) === true) {
					$flags[] = $arr;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Audit-report flags fetch failed', ['exception' => $e->getMessage()]);
		}//end try

		$dossier = null;
		$dossierId = (string)($assignment['evidenceDossierId'] ?? '');
		if ($dossierId !== '') {
			$dossier = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAEvidenceDossier', id: $dossierId);
		}

		$payload = [
			'opdracht' => $assignment,
			'intake' => $intake,
			'flags' => $flags,
			'evidenceDossier' => $dossier,
			'generatedAt' => (new DateTimeImmutable())->format('c'),
			'fiscaleGrondslag' => 'Wet DBA, BW art. 7:610, Deliveroo-arrest HR 24-3-2023, AWR art. 52, VBAR (peil '
				. DBAConstants::VBAR_GRENS_PEILJAAR . ')',
		];
		$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return $this->error(message: 'Rapport-rendering mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$hash = hash('sha256', $json);
		$payload['sha256'] = $hash;

		// For now: emit JSON. T23 PDF-pipeline will land in the implementation cycle.
		$accept = (string)$this->request->getHeader('Accept');
		if (str_contains($accept, 'application/pdf') === true) {
			return new DataDownloadResponse(
				data: $json,
				filename: 'dba-audit-' . $assignmentId . '.json',
				contentType: 'application/json',
			);
		}

		return new JSONResponse($payload);
	}//end auditReport()

	/**
	 * Helper: parse JSON request body.
	 *
	 * @return array<string,mixed> The decoded payload.
	 */
	private function jsonBody(): array {
		$raw = (string)file_get_contents('php://input');
		if ($raw === '') {
			return [];
		}

		$data = json_decode($raw, true);
		if (is_array($data) === true) {
			return $data;
		}

		return [];
	}//end jsonBody()

	/**
	 * Helper: build an error response.
	 *
	 * @param string $message Human-readable message.
	 * @param int $code HTTP status code.
	 *
	 * @return JSONResponse An error response.
	 */
	private function error(string $message, int $code): JSONResponse {
		return new JSONResponse(['error' => $message], $code);
	}//end error()

	/**
	 * Helper: resolve the configured register slug.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Helper: resolve OR's ObjectService lazily.
	 *
	 * The native return type stays `?object` on purpose — this resolves the
	 * service by container id and the call sites duck-type it, which is what
	 * lets the unit tests inject a focused fake implementing only the four
	 * methods this controller actually calls rather than all 26 on the
	 * contract. Adding a runtime `instanceof` here would reject that fake and
	 * turn 22 passing tests into 503s without making production any safer.
	 *
	 * The DOCBLOCK is narrower than the native type, and that is the point:
	 * with a bare `object`, every `$os->saveObject(...)` in this class produced
	 * an unresolved type, which PHPStan then refused to accept as a
	 * JSONResponse payload. The responses type-checked only in the sense that
	 * nothing about them could be checked at all.
	 *
	 * @return ObjectServiceInterface|null The ObjectService, or null when OR is unavailable.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('DBA controller: ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end objectService()

	/**
	 * Helper: fetch an object by id with array coercion.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return array<string,mixed>|null The object payload, or null when not found.
	 */
	private function findEntityOrNull(object $objectService, string $register, string $schema, string $id): ?array {
		try {
			$entity = $objectService->setRegister($register)->setSchema($schema)->find($id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBA controller: find failed',
				[
					'schema' => $schema,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

		if (is_array($entity) === true) {
			/*
			 * @var array<string,mixed> $entity
			 */

			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				/*
				 * @var array<string,mixed> $data
				 */

				return $data;
			}
		}

		return null;
	}//end findEntityOrNull()

	/**
	 * Guard per-object authorization. The currently active user MUST belong to the
	 * object's administrationId — IDOR-protection per ADR-005 Rule 3.
	 *
	 * Previously a documented stub that logged and unconditionally permitted
	 * every caller regardless of membership (security-endpoint-guards,
	 * STUB verdict — flagged because a mechanical scan for a
	 * `ensure*`/`authorize*`/`require*`-shaped call cannot tell a real
	 * guard from one that never denies). Now enforces via
	 * `AdministrationContextService::canAccess()`, the same membership seam
	 * `BookingNotificationController::authorizeBookingAccess()` already
	 * uses, and throws on denial instead of merely logging.
	 *
	 * @param array<string,mixed> $assignment The fetched object.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When unauthenticated or not a member of
	 *                               the object's administration.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function ensureAdministrationAccess(array $assignment): void {
		$administrationId = (string)($assignment['administrationId'] ?? '');
		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logger->warning('DBA controller: anonymous request rejected');
			throw new OCSForbiddenException('Authentication required.');
		}

		// A Nextcloud admin bypasses the membership check, matching the
		// established pattern in
		// `BookingNotificationController::authorizeBookingAccess()` — the
		// admin account carries no `AdministrationMembership` of its own by
		// default (tests/e2e/ci-seed.sh), so without this bypass an admin
		// could not manage any administration's DBA records.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		// An absent/empty administrationId must DENY, never skip the check
		// — canAccess('') already returns false, but the explicit check
		// keeps that invariant visible at the call site rather than
		// relying on a side-effect of the service's own input validation.
		if ($administrationId === '' || $this->administrationContext->canAccess($administrationId) === false) {
			$this->logger->warning(
				'DBA controller: administration access denied',
				[
					'user' => $user->getUID(),
					'administrationId' => $administrationId,
				]
			);
			throw new OCSForbiddenException('Not authorized to access this administration.');
		}

		$this->logger->debug(
			'DBA controller: administration access granted',
			[
				'user' => $user->getUID(),
				'administrationId' => $administrationId,
			]
		);
	}//end ensureAdministrationAccess()
}//end class
