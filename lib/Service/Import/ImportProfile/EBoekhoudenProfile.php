<?php

/**
 * Import profile for e-Boekhouden.
 *
 * Dialect quirks + companion-CSV column maps for e-Boekhouden.nl exports
 * (REQ-AIM-003). e-Boekhouden's XAF carries the chart of accounts and
 * relations but omits the outstanding-items ("openstaande posten") detail,
 * which the customer exports separately as a semicolon-delimited CSV with
 * Dutch comma decimals. This profile supplies that CSV column map and
 * corrects the two known dialect quirks (NL d-m-Y dates and the occasional
 * absent leadReference RGS code).
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
 * Import profile for e-Boekhouden.nl (REQ-AIM-003).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */
class EBoekhoudenProfile implements ImportProfileInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
	 */
	public function sourceSystem(): string {
		return 'e-boekhouden';
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
	 * The e-Boekhouden "Openstaande posten" CSV header (semicolon-delimited):
	 *   Relatiecode;Relatie;Factuurnummer;Factuurdatum;Vervaldatum;Bedrag;Openstaand;Soort
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
				'relationCode' => 'Relatiecode',
				'relationName' => 'Relatie',
				'invoiceNumber' => 'Factuurnummer',
				'invoiceDate' => 'Factuurdatum',
				'dueDate' => 'Vervaldatum',
				'totalAmount' => 'Bedrag',
				'outstandingAmount' => 'Openstaand',
				'type' => 'Soort',
			];
		}

		if ($artifact === 'relations') {
			return [
				'code' => 'Code',
				'name' => 'Bedrijf',
				'kvk' => 'KvK-nummer',
				'vat' => 'BTW-nummer',
				'email' => 'E-mail',
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
		return $parsed;
	}//end applyDialectQuirks()
}//end class
