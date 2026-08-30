<?php

/**
 * SnelStart Import Profile
 *
 * Dialect quirks + companion-CSV column maps for SnelStart exports
 * (REQ-AIM-003). SnelStart emits a valid XAF but its open-items export
 * ("Openstaande posten") is a semicolon-delimited CSV with Dutch column
 * headers, d-m-Y dates, and comma decimals. SnelStart also prefixes some
 * ledger codes with a grootboek-rubriek letter that must be stripped before
 * code matching.
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
 * SnelStart import profile (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */
class SnelstartProfile implements ImportProfileInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string {
		return 'snelstart';
	}//end sourceSystem()

	/**
	 * {@inheritDoc}
	 *
	 * SnelStart may prefix a ledger code with a single rubriek letter
	 * (e.g. "G1300"); strip a leading non-digit so codes align with the chart.
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
				$account['code'] = preg_replace('/^[A-Za-z]+/', '', (string)$account['code']);
			}
		}

		unset($account);
		return $accounts;
	}//end normalizeLedgerAccounts()

	/**
	 * {@inheritDoc}
	 *
	 * SnelStart "Openstaande posten" CSV header (semicolon-delimited):
	 *   Relatienr;Relatie;Boekstuk;Boekdatum;Vervaldatum;Bedrag;Saldo;Dagboek
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
				'relationCode' => 'Relatienr',
				'relationName' => 'Relatie',
				'invoiceNumber' => 'Boekstuk',
				'invoiceDate' => 'Boekdatum',
				'dueDate' => 'Vervaldatum',
				'totalAmount' => 'Bedrag',
				'outstandingAmount' => 'Saldo',
				'type' => 'Dagboek',
			];
		}

		if ($artifact === 'relations') {
			return [
				'code' => 'Relatienr',
				'name' => 'Naam',
				'kvk' => 'KvKnummer',
				'vat' => 'BTWnummer',
				'email' => 'Email',
				'phone' => 'Telefoon',
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
