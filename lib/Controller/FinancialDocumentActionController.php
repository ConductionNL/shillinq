<?php

/**
 * Financial Document Action Controller
 *
 * The acts of the financial documents panel: issue an invoice on a host
 * object, and ask for payment of an invoice. Both are open to any signed-in
 * user at the route and refused inside FinancialDocumentActions on the
 * caller's action and administration before anything is written.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Service\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentActions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Issues an invoice on an object and requests payment of an invoice.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-004)
 */
class FinancialDocumentActionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param FinancialDocumentActions $actions The acts and their refusals.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FinancialDocumentActions $actions,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Issue one invoice linked to the host object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param float $amount The gross amount.
	 * @param string $description What the invoice is for.
	 * @param string $currency The currency.
	 * @param array<string, mixed> $debtor A customerMasterId, or a name and an email.
	 * @param string $administrationId The administration that bills it; the caller's default when empty.
	 * @param string $objectType The semantic type of the host object, for example `case`.
	 *
	 * @return JSONResponse The issued invoice, or the refusal.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	#[NoAdminRequired]
	public function issueInvoice(
		string $register = '',
		string $schema = '',
		string $objectId = '',
		float $amount = 0.0,
		string $description = '',
		string $currency = 'EUR',
		array $debtor = [],
		string $administrationId = '',
		string $objectType = '',
	): JSONResponse {
		if ($register === '' || $schema === '' || $objectId === '') {
			return new JSONResponse(['error' => 'Name the register, the schema and the object to invoice.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$invoice = $this->actions->issueInvoice(
				register: $register,
				schema: $schema,
				objectId: $objectId,
				payload: [
					'amount' => $amount,
					'description' => $description,
					'currency' => $currency,
					'debtor' => $debtor,
					'administrationId' => $administrationId,
					'objectType' => $objectType,
				],
			);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse(['invoice' => $invoice], Http::STATUS_CREATED);
	}//end issueInvoice()

	/**
	 * Ask for payment of an outstanding invoice.
	 *
	 * @param string $id The invoice id.
	 *
	 * @return JSONResponse The payment request, or the refusal.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-004)
	 */
	#[NoAdminRequired]
	public function requestPayment(string $id): JSONResponse {
		try {
			$request = $this->actions->requestPayment(invoiceId: $id);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse(['request' => $request], Http::STATUS_CREATED);
	}//end requestPayment()
}//end class
