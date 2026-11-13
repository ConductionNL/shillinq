<?php

/**
 * VAT Supplementary Return Controller
 *
 * The two steps that correct a filed VAT period (REQ-VPS-002):
 *
 *   POST /api/vat-returns/{returnId}/detect-drift   — compare a filed return with the books
 *   POST /api/vat-corrections/{correctionId}/prepare — compile a detected correction
 *
 * Both are authenticated with #[NoAdminRequired] and authorised on the
 * object's own administration through AdministrationContextService::canAccess():
 * a return or correction of an administration the caller is not a member of
 * answers 404, the same as one that does not exist, before anything is read
 * from the books or written.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Detect drift on a filed return and prepare its supplementary return.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
class VatSuppletieController extends Controller {

	/**
	 * The shape of an object id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request    The request.
	 * @param VatSuppletieDetectionService $suppletie  The detection service.
	 * @param VATReturnService             $vatReturns The VAT return service.
	 * @param IUserSession                 $session    The user session.
	 * @param AdministrationContextService $context    The caller's administrations.
	 * @param LoggerInterface              $logger     The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly VatSuppletieDetectionService $suppletie,
		private readonly VATReturnService $vatReturns,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check a filed return for drift against the books.
	 *
	 * @param string $returnId The filed return.
	 *
	 * @return JSONResponse 201 with the correction written; 200 with `drift: false` when the books
	 *                      still match; 400 for a malformed id; 401 without a user; 404 for a return
	 *                      that does not exist or the caller may not see; 409 for a return not filed.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function detectDrift(string $returnId): JSONResponse {
		$refusal = $this->refuseWithoutUserOrId(id: $returnId, name: 'returnId');
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$vatReturn = $this->vatReturns->findReturn(returnId: $returnId);
			if ($this->mayAccess(record: $vatReturn) === false) {
				return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
			}

			$correction = $this->suppletie->detect(vatReturnId: $returnId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatSuppletieController: drift check failed',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to check the return for corrections'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		if ($correction === null) {
			return new JSONResponse(['drift' => false, 'data' => null], Http::STATUS_OK);
		}

		return new JSONResponse(['drift' => true, 'data' => $correction], Http::STATUS_CREATED);
	}//end detectDrift()

	/**
	 * Prepare the supplementary return of a detected correction.
	 *
	 * @param string $correctionId The detected correction.
	 *
	 * @return JSONResponse 200 with the prepared correction; 400 for a malformed id; 401 without a
	 *                      user; 404 for a correction that does not exist or the caller may not see;
	 *                      409 for a correction already prepared or no longer a draft.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function prepare(string $correctionId): JSONResponse {
		$refusal = $this->refuseWithoutUserOrId(id: $correctionId, name: 'correctionId');
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$correction = $this->suppletie->findCorrection(vatCorrectionId: $correctionId);
			if ($this->mayAccess(record: $correction) === false) {
				return new JSONResponse(['error' => 'VAT correction not found'], Http::STATUS_NOT_FOUND);
			}

			$prepared = $this->suppletie->prepare(vatCorrectionId: $correctionId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatSuppletieController: preparing the supplementary return failed',
				['correctionId' => $correctionId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to prepare the supplementary return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(['data' => $prepared], Http::STATUS_OK);
	}//end prepare()

	/**
	 * 401 without a user, 400 for a malformed id, else null.
	 *
	 * @param string $id   The id from the route.
	 * @param string $name Its name, for the message.
	 *
	 * @return JSONResponse|null
	 */
	private function refuseWithoutUserOrId(string $id, string $name): ?JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => $name . ' is required'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refuseWithoutUserOrId()

	/**
	 * Whether the record exists and belongs to an administration the caller may see.
	 *
	 * @param array<string,mixed>|null $record The return or correction.
	 *
	 * @return bool
	 */
	private function mayAccess(?array $record): bool {
		if ($record === null) {
			return false;
		}

		return $this->context->canAccess(administrationId: (string)($record['administrationId'] ?? ''));
	}//end mayAccess()
}//end class
