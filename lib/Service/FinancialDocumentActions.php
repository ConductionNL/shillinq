<?php

/**
 * Financial Document Actions
 *
 * The acts the financial documents panel offers on a host object: issue an
 * invoice for it, and ask for payment of an invoice through shillinq. Every
 * act refuses before it writes: first on the caller's action
 * (`invoice.issue`, `payment.request`), then on the administration the money
 * belongs to, then on the document's own state.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Issues an invoice on an object and requests payment of an invoice.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-004)
 */
class FinancialDocumentActions {
	/**
	 * The action that issues an invoice.
	 *
	 * @var string
	 */
	public const ACTION_ISSUE_INVOICE = 'invoice.issue';

	/**
	 * Invoice states a payment can be asked for.
	 *
	 * @var array<int, string>
	 */
	private const PAYABLE_STATES = ['issued', 'overdue', 'disputed'];

	/**
	 * Payment request states that are still open.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_REQUEST_STATES = ['pending', 'authorized'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param PaymentActionAuthorizer $authorizer Whether the caller carries an action.
	 * @param AdministrationContextService $administrations Which administrations the caller works in.
	 * @param ObjectRequestInvoiceService $invoices Issues an invoice for a debtor and an amount.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly AdministrationContextService $administrations,
		private readonly ObjectRequestInvoiceService $invoices,
	) {
	}//end __construct()

	/**
	 * Issue one invoice linked to the host object.
	 *
	 * Payload: amount, description, debtor (`customerMasterId`, or `name` and
	 * `email`), and optionally currency, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The invoice.
	 *
	 * @return array{id: string} The issued invoice.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the payload is refused.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function issueInvoice(string $register, string $schema, string $objectId, array $payload): array {
		$this->assertMay(action: self::ACTION_ISSUE_INVOICE);
		$administrationId = trim((string)($payload['administrationId'] ?? ''));
		if ($administrationId === '') {
			$administrationId = (string)$this->administrations->defaultAdministrationIdForUser(userId: $this->authorizer->callerId());
		}

		$this->assertAdministration(administrationId: $administrationId);

		$amount = round((float)($payload['amount'] ?? 0), 2);
		if ($amount <= 0.0) {
			throw new FinancialDocumentActionRefusedException(message: 'An invoice needs an amount above zero.', status: 400);
		}

		$debtor = $payload['debtor'] ?? null;
		if (is_array($debtor) === false || ((string)($debtor['customerMasterId'] ?? '') === '' && (string)($debtor['email'] ?? '') === '')) {
			throw new FinancialDocumentActionRefusedException(message: 'An invoice needs a debtor: a customer, or a name and an email.', status: 400);
		}

		$link = ['register' => $register, 'schema' => $schema, 'id' => $objectId];
		$type = trim((string)($payload['objectType'] ?? ''));
		if ($type !== '') {
			$link = ['type' => $type] + $link;
		}

		$id = $this->invoices->issue(
			request: [
				'amount' => $amount,
				'currency' => (string)($payload['currency'] ?? 'EUR'),
				'description' => trim((string)($payload['description'] ?? '')),
				'debtor' => $debtor,
				'linkedObjects' => [$link],
			],
			administrationId: $administrationId,
			today: gmdate('Y-m-d'),
		);

		return ['id' => $id];
	}//end issueInvoice()

	/**
	 * Ask for payment of an outstanding invoice through shillinq.
	 *
	 * The request carries the invoice's open amount and its linked objects, so
	 * it appears on the same objects as the invoice and settles it on capture.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array<string, mixed> The payment request.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the invoice or an open request refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-004)
	 */
	public function requestPayment(string $invoiceId): array {
		$this->assertMay(action: PaymentActionAuthorizer::ACTION_REQUEST);

		$invoice = $this->readableInvoice(invoiceId: $invoiceId);
		$this->assertAdministration(administrationId: (string)($invoice['administrationId'] ?? ''));
		$this->assertPayable(invoice: $invoice);

		$request = [
			'subjectKind' => 'invoice',
			'invoiceReference' => $invoiceId,
			'amount' => (float)($invoice['amountDue'] ?? $invoice['grossAmount'] ?? 0),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'description' => 'Invoice ' . (string)($invoice['invoiceNumber'] ?? $invoiceId),
			'state' => 'pending',
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'linkedObjects' => array_values((array)($invoice['linkedObjects'] ?? [])),
			'requestedBy' => $this->authorizer->callerId(),
		];

		$saved = $this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: 'PaymentRequest',
			_rbac: false,
		);

		return ['id' => ObjectIdentifier::resolve(saved: $saved)] + $request;
	}//end requestPayment()

	/**
	 * Refuse a caller who does not carry the action.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller lacks it.
	 */
	private function assertMay(string $action): void {
		if ($this->authorizer->may($action) === false) {
			throw new FinancialDocumentActionRefusedException(message: sprintf('This needs the %s action.', $action), status: 403);
		}
	}//end assertMay()

	/**
	 * Refuse an administration the caller does not work in.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return void
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller may not work in it.
	 */
	private function assertAdministration(string $administrationId): void {
		if ($administrationId === '' || $this->administrations->canAccess(administrationId: $administrationId) === false) {
			throw new FinancialDocumentActionRefusedException(message: 'You do not work in the administration this money belongs to.', status: 403);
		}
	}//end assertAdministration()

	/**
	 * The invoice, read as the caller.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return array<string, mixed> The invoice.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller cannot read it.
	 */
	private function readableInvoice(string $invoiceId): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema('ARInvoice')
			->findAll(['filters' => ['id' => $invoiceId], 'limit' => 1]);

		$invoice = ObjectIdentifier::recordWithId(candidate: ($rows[0] ?? null));
		if ($invoice === null) {
			throw new FinancialDocumentActionRefusedException(message: 'No invoice with that id is readable.', status: 404);
		}

		return $invoice;
	}//end readableInvoice()

	/**
	 * Refuse an invoice that is not outstanding, or already has an open payment request.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return void
	 *
	 * @throws FinancialDocumentActionRefusedException When no payment can be asked for.
	 */
	private function assertPayable(array $invoice): void {
		if ((string)($invoice['invoiceType'] ?? 'standard') === 'credit-note'
			|| in_array((string)($invoice['lifecycleState'] ?? ''), self::PAYABLE_STATES, true) === false
		) {
			throw new FinancialDocumentActionRefusedException(message: 'Only an issued invoice that is not paid can be paid.', status: 409);
		}

		$open = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema('PaymentRequest')
			->findAll(['filters' => ['invoiceReference' => (string)$invoice['id']], 'limit' => 50], _rbac: false, _multitenancy: false);
		foreach ($open as $row) {
			$request = ObjectIdentifier::recordWithId(candidate: $row);
			if ($request !== null && in_array((string)($request['state'] ?? ''), self::OPEN_REQUEST_STATES, true) === true) {
				throw new FinancialDocumentActionRefusedException(message: 'This invoice already has an open payment request.', status: 409);
			}
		}
	}//end assertPayable()

	/**
	 * The register slug shillinq's documents live in.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
