<?php

/**
 * Exact Online Import Profile
 *
 * Dialect quirks + companion-CSV column maps for Exact Online exports
 * (REQ-AIM-003). Exact's XAF is close to standard but emits ledger codes
 * zero-padded to a fixed width and exports the open-items ("Openstaande
 * posten debiteuren/crediteuren") as a comma-delimited CSV with English-style
 * dot decimals and ISO dates. This profile supplies the open-items column map
 * and the relations column map.
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
 * Exact Online import profile (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ExactOnlineProfile implements ImportProfileInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string {
		return 'exact-online';
	}//end sourceSystem()

	/**
	 * {@inheritDoc}
	 *
	 * Exact zero-pads ledger codes to a fixed width; left-trim the zeros so
	 * RGS / code matching aligns with Shillinq's chart.
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function normalizeLedgerAccounts(array $parsed): array {
		$accounts = ($parsed['ledgerAccounts'] ?? []);
		foreach ($accounts as &$account) {
			if (isset($account['code']) === true && $account['code'] !== '') {
				$trimmed = ltrim((string)$account['code'], '0');
				if ($trimmed === '') {
					$account['code'] = '0';
				} else {
					$account['code'] = $trimmed;
				}
			}
		}

		unset($account);
		return $accounts;
	}//end normalizeLedgerAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * Exact Online "Openstaande posten" CSV header (comma-delimited):
	 *   AccountCode,AccountName,InvoiceNumber,InvoiceDate,DueDate,AmountDC,OutstandingDC,Type
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
				'relationCode' => 'AccountCode',
				'relationName' => 'AccountName',
				'invoiceNumber' => 'InvoiceNumber',
				'invoiceDate' => 'InvoiceDate',
				'dueDate' => 'DueDate',
				'totalAmount' => 'AmountDC',
				'outstandingAmount' => 'OutstandingDC',
				'type' => 'Type',
			];
		}

		if ($artifact === 'relations') {
			return [
				'code' => 'Code',
				'name' => 'Name',
				'kvk' => 'ChamberOfCommerce',
				'vat' => 'VATNumber',
				'email' => 'Email',
				'phone' => 'Phone',
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
		$parsed['ledgerAccounts'] = $this->normalizeLedgerAccounts(parsed: $parsed);
		return $parsed;
	}//end applyDialectQuirks()
}//end class
