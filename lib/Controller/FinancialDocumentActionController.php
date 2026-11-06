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
use OCA\Shillinq\Service\FinancialDocumentAuthoring;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Issues an invoice on an object, requests payment of an invoice, and starts a
 * quote, a credit note or a payout.
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
	 * @param FinancialDocumentAuthoring $authoring Starts a quote, a credit note or a payout.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FinancialDocumentActions $actions,
		private readonly FinancialDocumentAuthoring $authoring,
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

	/**
	 * Start a draft quote on the host object, with one line.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param float $amount The amount of the line.
	 * @param string $description What the quote is for.
	 * @param string $currency The currency.
	 * @param array<string, mixed> $debtor A customerMasterId, or a name and an email.
	 * @param string $administrationId The administration; the caller's default when empty.
	 * @param string $objectType The semantic type of the host object.
	 *
	 * @return JSONResponse The quote, or the refusal.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	#[NoAdminRequired]
	public function createQuote(
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
			return new JSONResponse(['error' => 'Name the register, the schema and the object to quote for.'], Http::STATUS_BAD_REQUEST);
		}

		$payload = [
			'amount' => $amount,
			'description' => $description,
			'currency' => $currency,
			'debtor' => $debtor,
			'administrationId' => $administrationId,
			'objectType' => $objectType,
		];

		return $this->answer(key: 'quote', act: fn (): array => $this->authoring->createQuote($register, $schema, $objectId, $payload));
	}//end createQuote()

	/**
	 * Issue a credit note for the whole of an invoice.
	 *
	 * @param string $id The invoice id.
	 *
	 * @return JSONResponse The credit note, or the refusal.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	#[NoAdminRequired]
	public function creditInvoice(string $id): JSONResponse {
		return $this->answer(key: 'creditNote', act: fn (): array => $this->authoring->creditInvoice(invoiceId: $id));
	}//end creditInvoice()

	/**
	 * Raise a payout on the host object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param float $amount The amount.
	 * @param string $payeeName Who is paid.
	 * @param string $iban Their IBAN.
	 * @param string $description What the payout is for.
	 * @param string $dueOn The pay-by date, YYYY-MM-DD.
	 * @param string $administrationId The administration; the caller's default when empty.
	 * @param string $objectType The semantic type of the host object.
	 *
	 * @return JSONResponse The payout, or the refusal.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	#[NoAdminRequired]
	public function raisePayout(
		string $register = '',
		string $schema = '',
		string $objectId = '',
		float $amount = 0.0,
		string $payeeName = '',
		string $iban = '',
		string $description = '',
		string $dueOn = '',
		string $administrationId = '',
		string $objectType = '',
	): JSONResponse {
		if ($register === '' || $schema === '' || $objectId === '') {
			return new JSONResponse(['error' => 'Name the register, the schema and the object the payout is for.'], Http::STATUS_BAD_REQUEST);
		}

		$payload = [
			'amount' => $amount,
			'payeeName' => $payeeName,
			'iban' => $iban,
			'description' => $description,
			'dueOn' => $dueOn,
			'administrationId' => $administrationId,
			'objectType' => $objectType,
		];

		return $this->answer(key: 'payout', act: fn (): array => $this->authoring->raisePayout($register, $schema, $objectId, $payload));
	}//end raisePayout()

	/**
	 * Run one act and answer 201 with its result under a key, or the refusal.
	 *
	 * @param string $key The key the result is answered under.
	 * @param callable(): array<string, mixed> $act The act.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answer(string $key, callable $act): JSONResponse {
		try {
			return new JSONResponse([$key => $act()], Http::STATUS_CREATED);
		} catch (FinancialDocumentActionRefusedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}//end answer()
}//end class
