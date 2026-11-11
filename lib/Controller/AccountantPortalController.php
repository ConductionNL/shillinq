<?php

/**
 * Accountant Portal Controller
 *
 * The in-app accountant-portal surface (REQ-ACP-001..004): a scoped
 * multi-client dashboard plus a one-click handover pack, both built on the
 * existing administratie-aware RBAC (AdministrationContextService, ADR-022 OR
 * scoping) rather than a parallel auth system.
 *
 *  - GET /api/accountant/dashboard                          the authenticated
 *    user's accessible clients with a per-client status card (period-close
 *    state, BTW filing status + deadline, missing documents, open/attention
 *    items) — REQ-ACP-002.
 *  - GET /api/accountant/administrations/{id}/handover-pack  a ZIP bundling the
 *    journal export, BTW-overzicht, trial balance and general ledger for one
 *    administration, reusing the existing report generators via
 *    ReportGenerationService (REQ-ACP-004). No new document renderer is added.
 *
 * Every endpoint is available to any authenticated user (#[NoAdminRequired]);
 * the administration scope is validated against the user's
 * AdministrationMembership records exactly like AdministrationExportController
 * — a non-member is masked as a 404 (never 403), so the existence of other
 * tenants' data is not disclosed (REQ-ACP-003, mirrors REQ-MA-001). This is the
 * security headline of this change: an accountant with a grant for client A
 * must never be able to reach client B's dashboard card or handover pack.
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
 * @spec openspec/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\AccountantDashboardService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Scoped accountant dashboard + handover-pack export.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */
class AccountantPortalController extends Controller {
	/**
	 * The report types bundled into a handover pack, with the format requested
	 * from each existing generator (falls back to the generator's own preferred
	 * format when unsupported — see ReportGenerationService::generate()).
	 *
	 * @var array<int,array{reportType:string,format:string}>
	 */
	private const HANDOVER_REPORTS = [
		['reportType' => 'xaf', 'format' => 'xml'],
		['reportType' => 'trial-balance', 'format' => 'csv'],
		['reportType' => 'general-ledger', 'format' => 'csv'],
		['reportType' => 'vat-return', 'format' => 'xml'],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AdministrationContextService $context Administratie-aware RBAC context (IDOR guard).
	 * @param AccountantDashboardService $dashboard Per-client status aggregation.
	 * @param ReportGenerationService $reports The existing report-generation orchestration (reused, not re-implemented).
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly AccountantDashboardService $dashboard,
		private readonly ReportGenerationService $reports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the authenticated user's accountant dashboard (REQ-ACP-001, REQ-ACP-002).
	 *
	 * JUSTIFY (security-endpoint-guards REQ-001): this method takes no
	 * request-supplied administration id — `AccountantDashboardService::
	 * buildDashboard()` builds one card per entry of
	 * `AdministrationContextService::buildContext()['administrations']`,
	 * which is derived purely from the authenticated session uid's own
	 * `AdministrationMembership` records (verified by reading
	 * `AdministrationContextService::buildContext()`). There is no
	 * client-supplied identifier a caller could substitute to reach another
	 * tenant's dashboard card, so no additional per-object guard applies
	 * beyond the authentication check below.
	 *
	 * @return JSONResponse 200 with the dashboard; 401 when no user is authenticated.
	 *
	 * @spec openspec/specs/accountant-portal/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function dashboard(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->dashboard->buildDashboard();
		} catch (\Throwable $e) {
			$this->logger->error(
				'AccountantPortalController: failed to build dashboard',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to resolve accountant dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($data, Http::STATUS_OK);
	}//end dashboard()

	/**
	 * Stream a ZIP handover pack for one administration (REQ-ACP-004): journal
	 * export (general ledger), BTW-overzicht (vat-return), trial balance and the
	 * XAF auditfile, each rendered by the existing report generators.
	 *
	 * @param string $id The administration id.
	 *
	 * @return Response 200 with the ZIP bytes; 400 bad id; 401 anonymous;
	 *                  404 masked non-membership; 500 when no report could be rendered.
	 *
	 * @spec openspec/specs/accountant-portal/spec.md
	 */
	#[NoAdminRequired]
	public function handoverPack(string $id): Response {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim($id);
		$refusal = $this->packRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$period = trim((string)$this->request->getParam('period', (string)gmdate('Y')));

		return $this->packResponse(scope: new HandoverScope(administrationId: $administrationId, period: $period));
	}//end handoverPack()

	/**
	 * The 400 for a malformed id, then the membership answer.
	 *
	 * @param string $administrationId The trimmed path id.
	 *
	 * @return JSONResponse|null The refusal, or null for a member.
	 */
	private function packRefusal(string $administrationId): ?JSONResponse {
		if ($this->isValidIdentifier(identifier: $administrationId) === false) {
			return new JSONResponse(['error' => 'Invalid administration id'], Http::STATUS_BAD_REQUEST);
		}

		return $this->membershipRefusal(administrationId: $administrationId);
	}//end packRefusal()

	/**
	 * The masked 404 for a non-member — never confirm administration existence
	 * (REQ-ACP-003) — or a 500 when membership cannot be checked.
	 *
	 * @param string $administrationId The well-formed administration id.
	 *
	 * @return JSONResponse|null The refusal, or null for a member.
	 */
	private function membershipRefusal(string $administrationId): ?JSONResponse {
		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('AccountantPortalController: failed to check handover-pack access', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to resolve handover pack'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end membershipRefusal()

	/**
	 * Build the pack and stream it; a failure, or a pack without any report, is a 500.
	 *
	 * @param HandoverScope $scope The authorised administration and period.
	 *
	 * @return Response
	 */
	private function packResponse(HandoverScope $scope): Response {
		try {
			$zipBytes = $this->buildPack(scope: $scope);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AccountantPortalController: handover pack generation failed',
				['administrationId' => $scope->administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Handover pack generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($zipBytes === null) {
			return new JSONResponse(['error' => 'No reports could be generated for this administration'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse($zipBytes, 'handover-pack-' . $scope->administrationId . '-' . $scope->period . '.zip', 'application/zip');
	}//end packResponse()

	/**
	 * Render every handover-pack report through the existing generators and zip
	 * the resulting files. Each report is generated independently and best-effort
	 * — a single generator failure never blocks the other reports.
	 *
	 * @param HandoverScope $scope The administration and the period passed to each generator.
	 *
	 * @return string|null The ZIP bytes, or null when no report could be rendered.
	 *
	 * @throws RuntimeException When the ZIP extension is unavailable.
	 */
	private function buildPack(HandoverScope $scope): ?string {
		$tmp = self::packFile();
		$zip = self::openArchive(tmp: $tmp);
		$added = $this->addReports(zip: $zip, scope: $scope);
		$zip->close();

		$bytes = (string)file_get_contents($tmp);
		if (file_exists($tmp) === true) {
			unlink($tmp);
		}

		if ($added === 0) {
			return null;
		}

		return $bytes;
	}//end buildPack()

	/**
	 * A fresh temp file for the archive.
	 *
	 * @return string The path.
	 *
	 * @throws RuntimeException When the ZIP extension is unavailable or no temp file can be made.
	 */
	private static function packFile(): string {
		if (class_exists(ZipArchive::class) === false) {
			throw new RuntimeException('ZIP extension unavailable');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'shillinq-handover-');
		if ($tmp === false) {
			throw new RuntimeException('Could not allocate a temp file for the handover pack');
		}

		return $tmp;
	}//end packFile()

	/**
	 * Open the archive over the temp file; on failure the temp file is removed.
	 *
	 * @param string $tmp The temp file.
	 *
	 * @return ZipArchive
	 *
	 * @throws RuntimeException When the archive cannot be opened.
	 */
	private static function openArchive(string $tmp): ZipArchive {
		$zip = new ZipArchive();
		if ($zip->open($tmp, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) === true) {
			return $zip;
		}

		if (file_exists($tmp) === true) {
			unlink($tmp);
		}

		throw new RuntimeException('Could not open the handover-pack ZIP archive');
	}//end openArchive()

	/**
	 * Add every report that renders; one that does not is skipped.
	 *
	 * @param ZipArchive    $zip   The open archive.
	 * @param HandoverScope $scope The administration and period.
	 *
	 * @return int The number of reports added.
	 */
	private function addReports(ZipArchive $zip, HandoverScope $scope): int {
		$added = 0;
		foreach (self::HANDOVER_REPORTS as $spec) {
			$entry = $this->renderReportEntry(spec: $spec, scope: $scope);
			if ($entry !== null) {
				$zip->addFromString($entry['fileName'], $entry['content']);
				$added++;
			}
		}

		return $added;
	}//end addReports()

	/**
	 * Generate one handover-pack report via the existing ReportGenerationService
	 * and resolve its stored bytes. Returns null (logged) on any failure so one
	 * bad generator never blocks the rest of the pack.
	 *
	 * @param array{reportType:string,format:string} $spec  The ReportCatalogue report-type id and requested format.
	 * @param HandoverScope                          $scope The administration and period.
	 *
	 * @return array{fileName:string,content:string}|null
	 */
	private function renderReportEntry(array $spec, HandoverScope $scope): ?array {
		$reportType = $spec['reportType'];
		$where = ['reportType' => $reportType, 'administrationId' => $scope->administrationId];
		$record = $this->reports->generate($reportType, $scope->period, $scope->administrationId, $spec['format']);
		if (isset($record['error']) === true) {
			$this->logger->warning('AccountantPortalController: handover-pack report failed', $where + ['error' => $record['error']]);
			return null;
		}

		$fileName = (string)($record['fileName'] ?? ($reportType . '.' . $spec['format']));
		$content = $this->storedContent(record: $record, where: $where);
		if ($content === null) {
			return null;
		}

		return ['fileName' => $reportType . '/' . $fileName, 'content' => $content];
	}//end renderReportEntry()

	/**
	 * The stored bytes of a generated report; a missing or unreadable file is a warning.
	 *
	 * @param array<string,mixed>                              $record The generated report record.
	 * @param array{reportType:string,administrationId:string} $where  The report type and administration (log context).
	 *
	 * @return string|null
	 */
	private function storedContent(array $record, array $where): ?string {
		$recordId = (string)($record['id'] ?? '');
		$file = null;
		if ($recordId !== '') {
			$file = $this->reports->resolveFile($recordId);
		}

		if ($file === null) {
			$this->logger->warning('AccountantPortalController: handover-pack report file not resolvable', $where);
			return null;
		}

		return $this->fileContent(file: $file, reportType: $where['reportType']);
	}//end storedContent()

	/**
	 * A file's content; an unreadable one is a warning.
	 *
	 * @param File   $file       The stored report file.
	 * @param string $reportType The report type (log context).
	 *
	 * @return string|null
	 */
	private function fileContent(File $file, string $reportType): ?string {
		try {
			return $file->getContent();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AccountantPortalController: handover-pack report content unreadable',
				['reportType' => $reportType, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fileContent()

	/**
	 * Validate an administration identifier slug before touching the data layer.
	 *
	 * @param string $identifier The identifier to validate.
	 *
	 * @return bool True when the identifier is a safe short slug.
	 */
	private function isValidIdentifier(string $identifier): bool {
		return ($identifier !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $identifier) === 1);
	}//end isValidIdentifier()
}//end class
