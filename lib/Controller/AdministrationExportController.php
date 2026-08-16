<?php

/**
 * Administration Export Controller
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
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
		parent::__construct(appName: Application::APP_ID, request: $request);
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
		if ($this->isValidIdentifier(identifier: $administrationId) === false) {
			return new JSONResponse(['error' => 'Invalid administration id'], Http::STATUS_BAD_REQUEST);
		}

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
			// Mask non-membership as 404 — never confirm administration existence (REQ-MA-001).
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$period = trim((string)$this->request->getParam('period', ''));
		$context = [
			'administrationId' => $administrationId,
			'period' => $period,
		];

		try {
			$generator = $this->container->get(XafAuditfileGenerator::class);
			if (($generator instanceof XafAuditfileGenerator) === false) {
				throw new RuntimeException('XAF generator unavailable');
			}

			$rendered = $generator->generate($context, 'xml');
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationExportController: XAF generation failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Export generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$full = $this->isTruthy(value: $this->request->getParam('full', ''));
		if ($full === false) {
			return new DataDownloadResponse(
				$rendered->content,
				$rendered->fileName,
				$rendered->mimeType,
			);
		}

		try {
			$zipBytes = $this->bundleZip(
				administrationId: $administrationId,
				xafFileName: $rendered->fileName,
				xafContent: $rendered->content
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationExportController: XAF ZIP bundling failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Export bundling failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse(
			$zipBytes,
			'xaf-' . $administrationId . '.zip',
			'application/zip',
		);

	}//end exportXaf()

	/**
	 * Bundle the XAF document plus the administration's attached documents into a ZIP.
	 *
	 * The XAF file is always included. Attached NC-Files documents are added
	 * best-effort — each is streamed from Files by its stored fileId; a file that
	 * cannot be resolved is skipped (never fatal), so the ZIP always contains at
	 * least the audit file. Documents live in NC Files, so this references/streams
	 * them rather than duplicating storage.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $xafFileName The XAF entry file name.
	 * @param string $xafContent The XAF bytes.
	 *
	 * @return string The ZIP archive bytes.
	 *
	 * @throws RuntimeException When ZIP support is unavailable or the archive cannot be written.
	 */
	private function bundleZip(string $administrationId, string $xafFileName, string $xafContent): string {
		if (class_exists(ZipArchive::class) === false) {
			throw new RuntimeException('ZIP extension unavailable');
		}

		$tmp = tempnam(sys_get_temp_dir(), 'shillinq-xaf-');
		if ($tmp === false) {
			throw new RuntimeException('Could not allocate a temp file for the ZIP');
		}

		$zip = new ZipArchive();
		if ($zip->open($tmp, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			if (file_exists($tmp) === true) {
				unlink($tmp);
			}

			throw new RuntimeException('Could not open the ZIP archive');
		}

		$zip->addFromString($xafFileName, $xafContent);

		foreach ($this->attachedDocumentFileIds(administrationId: $administrationId) as $fileId) {
			try {
				$nodes = $this->rootFolder->getById($fileId);
				$node = ($nodes[0] ?? null);
				if ($node !== null && method_exists($node, 'getContent') === true) {
					$zip->addFromString('documents/' . $node->getName(), $node->getContent());
				}
			} catch (\Throwable $e) {
				// Best-effort: a single unreadable attachment must not fail the export.
				$this->logger->warning(
					'AdministrationExportController: skipped an unreadable attachment',
					['fileId' => $fileId, 'exception' => $e->getMessage()]
				);
			}
		}

		$zip->close();

		$bytes = (string)file_get_contents($tmp);
		if (file_exists($tmp) === true) {
			unlink($tmp);
		}

		return $bytes;
	}//end bundleZip()

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
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister('shillinq')
				->setSchema('Document')
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 1000]);
		} catch (\Throwable $e) {
			return [];
		}

		$ids = [];
		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = $rows;
		}

		foreach ($rowList as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = (array)$row->jsonSerialize();
			}

			if (is_array($data) === false) {
				continue;
			}

			$fileId = ($data['fileId'] ?? null);
			if (is_int($fileId) === true || (is_string($fileId) === true && ctype_digit($fileId) === true)) {
				$ids[] = (int)$fileId;
			}
		}

		return $ids;
	}//end attachedDocumentFileIds()

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
