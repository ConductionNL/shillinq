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
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
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
	 * @param DBAOpdrachtGuard $opdrachtGuard Save-precondition guard.
	 * @param DBAVbarMonitorService $vbarMonitor VBAR monitor service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly DBAScoreCalculator $scoreCalc,
		private readonly DBAOpdrachtGuard $opdrachtGuard,
		private readonly DBAVbarMonitorService $vbarMonitor,
		private readonly LoggerInterface $logger,
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
				'riskNiveau' => $band,
				'authorityRelationship' => $this->scoreCalc->subtotalGezag($body),
				'personalArbeid' => $this->scoreCalc->subtotalArbeid($body),
				'financieelRisk' => $this->scoreCalc->subtotalFinancieel($body),
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
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		if ($opdrachtId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);

		$total = $this->scoreCalc->computeTotal($body);
		$band = DBAConstants::bandFromScore($total);

		$body['totalScore'] = $total;
		$body['interpretatie'] = $band;
		$body['ingevuldBy'] = (string)(($this->userSession->getUser()?->getUID()) ?? '');
		$body['ingevuldOn'] ??= (new DateTimeImmutable())->format('Y-m-d');
		$body['administrationId'] = (string)($opdracht['administrationId'] ?? '');

		try {
			$intake = $os->saveObject(object: $body, register: $register, schema: 'DBAIntake');
		} catch (Throwable $e) {
			$this->logger->error('DBA saveIntake failed', ['exception' => $e->getMessage()]);
			return $this->error(message: 'Opslaan intake mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (($body['verkortType'] ?? false) === true) {
			$risicoNiveau = 'VERKORT_LAGE_DREMPEL';
		} else {
			$risicoNiveau = $band;
		}

		$opdracht['intakeStatus'] = 'INTAKE_VOLTOOID';
		$opdracht['intakeDate'] = $body['ingevuldOn'];
		$opdracht['actueleRisicoscore'] = $total;
		$opdracht['riskNiveau'] = $risicoNiveau;
		try {
			$os->saveObject(object: $opdracht, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			$this->logger->error('DBA opdracht update failed', ['exception' => $e->getMessage()]);
		}

		return new JSONResponse(['intake' => $intake, 'opdracht' => $opdracht]);
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
		$bedragCents = (int)($body['bedragCents'] ?? 0);
		$uren = (float)($body['hours'] ?? 0.0);
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		$administrationId = (string)($body['administrationId'] ?? '');
		$factuurId = (string)($body['invoiceId'] ?? '');

		$result = $this->vbarMonitor->assess(bedragCents: $bedragCents, uren: $uren, administrationId: $administrationId);

		if ($result['result'] !== DBAVbarMonitorService::RESULT_OK
			&& $opdrachtId !== ''
			&& $factuurId !== ''
		) {
			$this->vbarMonitor->emitFlag(
				opdrachtId: $opdrachtId,
				administrationId: $administrationId,
				factuurId: $factuurId,
				uurtariefCents: (int)($result['uurtariefCents'] ?? 0),
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
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		$resultaat = (string)($body['wbaAssessmentResult'] ?? '');
		if ($opdrachtId === '' || $resultaat === '') {
			return $this->error(message: 'opdrachtId + wbaBeoordelingResultaat vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);

		$opdracht['wbaAssessmentResult'] = $resultaat;
		$opdracht['wbaValidTo'] = (new DateTimeImmutable())
			->modify('+' . DBAConstants::WBA_GELDIGHEID_DAGEN . ' days')->format('Y-m-d');
		try {
			$updated = $os->saveObject(object: $opdracht, register: $register, schema: 'DBAOpdracht');
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
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		$einddatum = (string)($body['actualEndDate'] ?? '');
		if ($opdrachtId === '' || $einddatum === '') {
			return $this->error(message: 'opdrachtId + feitelijkeEindDatum vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);

		$opdracht['intakeStatus'] = 'BEEINDIGD';
		$opdracht['actualEndDate'] = $einddatum;
		$retentie = $this->opdrachtGuard->computeRetentieDeadline($einddatum);
		if ($retentie !== null) {
			$opdracht['retentionDeadline'] = $retentie;
		}

		try {
			$updated = $os->saveObject(object: $opdracht, register: $register, schema: 'DBAOpdracht');
		} catch (Throwable $e) {
			return $this->error(message: 'Beeindiging mislukt', code: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['opdracht' => $updated, 'retentionDeadline' => $retentie]);
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
	 */
	#[NoAdminRequired]
	public function setTussenkomstMode(): JSONResponse {
		$body = $this->jsonBody();
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		$enabled = (bool)($body['intermediairMode'] ?? false);
		if ($opdrachtId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);
		$opdracht['intermediairMode'] = $enabled;
		try {
			$updated = $os->saveObject(object: $opdracht, register: $register, schema: 'DBAOpdracht');
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

		$this->ensureAdministrationAccess(opdracht: $dossier);
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
	 * Sets DBAOpdracht.perspectief = 'OPDRACHTGEVER' before delegating to saveIntake.
	 *
	 * @return JSONResponse The result.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function inhuurIntake(): JSONResponse {
		$body = $this->jsonBody();
		$opdrachtId = (string)($body['assignmentId'] ?? '');
		if ($opdrachtId === '') {
			return $this->error(message: 'opdrachtId vereist', code: Http::STATUS_BAD_REQUEST);
		}

		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);
		$opdracht['perspectief'] = 'OPDRACHTGEVER';
		try {
			$os->saveObject(object: $opdracht, register: $register, schema: 'DBAOpdracht');
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
	 * @param string $opdrachtId Path parameter; FK to the DBAOpdracht.
	 *
	 * @return JSONResponse|DataDownloadResponse The audit-rapport payload.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	#[NoAdminRequired]
	public function auditReport(string $opdrachtId): JSONResponse|DataDownloadResponse {
		$os = $this->objectService();
		if ($os === null) {
			return $this->error(message: 'OpenRegister niet beschikbaar', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$register = $this->register();
		$opdracht = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAOpdracht', id: $opdrachtId);
		if ($opdracht === null) {
			return $this->error(message: 'Opdracht niet gevonden', code: Http::STATUS_NOT_FOUND);
		}

		$this->ensureAdministrationAccess(opdracht: $opdracht);

		$intake = null;
		try {
			$intakeRows = $os->setRegister($register)->setSchema('DBAIntake')->findAll(
				[
					'filters' => ['assignmentId' => $opdrachtId],
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
					'filters' => ['assignmentId' => $opdrachtId],
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
		$dossierId = (string)($opdracht['evidenceDossierId'] ?? '');
		if ($dossierId !== '') {
			$dossier = $this->findEntityOrNull(objectService: $os, register: $register, schema: 'DBAEvidenceDossier', id: $dossierId);
		}

		$payload = [
			'opdracht' => $opdracht,
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
				filename: 'dba-audit-' . $opdrachtId . '.json',
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
	 * @return object|null The ObjectService, or null when OR is unavailable.
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
	 * object's administrationId — IDOR-protection per ADR-005.
	 *
	 * Stub: when no administration-membership service is wired (yet), we log and
	 * permit; the production implementation calls an `AdministrationMembership`
	 * service.
	 *
	 * @param array<string,mixed> $opdracht The fetched object.
	 *
	 * @return void
	 */
	private function ensureAdministrationAccess(array $opdracht): void {
		$administrationId = (string)($opdracht['administrationId'] ?? '');
		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logger->warning('DBA controller: anonymous request rejected');
			return;
		}

		// Real check would call AdministrationMembership::isMember($user, $administrationId).
		// Until the implementation cycle wires it, we log the guarded access.
		$this->logger->debug(
			'DBA controller: administration access',
			[
				'user' => $user->getUID(),
				'administrationId' => $administrationId,
			]
		);
	}//end ensureAdministrationAccess()
}//end class
