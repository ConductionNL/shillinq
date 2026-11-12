<?php

/**
 * Administration Export Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The streaming half of the Auditfile Financieel (XAF 3.2) export (REQ-MA-007 /
 * REQ-MA-011). Where {@see AdministrationController::exportScope()} returns only
 * a `{ format: 'xaf-3.2', ... }` scope descriptor, this controller resolves that
 * descriptor to real bytes: it runs {@see \OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator}
 * for a single administration and streams the resulting XAF document.
 *
 *  - GET /api/administrations/{id}/export        streams the XAF 3.2 file;
 *  - GET /api/administrations/{id}/export?full=1  streams a ZIP bundling the XAF
 *                                                plus the administration's
 *                                                attached NC-Files documents
 *                                                (referenced/streamed from
 *                                                Files — link, don't store).
 *
 * Every request is guarded exactly like `exportScope()`: any authenticated user
 * (#[NoAdminRequired]), but the administration scope is validated against the
 * user's membership via AdministrationContextService — a non-member is masked as
 * a 404 (never 403), so the existence of other tenants' data is not disclosed
 * (REQ-MA-001). The generator itself enforces `administrationId` isolation, so no
 * cross-administration row can appear in the stream.
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
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Streaming administration-scoped XAF 3.2 export.
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 */
class AdministrationExportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param mixed $request The request object (IRequest).
	 * @param AdministrationContextService $context Administratie-aware RBAC context service.
	 * @param ContainerInterface $container DI container — resolves the XAF generator.
	 * @param IRootFolder $rootFolder Nextcloud Files root (attachment bundle).
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		$request,
		private readonly AdministrationContextService $context,
		private readonly ContainerInterface $container,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Stream the XAF 3.2 audit file (or a ZIP bundle) for one administration.
	 *
	 * @param string $id The administration id.
	 *
	 * @return Response 200 with the XAF/ZIP bytes; 400 bad id; 401 anonymous;
	 *                  404 masked non-membership; 500 on generation failure.
	 *
	 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
	 */
	#[NoAdminRequired]
	public function exportXaf(string $id): Response {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim($id);
		$refusal = $this->exportRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$rendered = $this->renderedXaf(administrationId: $administrationId);
		if ($rendered instanceof JSONResponse) {
			return $rendered;
		}

		return $this->download(administrationId: $administrationId, rendered: $rendered);

	}//end exportXaf()

	/**
	 * The 400 for a malformed id, or the access refusal.
	 *
	 * @param string $administrationId The trimmed administration id.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may export.
	 *
	 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
	 */
	private function exportRefusal(string $administrationId): ?JSONResponse {
		if ($this->isValidIdentifier(identifier: $administrationId) === false) {
			return new JSONResponse(['error' => 'Invalid administration id'], Http::STATUS_BAD_REQUEST);
		}

		return $this->accessRefusal(administrationId: $administrationId);
	}//end exportRefusal()

	/**
	 * A failing membership check is a logged 500; a non-member is masked as
	 * 404 — never confirm administration existence (REQ-MA-001).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller is a member.
	 *
	 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
	 */
	private function accessRefusal(string $administrationId): ?JSONResponse {
		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationExportController: failed to check export access',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to resolve export'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end accessRefusal()

	/**
	 * Render the XAF for the requested period; a failure is a logged 500.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return GeneratedFile|JSONResponse
	 */
	private function renderedXaf(string $administrationId): GeneratedFile|JSONResponse {
		$context = $this->xafContext(administrationId: $administrationId);

		try {
			return $this->xafGenerator()->generate($context, 'xml');
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationExportController: XAF generation failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Export generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end renderedXaf()

	/**
	 * The generator context: the administration and the requested period.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string>
	 */
	private function xafContext(string $administrationId): array {
		$period = trim((string)$this->request->getParam('period', ''));
		return [
			'administrationId' => $administrationId,
			'period' => $period,
		];
	}//end xafContext()

	/**
	 * The XAF generator from the container.
	 *
	 * @return XafAuditfileGenerator
	 *
	 * @throws RuntimeException When the container does not hold one.
	 */
	private function xafGenerator(): XafAuditfileGenerator {
		$generator = $this->container->get(XafAuditfileGenerator::class);
		if (($generator instanceof XafAuditfileGenerator) === false) {
			throw new RuntimeException('XAF generator unavailable');
		}

		return $generator;
	}//end xafGenerator()

	/**
	 * Download the XAF itself, or with a truthy "full" a ZIP of it and its attachments.
	 *
	 * @param string        $administrationId The administration.
	 * @param GeneratedFile $rendered         The rendered XAF.
	 *
	 * @return Response
	 */
	private function download(string $administrationId, GeneratedFile $rendered): Response {
		if ($this->isTruthy(value: $this->request->getParam('full', '')) === false) {
			return new DataDownloadResponse(
				$rendered->content,
				$rendered->fileName,
				$rendered->mimeType,
			);
		}

		return $this->zipDownload(administrationId: $administrationId, rendered: $rendered);
	}//end download()

	/**
	 * Download the XAF bundled with its attachments; a bundling failure is a logged 500.
	 *
	 * @param string        $administrationId The administration.
	 * @param GeneratedFile $rendered         The rendered XAF.
	 *
	 * @return Response
	 */
	private function zipDownload(string $administrationId, GeneratedFile $rendered): Response {
		try {
			$zipBytes = $this->bundleZip(administrationId: $administrationId, rendered: $rendered);
		} catch (\Throwable $e) {
			return $this->bundlingFailed(administrationId: $administrationId, e: $e);
		}

		return new DataDownloadResponse(
			$zipBytes,
			'xaf-' . $administrationId . '.zip',
			'application/zip',
		);
	}//end zipDownload()

	/**
	 * Log a failed bundling and answer 500.
	 *
	 * @param string     $administrationId The administration.
	 * @param \Throwable $e                The failure.
	 *
	 * @return JSONResponse
	 */
	private function bundlingFailed(string $administrationId, \Throwable $e): JSONResponse {
		$this->logger->error(
			'AdministrationExportController: XAF ZIP bundling failed',
			['administrationId' => $administrationId, 'exception' => $e->getMessage()]
		);
		return new JSONResponse(['error' => 'Export bundling failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end bundlingFailed()

	/**
	 * Bundle the XAF document plus the administration's attached documents into a ZIP.
	 *
	 * The XAF file is always included. Attached NC-Files documents are added
	 * best-effort — each is streamed from Files by its stored fileId; a file that
	 * cannot be resolved is skipped (never fatal), so the ZIP always contains at
	 * least the audit file. Documents live in NC Files, so this references/streams
	 * them rather than duplicating storage.
	 *
	 * @param string        $administrationId The administration scope.
	 * @param GeneratedFile $rendered         The XAF entry: file name and bytes.
	 *
	 * @return string The ZIP archive bytes.
	 *
	 * @throws RuntimeException When ZIP support is unavailable or the archive cannot be written.
	 */
	private function bundleZip(string $administrationId, GeneratedFile $rendered): string {
		$tmp = $this->zipTempFile();
		$zip = $this->openZip(tmp: $tmp);

		$zip->addFromString($rendered->fileName, $rendered->content);
		$this->addAttachments(zip: $zip, administrationId: $administrationId);

		$zip->close();

		$bytes = (string)file_get_contents($tmp);
		$this->removeTempFile(tmp: $tmp);

		return $bytes;
	}//end bundleZip()

	/**
	 * A temp file for the ZIP.
	 *
	 * @return string The temp file path.
	 *
	 * @throws RuntimeException Without the ZIP extension or a temp file.
	 */
	private function zipTempFile(): string {
		if (class_exists(ZipArchive::class) === false) {
			throw new RuntimeException('ZIP extension unavailable');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'shillinq-xaf-');
		if ($tmp === false) {
			throw new RuntimeException('Could not allocate a temp file for the ZIP');
		}

		return $tmp;
	}//end zipTempFile()

	/**
	 * Open the ZIP archive over the temp file; on failure the temp file is removed.
	 *
	 * @param string $tmp The temp file.
	 *
	 * @return ZipArchive
	 *
	 * @throws RuntimeException When the archive cannot be opened.
	 */
	private function openZip(string $tmp): ZipArchive {
		$zip = new ZipArchive();
		if ($zip->open($tmp, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			$this->removeTempFile(tmp: $tmp);
			throw new RuntimeException('Could not open the ZIP archive');
		}

		return $zip;
	}//end openZip()

	/**
	 * Remove the temp file when it exists.
	 *
	 * @param string $tmp The temp file.
	 *
	 * @return void
	 */
	private function removeTempFile(string $tmp): void {
		if (file_exists($tmp) === true) {
			unlink($tmp);
		}
	}//end removeTempFile()

	/**
	 * Add the administration's attached documents under documents/.
	 *
	 * @param ZipArchive $zip              The open archive.
	 * @param string     $administrationId The administration.
	 *
	 * @return void
	 */
	private function addAttachments(ZipArchive $zip, string $administrationId): void {
		foreach ($this->attachedDocumentFileIds(administrationId: $administrationId) as $fileId) {
			$this->addAttachment(zip: $zip, fileId: $fileId);
		}
	}//end addAttachments()

	/**
	 * Add one attached document. Best-effort: a single unreadable attachment
	 * must not fail the export, so a failure is logged and skipped.
	 *
	 * @param ZipArchive $zip    The open archive.
	 * @param int        $fileId The attachment's file id.
	 *
	 * @return void
	 */
	private function addAttachment(ZipArchive $zip, int $fileId): void {
		try {
			$nodes = $this->rootFolder->getById($fileId);
			$node = ($nodes[0] ?? null);
			if ($node !== null && method_exists($node, 'getContent') === true) {
				$zip->addFromString('documents/' . $node->getName(), $node->getContent());
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AdministrationExportController: skipped an unreadable attachment',
				['fileId' => $fileId, 'exception' => $e->getMessage()]
			);
		}
	}//end addAttachment()

	/**
	 * Resolve the NC-Files fileIds attached to the administration's documents.
	 *
	 * Best-effort: reads `Document` records scoped to the administration from
	 * OpenRegister and returns their numeric fileIds. Returns an empty list when
	 * no document surface is present — the ZIP then bundles only the XAF file.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<int, int>
	 */
	private function attachedDocumentFileIds(string $administrationId): array {
		$ids = [];
		foreach ($this->documentRows(administrationId: $administrationId) as $row) {
			$fileId = $this->fileIdOf(row: $row);
			if ($fileId !== null) {
				$ids[] = $fileId;
			}
		}

		return $ids;
	}//end attachedDocumentFileIds()

	/**
	 * The administration's Document rows ([] when they cannot be read).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int|string, mixed>
	 */
	private function documentRows(string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister('shillinq')
				->setSchema('Document')
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 1000]);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($rows) === true) {
			return $rows;
		}

		return [];
	}//end documentRows()

	/**
	 * A Document row's numeric file id, or null.
	 *
	 * @param mixed $row The row (an array, or an entity that serialises to one).
	 *
	 * @return integer|null
	 */
	private function fileIdOf(mixed $row): int|null {
		$data = $row;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = (array)$row->jsonSerialize();
		}

		if (is_array($data) === false) {
			return null;
		}

		return $this->numericId(value: ($data['fileId'] ?? null));
	}//end fileIdOf()

	/**
	 * An integer, or a string of digits, as an int; null otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return integer|null
	 */
	private function numericId(mixed $value): int|null {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end numericId()

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

	/**
	 * Interpret a request flag as a boolean (1/true/yes/on = true).
	 *
	 * @param mixed $value The raw request value.
	 *
	 * @return bool
	 */
	private function isTruthy(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}//end isTruthy()
}//end class
