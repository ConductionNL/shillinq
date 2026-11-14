<?php

/**
 * Ledger Sampling
 *
 * Draws an audit sample for a protocol from the posted ledger lines of its
 * administration and audit year, optionally on the accounts whose number
 * starts with a prefix, through AuditSampleDrawer (REQ-011, REQ-012).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\LedgerActuals;

/**
 * The population of posted lines a protocol samples from, and the draw over it.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
class LedgerSampling {

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records Reads the protocol and the ledger.
	 * @param AuditSampleDrawer   $drawer  Draws and stores the sample.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly AuditSampleDrawer $drawer,
	) {
	}//end __construct()

	/**
	 * Draw a sample for the protocol.
	 *
	 * The request holds `size` (the number of lines), `seed` (null for a new
	 * one) and `accountPrefix` ('' for every account).
	 *
	 * @param string               $protocolId The Controleprotocol id.
	 * @param array<string, mixed> $request    The draw.
	 *
	 * @return array{id: string, sampledLineIds: list<string>, reproducibleSeed: string, populationSize: int}
	 *
	 * @throws DomainException When the protocol does not exist or the size does not fit the population.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
	 */
	public function draw(string $protocolId, array $request): array {
		$protocol = $this->records->find(schema: 'Controleprotocol', id: $protocolId);
		if ($protocol === null) {
			throw new DomainException(sprintf('Audit protocol %s does not exist.', $protocolId));
		}

		$prefix = trim((string)($request['accountPrefix'] ?? ''));
		$population = $this->postedLines(protocol: $protocol, accountPrefix: $prefix);
		$year = (int)($protocol['auditYear'] ?? 0);
		$description = sprintf('Posted ledger lines of %d', $year);
		if ($prefix !== '') {
			$description .= sprintf(' on accounts %s…', $prefix);
		}

		$seed = $request['seed'] ?? null;
		$drawn = $this->drawer->draw(
			protocolId: $protocolId,
			population: $population,
			size: (int)($request['size'] ?? 0),
			seed: (is_int($seed) === true ? $seed : null),
			description: $description
		);

		return $drawn + ['populationSize' => count($population)];
	}//end draw()

	/**
	 * The posted lines of the protocol's administration in its audit year.
	 *
	 * @param array<string, mixed> $protocol      The Controleprotocol.
	 * @param string               $accountPrefix '' for every account, else the start of the account number.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function postedLines(array $protocol, string $accountPrefix): array {
		$administrationId = (string)($protocol['organisationId'] ?? '');
		$year = (string)(int)($protocol['auditYear'] ?? 0);
		if ($administrationId === '') {
			return [];
		}

		$posted = [];
		foreach ($this->records->records(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']) as $transaction) {
			if ((string)($transaction['administrationId'] ?? '') === $administrationId
				&& ($transaction['state'] ?? '') === 'posted'
				&& substr((string)($transaction['postingDate'] ?? ''), 0, 4) === $year
			) {
				$posted[] = $transaction;
			}
		}

		$refs = (new LedgerActuals())->postingMonths(transactions: $posted);
		if ($refs === []) {
			return [];
		}

		$lines = [];
		foreach ($this->records->records(schema: 'GLLine', filters: []) as $line) {
			if (isset($refs[(string)($line['transactionId'] ?? '')]) === true
				&& str_starts_with((string)($line['accountNumber'] ?? ''), $accountPrefix) === true
			) {
				$lines[] = $line;
			}
		}

		return $lines;
	}//end postedLines()
}//end class
