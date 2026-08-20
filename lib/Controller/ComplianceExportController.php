<?php

/**
 * Compliance Export Controller.
 *
 * Backs the REQ-RAP-005 manifest entry `BookkeepingComplianceExport`.
 *
 *   GET /api/audit/export
 *        Query: from=YYYY-MM-DD, to=YYYY-MM-DD,
 *                format=csv|json, scope=all|subject,
 *                actor=<uid> (optional, scope=subject)
 *        → 200 with CSV (text/csv) or JSON envelope.
 *
 * Authorization (REQ-RAP-005):
 *  - Endpoint is `#[NoAdminRequired]` — admin posture is the NC
 *    SecurityMiddleware default per [[nc-security-defaults]]; without
 *    the attribute the route would be admin-only and the manifest
 *    page would 403 for the auditor group.
 *  - Caller MUST be in the `auditor` group OR be a Nextcloud admin.
 *    Non-auditor / non-admin → 403 Forbidden.
 *  - Anonymous → 401.
 *  - Cross-tenant access is masked as 404 by AdministrationContextService
 *    (not used here — exports are tenant-agnostic per REQ-RAP-005).
 *
 * The export request itself is recorded in the OR audit-trail by
 * passing through a synthetic `export_request` event when the OR
 * AuditTrailService::recordEvent() method is available, so REQ-RAP-005
 * scenario 3 ("Export audit trail is itself audited") is honoured.
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
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ComplianceExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * RBAC-scoped compliance export endpoint (REQ-RAP-005).
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ComplianceExportController extends Controller {

	/**
	 * Group whose members may invoke the compliance export per REQ-RAP-005.
	 *
	 * @var string
	 */
	private const AUDITOR_GROUP = 'auditor';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ComplianceExportService $complianceExportService Read-filter-render service.
	 * @param IUserSession $userSession Session guard.
	 * @param IGroupManager $groupManager Group RBAC.
	 * @param ContainerInterface $container DI container — OR
	 *                                      audit-trail service is
	 *                                      fetched lazily for the
	 *                                      REQ-RAP-005 scenario 3
	 *                                      export-of-export logging.
	 * @param LoggerInterface $logger Logger (no PII).
	 * @param IL10N $l10n Translation service for ADR-050 error-response messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ComplianceExportService $complianceExportService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Generate and stream the compliance export.
	 *
	 * GET /api/audit/export
	 *
	 * @return Response 200 with CSV/JSON; 400 on validation; 401 anonymous;
	 *                  403 non-auditor; 500 without stack trace.
	 *
	 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function export(): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		$adminBypass = $this->groupManager->isAdmin($uid);
		$isAuditor = $this->groupManager->isInGroup($uid, self::AUDITOR_GROUP);
		if ($adminBypass !== true && $isAuditor !== true) {
			return new JSONResponse(
				['error' => 'Compliance export requires the auditor group (REQ-RAP-005)'],
				Http::STATUS_FORBIDDEN
			);
		}

		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		$scope = (string)$this->request->getParam('scope', ComplianceExportService::SCOPE_ALL);
		$format = (string)$this->request->getParam('format', ComplianceExportService::FORMAT_CSV);
		$actor = $this->request->getParam('actor');
		if (is_string($actor) === true && $actor !== '') {
			$actorFilter = $actor;
		} else {
			$actorFilter = null;
		}

		try {
			$envelope = $this->complianceExportService->generateExport(
				from:        $from,
				to:          $to,
				scope:       $scope,
				format:      $format,
				actorFilter: $actorFilter,
			);
		} catch (RuntimeException $e) {
			$this->logger->error('ComplianceExportController.export failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to generate the compliance export'),
					'error' => 'compliance-export-invalid-request',
				],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ComplianceExportController: failed to generate export',
				[
					'from' => $from,
					'to' => $to,
					'scope' => $scope,
					'format' => $format,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not generate compliance export'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		$this->logExportRequest(envelope: $envelope, uid: $uid);

		if ($format === ComplianceExportService::FORMAT_CSV) {
			$csv = $this->complianceExportService->renderCsv(envelope: $envelope);
			return new DataDisplayResponse(
				data:        $csv,
				statusCode:  Http::STATUS_OK,
				headers:     [
					'Content-Type' => 'text/csv; charset=utf-8',
					'Content-Disposition' => sprintf(
						'attachment; filename="shillinq-audit-export-%s_%s.csv"',
						preg_replace('/[^0-9-]/', '', $envelope['from']),
						preg_replace('/[^0-9-]/', '', $envelope['to'])
					),
				]
			);
		}

		return new JSONResponse($envelope, Http::STATUS_OK);
	}//end export()

	/**
	 * Record the export request itself in the OR audit-trail per
	 * REQ-RAP-005 scenario 3 — the export operation is auditable on
	 * the same hash-chained channel it queries.
	 *
	 * Best-effort: if OR's audit-trail service does not expose a
	 * recordEvent / log method, we log to the app logger instead so
	 * the request is not silently lost (the controller still succeeds).
	 *
	 * @param array<string,mixed> $envelope Export envelope.
	 * @param string $uid Caller UID.
	 *
	 * @return void
	 */
	private function logExportRequest(array $envelope, string $uid): void {
		$payload = [
			'action' => 'export_request',
			'actor' => $uid,
			'timestamp' => $envelope['generatedAt'],
			'scope' => $envelope['scope'],
			'format' => $envelope['format'],
			'from' => $envelope['from'],
			'to' => $envelope['to'],
			'eventCount' => $envelope['eventCount'],
			'actorFilter' => $envelope['actorFilter'],
			'requirementId' => 'REQ-RAP-005',
		];

		try {
			$auditService = $this->container->get('OCA\OpenRegister\Service\AuditTrailService');
			if (method_exists($auditService, 'recordEvent') === true) {
				$auditService->recordEvent($payload);
				return;
			}

			if (method_exists($auditService, 'log') === true) {
				$auditService->log($payload);
				return;
			}
		} catch (\Throwable $e) {
			// Fall through to logger fallback below.
		}

		$this->logger->info('shillinq.compliance.export_request', $payload);

	}//end logExportRequest()
}//end class
