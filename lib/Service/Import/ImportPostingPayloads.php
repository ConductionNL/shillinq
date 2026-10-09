<?php

/**
 * Import Posting Payloads
 *
 * The records a posted import writes, in the shape the register accepts: the
 * opening JournalEntry, its reversing entry, and a CustomerMaster per imported
 * customer. Pure: nothing here reads or writes the register.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use DomainException;

/**
 * Builds the JournalEntry and CustomerMaster payloads of a posted import.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class ImportPostingPayloads {

	/**
	 * The journal entry's sub-ledger: the import may book on control accounts.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'import';

	/**
	 * XAF relation types that are customers (C customer, B both).
	 *
	 * @var string[]
	 */
	private const CUSTOMER_TYPES = ['C', 'B'];

	/**
	 * The opening entry in state draft, one line per side of each balance.
	 *
	 * @param array<string,mixed> $batch   The batch (id, administrationId, migrationDate, sourceSystem).
	 * @param array<string,mixed> $journal The dry-run's opening journal (lines with targetAccount, debit, credit).
	 *
	 * @return array<string,mixed>|null The JournalEntry payload, or null when there is no balance to book.
	 *
	 * @throws DomainException When a line's account has no mapping.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function openingEntry(array $batch, array $journal): ?array {
		$lines = [];
		foreach (($journal['lines'] ?? []) as $line) {
			$account = trim((string)($line['targetAccount'] ?? ''));
			if ($account === '') {
				throw new DomainException(sprintf('The account %s has no mapping.', (string)($line['sourceAccount'] ?? '')));
			}

			foreach (['debit', 'credit'] as $side) {
				$amount = round((float)($line[$side] ?? 0.0), 2);
				if ($amount > 0) {
					$lines[] = ['accountNumber' => $account, 'side' => $side, 'amount' => $amount, 'description' => 'Opening balance'];
				}
			}
		}

		if (count($lines) < 2) {
			return null;
		}

		$source = trim((string)($batch['sourceSystem'] ?? ''));
		if ($source === '') {
			$source = 'auditfile';
		}

		return $this->entry(
			batch: $batch,
			prefix: 'IMP-',
			description: sprintf('Opening balance from the %s import', $source),
			lines: $lines
		);

	}//end openingEntry()

	/**
	 * The reversing entry of a posted opening entry: the same lines, sides swapped.
	 *
	 * @param array<string,mixed> $batch   The batch.
	 * @param array<string,mixed> $opening The opening JournalEntry as stored.
	 *
	 * @return array<string,mixed> The JournalEntry payload in state draft.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function reversingEntry(array $batch, array $opening): array {
		$lines = [];
		foreach (($opening['lines'] ?? []) as $line) {
			$side = 'debit';
			if (($line['side'] ?? '') === 'debit') {
				$side = 'credit';
			}

			$lines[] = array_merge($line, ['side' => $side, 'description' => 'Reversal of the opening balance']);
		}

		return $this->entry(
			batch: $batch,
			prefix: 'IMP-R-',
			description: sprintf('Reversal of %s', (string)($opening['journalNumber'] ?? 'the imported opening balance')),
			lines: $lines
		);

	}//end reversingEntry()

	/**
	 * The CustomerMaster of an imported customer relation.
	 *
	 * @param array<string,mixed> $relation         The staged relation (code, name, kvk, vat, email, phone, type).
	 * @param string              $administrationId The target administration.
	 *
	 * @return array<string,mixed>|null The payload, or null when the relation is not a customer or has no email.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function customer(array $relation, string $administrationId): ?array {
		$email = trim((string)($relation['email'] ?? ''));
		if ($this->isCustomer(relation: $relation) === false || $email === '') {
			return null;
		}

		$name = trim((string)($relation['name'] ?? ''));
		$code = trim((string)($relation['code'] ?? ''));
		if ($code === '') {
			$code = 'IMP-' . substr(hash('sha256', $name . '|' . $email), 0, 8);
		}

		$customer = [
			'customerId' => $code,
			'legalName' => $name,
			'email' => $email,
			'administrationId' => $administrationId,
			'lifecycleState' => 'active',
		];
		$optional = ['kvkNumber' => 'kvk', 'vatId' => 'vat', 'telephone' => 'phone'];
		foreach ($optional as $field => $source) {
			$value = trim((string)($relation[$source] ?? ''));
			if ($value !== '') {
				$customer[$field] = $value;
			}
		}

		return $customer;

	}//end customer()

	/**
	 * Whether a staged relation is a customer.
	 *
	 * @param array<string,mixed> $relation The staged relation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function isCustomer(array $relation): bool {
		return in_array(strtoupper(trim((string)($relation['type'] ?? ''))), self::CUSTOMER_TYPES, true);

	}//end isCustomer()

	/**
	 * A manual journal entry of the import in state draft.
	 *
	 * @param array<string,mixed>            $batch       The batch.
	 * @param string                         $prefix      The journal number prefix.
	 * @param string                         $description The entry description.
	 * @param array<int,array<string,mixed>> $lines       The lines.
	 *
	 * @return array<string,mixed>
	 */
	private function entry(array $batch, string $prefix, string $description, array $lines): array {
		$batchId = (string)($batch['id'] ?? ($batch['@self']['id'] ?? ''));

		return [
			'journalNumber' => $prefix . substr(hash('sha256', $batchId), 0, 12),
			'entryDate' => substr((string)($batch['migrationDate'] ?? ''), 0, 10),
			'description' => $description,
			'lines' => $lines,
			'journalType' => 'manual',
			'sourceApp' => self::SOURCE_APP,
			'approvalState' => 'not-required',
			'administrationId' => (string)($batch['administrationId'] ?? ''),
			'state' => 'draft',
		];

	}//end entry()
}//end class
