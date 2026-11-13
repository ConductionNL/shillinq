<?php

/**
 * VAT number check controller
 *
 * `POST /api/vat-number-checks/{type}/{id}`: the Check VAT number action on
 * a customer's page and on a supplier's page (tax-vat-number-check).
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
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AssetRecordsInterface;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Checks the VAT number of one customer or supplier the caller can see.
 */
class VatNumberCheckController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param AdministrationContextServiceInterface $context The caller's administrations.
	 * @param AssetRecordsInterface                 $records Reads a record from the register.
	 * @param VatNumberCheck               $check   The VIES check.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextServiceInterface $context,
		private readonly AssetRecordsInterface $records,
		private readonly VatNumberCheck $check,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Check the record's VAT number against VIES and keep the outcome on it (REQ-TVNC-001).
	 *
	 * @param string $type `customer` or `supplier`.
	 * @param string $id   The record id.
	 *
	 * @return JSONResponse 200 with status, check date and last valid date; 401, 404 or 422.
	 *
	 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function check(string $type, string $id): JSONResponse {
		$record = $this->authorizeRecord(type: $type, id: $id);
		if ($record instanceof JSONResponse) {
			return $record;
		}

		try {
			return new JSONResponse($this->check->checkRecord(type: $type, record: $record));
		} catch (DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

	}//end check()

	/**
	 * Resolve the record and refuse a caller outside its administration.
	 *
	 * @param string $type `customer` or `supplier`.
	 * @param string $id   The record id.
	 *
	 * @return array<string,mixed>|JSONResponse The record, or 401 / 404.
	 */
	private function authorizeRecord(string $type, string $id): array|JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$record = $this->recordOrNull(type: $type, id: $id);
		if ($record === null || $this->context->canAccess(administrationId: (string)($record['administrationId'] ?? '')) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return $record;
	}//end authorizeRecord()

	/**
	 * The record of a known type, or null when the type is unknown or the record cannot be read.
	 *
	 * @param string $type The record type: customer or supplier.
	 * @param string $id   The record id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function recordOrNull(string $type, string $id): array|null {
		if (isset(VatNumberCheck::RECORDS[$type]) === false) {
			return null;
		}

		try {
			return $this->records->find(schema: VatNumberCheck::RECORDS[$type]['schema'], id: $id);
		} catch (Throwable $e) {
			return null;
		}
	}//end recordOrNull()
}//end class
