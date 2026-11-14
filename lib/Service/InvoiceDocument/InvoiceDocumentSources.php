<?php

/**
 * Loads the records an invoice document is made from.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-administration-sets-its-invoice-layout-req-sid-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * The administration, its layout and logo, the customer and the payment
 * link of one invoice, read from OpenRegister. Every lookup is scoped to the
 * invoice's administration: a record of another administration is a miss.
 * A miss gives an empty answer, so the document still renders in the
 * default layout.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-administration-sets-its-invoice-layout-req-sid-001
 */
class InvoiceDocumentSources {

	/**
	 * The register every record lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The largest logo embedded, in bytes.
	 *
	 * @var integer
	 */
	private const MAX_LOGO_BYTES = 1048576;

	/**
	 * The logo types embedded.
	 *
	 * @var array<int,string>
	 */
	private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/gif'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IRootFolder            $rootFolder    Files, for the logo.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The administration record.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function administration(string $administrationId): array {
		return $this->one(schema: 'Administration', id: $administrationId, administrationId: '');

	}//end administration()

	/**
	 * The administration's InvoiceLayout, or [] when it has none.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function layout(string $administrationId): array {
		if (trim($administrationId) === '') {
			return [];
		}

		$rows = $this->all(schema: 'InvoiceLayout', filters: ['administrationId' => $administrationId]);

		return $rows[0] ?? [];

	}//end layout()

	/**
	 * The customer of the invoice, within its administration.
	 *
	 * @param string $customerId       The customer.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function customer(string $customerId, string $administrationId): array {
		return $this->one(schema: 'CustomerMaster', id: $customerId, administrationId: $administrationId);

	}//end customer()

	/**
	 * The payment link of the invoice's open payment request, or null.
	 *
	 * @param string $invoiceReference The invoice id or number the request names.
	 * @param string $administrationId The administration.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	public function payLink(string $invoiceReference, string $administrationId): ?string {
		if (trim($invoiceReference) === '') {
			return null;
		}

		$rows = $this->all(schema: 'PaymentRequest', filters: ['invoiceReference' => $invoiceReference]);
		foreach ($rows as $row) {
			if ($administrationId !== '' && (string)($row['administrationId'] ?? $administrationId) !== $administrationId) {
				continue;
			}

			$link = trim((string)($row['paymentLink'] ?? ''));
			if ($link !== '' && in_array((string)($row['state'] ?? 'pending'), ['pending', 'authorized'], true) === true) {
				return $link;
			}
		}

		return null;

	}//end payLink()

	/**
	 * The logo as a data URI, so the document never links to it; null for a
	 * missing file, an unknown type or a file over 1 MiB.
	 *
	 * @param string $fileId The Nextcloud file id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function logoDataUri(string $fileId): ?string {
		if (ctype_digit(trim($fileId)) === false) {
			return null;
		}

		try {
			$nodes = $this->rootFolder->getById((int)$fileId);
			$file  = $nodes[0] ?? null;
			if ($file instanceof File === false
				|| in_array($file->getMimeType(), self::LOGO_TYPES, true) === false
				|| $file->getSize() > self::MAX_LOGO_BYTES
			) {
				return null;
			}

			return 'data:'.$file->getMimeType().';base64,'.base64_encode($file->getContent());
		} catch (Throwable $e) {
			return null;
		}

	}//end logoDataUri()

	/**
	 * One record by id, or [] on a miss or another administration's record.
	 *
	 * @param string $schema           The schema.
	 * @param string $id               The id.
	 * @param string $administrationId The administration it must belong to, '' for none.
	 *
	 * @return array<string,mixed>
	 */
	private function one(string $schema, string $id, string $administrationId): array {
		if (trim($id) === '') {
			return [];
		}

		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister(self::REGISTER)->setSchema($schema),
				id: $id
			);
		} catch (Throwable $e) {
			return [];
		}

		$row = $this->asArray(row: $found);
		if ($administrationId !== '' && (string)($row['administrationId'] ?? '') !== $administrationId) {
			return [];
		}

		return $row;

	}//end one()

	/**
	 * Every record of a schema matching the filters.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema, array $filters): array {
		try {
			$rows = $this->objectService->setRegister(self::REGISTER)->setSchema($schema)->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			$array = $this->asArray(row: $row);
			if ($array !== []) {
				$out[] = $array;
			}
		}

		return $out;

	}//end all()

	/**
	 * An entity or array as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];

	}//end asArray()
}//end class
