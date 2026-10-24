<?php

/**
 * Financial Document Guard
 *
 * The refusals every act on a financial document shares, in the order they
 * run: the caller's action, then the administration the money belongs to.
 * Also reads an invoice as the caller, so an act never touches an invoice the
 * caller could not open.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
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
 * Refuses an act before it writes.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
class FinancialDocumentGuard {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param PaymentActionAuthorizer $authorizer Whether the caller carries an action.
	 * @param AdministrationContextService $administrations Which administrations the caller works in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly AdministrationContextService $administrations,
	) {
	}//end __construct()

	/**
	 * Refuse a caller who does not carry the action.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller lacks it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function assertMay(string $action): void {
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
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function assertAdministration(string $administrationId): void {
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
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function readableInvoice(string $invoiceId): array {
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
	 * The administration an act bills in: the given one, else the caller's default.
	 *
	 * @param string $given The administration the caller named, or empty.
	 *
	 * @return string The administration id, or empty when the caller has none.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function administrationOr(string $given): string {
		if (trim($given) !== '') {
			return trim($given);
		}

		return (string)$this->administrations->defaultAdministrationIdForUser(userId: $this->authorizer->callerId());
	}//end administrationOr()

	/**
	 * The calling user's id.
	 *
	 * @return string The id, or empty.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function callerId(): string {
		return $this->authorizer->callerId();
	}//end callerId()

	/**
	 * The register slug shillinq's documents live in.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
