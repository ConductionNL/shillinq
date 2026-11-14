<?php

/**
 * VAT Supplementary Return Filing Controller
 *
 * The last step of correcting a filed VAT period (REQ-VPS-002):
 *
 *   GET  /api/vat-corrections/{correctionId}/supplementary-return — download the prepared return
 *   POST /api/vat-corrections/{correctionId}/file                 — file it through Digipoort
 *
 * Both are authenticated with #[NoAdminRequired] and authorised on the
 * correction's own administration through AdministrationContextService::canAccess():
 * a correction of an administration the caller is not a member of answers
 * 404, the same as one that does not exist, before anything is built or sent.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Tax\SuppletieFilingUnavailableException;
use OCA\Shillinq\Service\Tax\VatSuppletieFilingService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Download or file the supplementary return of a prepared correction.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 */
class VatSuppletieFilingController extends Controller {

	/**
	 * The shape of an object id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param VatSuppletieDetectionService $suppletie Finds the correction.
	 * @param VatSuppletieFilingService    $filing    Builds and files the supplementary return.
	 * @param IUserSession                 $session   The user session.
	 * @param AdministrationContextService $context   The caller's administrations.
	 * @param LoggerInterface              $logger    The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly VatSuppletieDetectionService $suppletie,
		private readonly VatSuppletieFilingService $filing,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download the prepared supplementary return as a JSON file.
	 *
	 * @param string $correctionId The prepared correction.
	 *
	 * @return DataDownloadResponse|JSONResponse 200 with the file; 400 for a malformed id; 401 without a user;
	 *                                           404 for a correction that does not exist or the caller may not see;
	 *                                           409 for a correction not prepared yet.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function download(string $correctionId): DataDownloadResponse|JSONResponse {
		$correction = $this->accessibleCorrection(correctionId: $correctionId);
		if ($correction instanceof JSONResponse) {
			return $correction;
		}

		try {
			$document = $this->filing->document(correction: $correction);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new DataDownloadResponse(
			(string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'suppletie-' . $correctionId . '.json',
			'application/json'
		);
	}//end download()

	/**
	 * File the prepared supplementary return through Digipoort.
	 *
	 * @param string $correctionId The prepared correction.
	 *
	 * @return JSONResponse 200 with the submitted correction; 400 for a malformed id; 401 without a user;
	 *                      404 for a correction that does not exist or the caller may not see; 409 for a
	 *                      correction not prepared or no longer a draft, and, with `download`, while
	 *                      Digipoort is not connected; 500 when filing failed.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function file(string $correctionId): JSONResponse {
		$correction = $this->accessibleCorrection(correctionId: $correctionId);
		if ($correction instanceof JSONResponse) {
			return $correction;
		}

		try {
			$filed = $this->filing->file(correction: $correction);
		} catch (SuppletieFilingUnavailableException $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'download' => '/apps/shillinq/api/vat-corrections/' . $correctionId . '/supplementary-return'],
				Http::STATUS_CONFLICT
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error(
				'VatSuppletieFilingController: filing the supplementary return failed',
				['correctionId' => $correctionId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to file the supplementary return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(['data' => $filed], Http::STATUS_OK);
	}//end file()

	/**
	 * The correction, or the refusal: 401 without a user, 400 for a malformed id,
	 * 404 for one that does not exist or belongs to an administration the caller may not see.
	 *
	 * @param string $correctionId The id from the route.
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private function accessibleCorrection(string $correctionId): array|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $correctionId) !== 1) {
			return new JSONResponse(['error' => 'correctionId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$correction = $this->suppletie->findCorrection(vatCorrectionId: $correctionId);
		} catch (Throwable $e) {
			$this->logger->error(
				'VatSuppletieFilingController: reading the correction failed',
				['correctionId' => $correctionId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to read the VAT correction'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($correction === null
			|| $this->context->canAccess(administrationId: (string)($correction['administrationId'] ?? '')) === false
		) {
			return new JSONResponse(['error' => 'VAT correction not found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($correction['id'] ?? '') === '') {
			$correction['id'] = $correctionId;
		}

		return $correction;
	}//end accessibleCorrection()
}//end class
