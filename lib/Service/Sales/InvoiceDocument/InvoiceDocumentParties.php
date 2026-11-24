<?php

/**
 * Invoice Document Parties
 *
 * Reads what an invoice document needs beside the invoice itself: the
 * administration as creditor (name, IBAN, BIC), the customer as recipient
 * (name, VAT id, language), the administration's invoice layout and its
 * default language (sales-invoice-document REQ-SID-001, REQ-SID-003 and
 * REQ-SID-005). Without these the document carried no IBAN, so the EPC QR was
 * never drawn, and no layout or customer language reached it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales\InvoiceDocument;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the creditor, recipient, layout and language of an invoice document.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
 */
class InvoiceDocumentParties {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service, injected per ADR-083.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param LoggerInterface        $logger           Logs a record that could not be read.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The parties of an invoice, in the shape `InvoicePdfGenerator::generatePdf()`
	 * takes as context. A record that is missing or unreadable leaves its part
	 * empty: the document still renders, only without that part.
	 *
	 * @param array<string, mixed> $invoice The invoice, with `administrationId` and `customerId`.
	 *
	 * @return array{creditor: array<string, string>, recipient: array<string, string>, layout: array<string, mixed>, language: string}
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
	 */
	public function forInvoice(array $invoice): array {
		$administrationId = trim((string)($invoice['administrationId'] ?? ''));
		$administration = $this->one(schema: 'Administration', id: $administrationId, fallbackProperty: 'administrationCode');
		$customer = $this->one(schema: 'CustomerMaster', id: trim((string)($invoice['customerId'] ?? '')), fallbackProperty: 'customerId');
		if ((string)($customer['administrationId'] ?? '') !== $administrationId) {
			$customer = [];
		}

		return [
			'creditor' => array_filter(
				[
					'legalName' => trim((string)($administration['name'] ?? '')),
					'iban' => trim((string)($administration['primaryIban'] ?? '')),
					'bic' => trim((string)($administration['primaryBic'] ?? '')),
				],
				static fn (string $value): bool => $value !== ''
			),
			'recipient' => array_filter(
				[
					'legalName' => trim((string)($customer['legalName'] ?? ($customer['tradeName'] ?? ''))),
					'vatNumber' => trim((string)($customer['vatId'] ?? '')),
					'language' => trim((string)($customer['language'] ?? '')),
				],
				static fn (string $value): bool => $value !== ''
			),
			'layout' => $this->layout(administrationId: $administrationId),
			'language' => trim((string)($administration['defaultLanguage'] ?? '')),
		];
	}//end forInvoice()

	/**
	 * The administration's invoice layout, or an empty array when it has none.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
	 */
	private function layout(string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}

		try {
			$rows = $this->scoped(schema: 'InvoiceLayout')->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 1]);
		} catch (Throwable $e) {
			$this->logger->warning('InvoiceDocumentParties: invoice layout unreadable', ['exception' => $e->getMessage()]);
			return [];
		}

		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				return $record;
			}
		}

		return [];
	}//end layout()

	/**
	 * One record by id or business key, or an empty array.
	 *
	 * @param string $schema           The schema.
	 * @param string $id               The id or business key.
	 * @param string $fallbackProperty The business key property.
	 *
	 * @return array<string, mixed>
	 */
	private function one(string $schema, string $id, string $fallbackProperty): array {
		if ($id === '') {
			return [];
		}

		try {
			return (ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: $id, fallbackProperty: $fallbackProperty) ?? []);
		} catch (Throwable $e) {
			$this->logger->warning('InvoiceDocumentParties: record unreadable', ['schema' => $schema, 'exception' => $e->getMessage()]);
			return [];
		}
	}//end one()

	/**
	 * The object service bound to a schema of the register.
	 *
	 * @param string $schema The schema.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
