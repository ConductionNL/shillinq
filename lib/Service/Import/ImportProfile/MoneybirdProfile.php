<?php

/**
 * Moneybird Import Profile
 *
 * Dialect quirks + companion-CSV column maps for Moneybird exports
 * (REQ-AIM-003). Moneybird's XAF carries the ledger and relations but does
 * NOT include open items in the auditfile — the customer exports them from
 * the "Openstaande facturen" overview as a comma-delimited CSV with English
 * column names, ISO dates, and dot decimals. Moneybird also omits the
 * leadReference RGS element on some accounts (it is optional in their export),
 * so RGS-based auto-mapping degrades to code/name suggestions for those rows.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import\ImportProfile
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import\ImportProfile;

use OCA\Shillinq\Service\Import\ImportProfileInterface;

/**
 * Moneybird import profile (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */
class MoneybirdProfile implements ImportProfileInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string {
		return 'moneybird';
	}//end sourceSystem()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function normalizeLedgerAccounts(array $parsed): array {
		return ($parsed['ledgerAccounts'] ?? []);
	}//end normalizeLedgerAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * Moneybird "Openstaande facturen" CSV header (comma-delimited):
	 *   Contact,ContactID,InvoiceID,InvoiceDate,DueDate,TotalPriceIncl,OutstandingAmount,Type
	 *
	 * @param string $artifact Artifact kind.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function mapCsvColumns(string $artifact): array {
		if ($artifact === 'open-items') {
			return [
				'relationName' => 'Contact',
				'relationCode' => 'ContactID',
				'invoiceNumber' => 'InvoiceID',
				'invoiceDate' => 'InvoiceDate',
				'dueDate' => 'DueDate',
				'totalAmount' => 'TotalPriceIncl',
				'outstandingAmount' => 'OutstandingAmount',
				'type' => 'Type',
			];
		}

		if ($artifact === 'relations') {
			return [
				'code' => 'ContactID',
				'name' => 'CompanyName',
				'kvk' => 'ChamberOfCommerceID',
				'vat' => 'TaxNumber',
				'email' => 'SendInvoicesToEmail',
				'phone' => 'PhoneNumber',
			];
		}

		return [];
	}//end mapCsvColumns()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function applyDialectQuirks(array $parsed): array {
		return $parsed;
	}//end applyDialectQuirks()
}//end class
