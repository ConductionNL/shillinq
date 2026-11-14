<?php

/**
 * Direct Debit Batch Controller
 *
 * The HTTP surface behind the direct debit batches page: propose the draft
 * batches for a collection date (POST /api/v1/direct-debit-batches/propose)
 * and download a generated batch's pain.008 file
 * (GET /api/v1/direct-debit-batches/{id}/file). Both are #[NoAdminRequired]
 * with a session guard and a per-administration guard in the method body
 * (ADR-005): a proposal outside the caller's administrations is refused, and
 * another administration's batch is masked as 404.
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
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Receivables\DirectDebitBatchService;
use OCA\Shillinq\Service\Receivables\DirectDebitFileService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proposes direct debit batches and serves their pain.008 files.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
class DirectDebitBatchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request          The request.
	 * @param DirectDebitBatchService               $batches          Proposes the draft batches.
	 * @param DirectDebitFileService                $files            Serves a batch's stored file.
	 * @param AdministrationContextServiceInterface $administrations  The caller's administrations (ADR-005).
	 * @param ObjectServiceInterface                $objectService    OpenRegister's object service.
	 * @param RegisterLocation                      $registerLocation Where the register lives.
	 * @param IUserSession                          $session          The user session.
	 * @param LoggerInterface                       $logger           Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly DirectDebitBatchService $batches,
		private readonly DirectDebitFileService $files,
		private readonly AdministrationContextServiceInterface $administrations,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Propose the draft batches for a collection date.
	 *
	 * Request: administrationId, collectionDate (YYYY-MM-DD). 201 with the
	 * batches and the skipped invoices; 422 with the skipped invoices when
	 * nothing can be collected; 400 on a malformed date; 403 outside the
	 * caller's administrations.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function propose(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '' || $this->administrations->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not allowed for this administration'], Http::STATUS_FORBIDDEN);
		}

		$collectionDate = trim((string)$this->request->getParam('collectionDate', ''));
		if (self::isDate(value: $collectionDate) === false) {
			return new JSONResponse(['error' => 'invalid-collection-date'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->batches->propose(administrationId: $administrationId, collectionDate: $collectionDate);
		} catch (Throwable $e) {
			$this->logger->error('DirectDebitBatchController: proposal failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'proposal-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($result['batches'] === []) {
			return new JSONResponse($result + ['error' => 'nothing-to-collect'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);

	}//end propose()

	/**
	 * Download the pain.008 file of a generated batch.
	 *
	 * 200 with the XML as an attachment; 404 for a missing batch or one of
	 * another administration; 409 when the batch has no file yet.
	 *
	 * @param string $id The DirectDebitBatch id.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.3
	 */
	#[NoAdminRequired]
	public function file(string $id): DataDownloadResponse|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$batch = $this->accessibleBatch(id: $id);
		if ($batch === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$file = $this->files->storedFile(batch: $batch);
		if ($file === null) {
			return new JSONResponse(['error' => 'not-generated'], Http::STATUS_CONFLICT);
		}

		return new DataDownloadResponse($file->content, $file->fileName, $file->mimeType);

	}//end file()

	/**
	 * The batch, when it exists and belongs to one of the caller's administrations.
	 *
	 * @param string $id The batch id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function accessibleBatch(string $id): ?array {
		try {
			$batch = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('DirectDebitBatch'),
				id: trim($id)
			);
		} catch (Throwable $e) {
			$this->logger->warning('DirectDebitBatchController: batch lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if ($batch === null) {
			return null;
		}

		$administrationId = (string)($batch['administrationId'] ?? '');
		if ($administrationId === '' || $this->administrations->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		return $batch;

	}//end accessibleBatch()

	/**
	 * Whether the value is a real calendar date written YYYY-MM-DD.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);

	}//end isDate()
}//end class
