<?php

/**
 * Self-billed Invoice Review
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bookkeeper's review of a self-billed invoice a customer sent
 * (sales-einvoice-exchange design D5, REQ-SEIX-007). It arrives as a draft;
 * issuing it books it like any sales invoice, but only while the customer's
 * self-billing agreement covers the invoice date, and never once it was
 * rejected. Rejecting it records the reason and leaves it unbooked.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Guards issuing a self-billed invoice, and records its rejection.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 */
class SelfBilledInvoiceReview {
	/**
	 * The longest rejection reason kept.
	 *
	 * @var int
	 */
	public const MAX_REASON_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService         OpenRegister's object service (ADR-083).
	 * @param AdministrationContextService $administrationContext Per-user administration scope (ADR-005).
	 * @param IAppConfig                   $appConfig             App config for the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Refuse to issue a self-billed invoice the customer's agreement does not cover.
	 *
	 * A no-op for an invoice that is not self-billed.
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With the reason, when it may not be issued.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function requireSelfBillingAgreement(array $invoice): void {
		if (($invoice['selfBilled'] ?? false) !== true) {
			return;
		}

		$number   = (string)($invoice['invoiceNumber'] ?? '');
		$rejected = trim((string)($invoice['selfBilling']['rejectionReason'] ?? ''));
		if ($rejected !== '') {
			throw new RuntimeException(sprintf('Self-billed invoice %s was rejected and cannot be issued: %s', $number, $rejected));
		}

		$customer = $this->first(
			schema: 'CustomerMaster',
			filters: [
				'administrationId' => (string)($invoice['administrationId'] ?? ''),
				'customerId' => (string)($invoice['customerId'] ?? ''),
			]
		);
		$gap      = 'customer';
		if ($customer !== null) {
			$gap = SelfBilledInvoiceIntake::agreementGap(
				agreement: ($customer['selfBillingAgreement'] ?? null),
				date: substr((string)($invoice['invoiceDate'] ?? ''), 0, 10)
			);
		}

		if ($gap !== null) {
			throw new RuntimeException(sprintf('Self-billed invoice %s cannot be issued: %s.', $number, SelfBilledInvoiceIntake::REASONS[$gap]));
		}
	}//end requireSelfBillingAgreement()

	/**
	 * Reject a draft self-billed invoice with a reason; it stays unbooked.
	 *
	 * @param string $administrationId The administration (checked against the user).
	 * @param string $invoiceNumber    The invoice's number.
	 * @param string $reason           Why it is rejected.
	 *
	 * @return array<string,mixed> The rejected invoice.
	 *
	 * @throws RuntimeException `ARInvoice not found` for a missing or inaccessible
	 *                          invoice; otherwise the refusal.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
	 */
	public function reject(string $administrationId, string $invoiceNumber, string $reason): array {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('ARInvoice not found');
		}

		$invoice = $this->first(schema: 'ARInvoice', filters: ['administrationId' => $administrationId, 'invoiceNumber' => $invoiceNumber]);
		if ($invoice === null) {
			throw new RuntimeException('ARInvoice not found');
		}

		if (($invoice['selfBilled'] ?? false) !== true || (string)($invoice['lifecycleState'] ?? '') !== 'draft') {
			throw new RuntimeException('Only a draft self-billed invoice can be rejected');
		}

		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException('A reason is required to reject a self-billed invoice');
		}

		if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			throw new RuntimeException(sprintf('The reason is longer than %d characters', self::MAX_REASON_LENGTH));
		}

		$selfBilling = $invoice['selfBilling'] ?? [];
		if (is_array($selfBilling) === false) {
			$selfBilling = [];
		}

		$selfBilling['rejectionReason'] = $reason;
		$invoice['selfBilling']         = $selfBilling;

		$this->objectService
			->setRegister($this->register())
			->setSchema('ARInvoice')
			->saveObject($invoice);

		return $invoice;
	}//end reject()

	/**
	 * The first matching record of a schema, entity rows included.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				return $record;
			}
		}

		return null;
	}//end first()

	/**
	 * The register slug from app config, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
