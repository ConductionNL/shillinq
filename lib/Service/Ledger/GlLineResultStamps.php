<?php

/**
 * GL Line Result Stamps
 *
 * Stamps a ledger line with what it cannot know itself: the class of its
 * account (profit and loss or balance sheet) and whether it counts in a
 * result, which depends on the state of its transaction and of the
 * transaction it reverses (reporting-segment-results REQ-RSR-002). The
 * segment aggregations sum only lines of class `pnl` that count.
 *
 * A line counts while its transaction is posted and is not the reversal of a
 * reversed transaction. A reversed transaction and its reversal therefore
 * leave the result together, and a draft line is never stamped at all, so the
 * aggregation filter leaves it out.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ledger;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Stamps accountClass and countsInResult on the lines of a transaction.
 */
class GlLineResultStamps {
	use ReadsSourceRowsInBatches;

	/**
	 * Account types whose lines belong to the profit and loss account.
	 */
	public const PNL_ACCOUNT_TYPES = ['revenue', 'expenses'];

	/**
	 * Account type per administration and account number, read once per call chain.
	 *
	 * @var array<string, string>
	 */
	private array $accountTypes = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param SettingsService        $settings      Resolves the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Stamp every line of one transaction as its state now says.
	 *
	 * A draft transaction is left alone: its lines carry no stamp, which the
	 * aggregation filter already reads as "does not count".
	 *
	 * @param string $transactionId The GLTransaction id.
	 *
	 * @return int The number of lines whose stamp changed.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampTransaction(string $transactionId): int {
		$transaction = $this->transaction(transactionId: $transactionId);
		if ($transaction === null || (string)($transaction['state'] ?? '') === 'draft') {
			return 0;
		}

		$counts = $this->countsInResult(transaction: $transaction);
		$changed = 0;
		foreach ($this->rows(schema: 'GLLine', filters: ['transactionId' => $transactionId]) as $line) {
			if ($this->stamp(line: $line, transaction: $transaction, counts: $counts) === true) {
				$changed++;
			}
		}

		return $changed;

	}//end stampTransaction()

	/**
	 * Stamp a transaction that was reversed, its reversal and the transaction it reverses.
	 *
	 * @param string $transactionId The GLTransaction that moved to reversed.
	 *
	 * @return int The number of lines whose stamp changed.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampReversal(string $transactionId): int {
		$ids = [$transactionId];
		$transaction = $this->transaction(transactionId: $transactionId);
		$reverses = (string)($transaction['reversesTransactionId'] ?? '');
		if ($reverses !== '') {
			$ids[] = $reverses;
		}

		foreach ($this->rows(schema: 'GLTransaction', filters: ['reversesTransactionId' => $transactionId]) as $reversal) {
			$ids[] = ObjectIdentifier::resolve(saved: $reversal);
		}

		$changed = 0;
		foreach (array_unique(array_filter($ids)) as $id) {
			$changed += $this->stampTransaction(transactionId: $id);
		}

		return $changed;

	}//end stampReversal()

	/**
	 * Stamp one line that was written after its transaction was posted.
	 *
	 * Source documents are booked by writing a transaction already in state
	 * posted and then its lines, so no transition fires for them.
	 *
	 * @param array<string, mixed> $line The line as saved, with its id.
	 *
	 * @return bool True when the line was stamped.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function stampLine(array $line): bool {
		$transaction = $this->transaction(transactionId: (string)($line['transactionId'] ?? ''));
		if ($transaction === null || (string)($transaction['state'] ?? '') === 'draft') {
			return false;
		}

		return $this->stamp(line: $line, transaction: $transaction, counts: $this->countsInResult(transaction: $transaction));

	}//end stampLine()

	/**
	 * Whether a transaction's lines count in a result.
	 *
	 * @param array<string, mixed> $transaction The GLTransaction.
	 *
	 * @return bool True for a posted transaction that is not the reversal of a reversed one.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function countsInResult(array $transaction): bool {
		if ((string)($transaction['state'] ?? '') !== 'posted') {
			return false;
		}

		$reverses = (string)($transaction['reversesTransactionId'] ?? '');
		if ($reverses === '') {
			return true;
		}

		$original = $this->transaction(transactionId: $reverses);

		return $original === null || (string)($original['state'] ?? '') !== 'reversed';

	}//end countsInResult()

	/**
	 * The class of an account type.
	 *
	 * @param string $accountType The Account.accountType.
	 *
	 * @return string `pnl` for revenue and expenses, `balance` for everything else.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function accountClass(string $accountType): string {
		if (in_array($accountType, self::PNL_ACCOUNT_TYPES, true) === true) {
			return 'pnl';
		}

		return 'balance';

	}//end accountClass()

	/**
	 * Patch one line's stamp when it differs from what it should be.
	 *
	 * @param mixed                $line        The line row.
	 * @param array<string, mixed> $transaction Its transaction.
	 * @param bool                 $counts      Whether it counts in a result.
	 *
	 * @return bool True when the line was patched.
	 */
	private function stamp(mixed $line, array $transaction, bool $counts): bool {
		$data = $this->rowPayload(row: $line);
		$lineId = ObjectIdentifier::resolve(saved: $line);
		if ($lineId === '') {
			return false;
		}

		$stamp = [
			'accountClass'   => $this->accountClass(
				accountType: $this->accountType(
					administrationId: (string)($transaction['administrationId'] ?? ($data['administrationId'] ?? '')),
					accountNumber: (string)($data['accountNumber'] ?? '')
				)
			),
			'countsInResult' => $counts,
		];
		if (($data['accountClass'] ?? null) === $stamp['accountClass'] && ($data['countsInResult'] ?? null) === $counts) {
			return false;
		}

		$this->objectService->patchObject(
			objectId: $lineId,
			data: $stamp,
			register: $this->settings->getRegisterSlug(),
			schema: 'GLLine',
			_rbac: false,
			_multitenancy: false
		);

		return true;

	}//end stamp()

	/**
	 * The account type of an account number in an administration, '' when unknown.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber    The account number.
	 *
	 * @return string The accountType.
	 */
	private function accountType(string $administrationId, string $accountNumber): string {
		$key = $administrationId . '|' . $accountNumber;
		if (isset($this->accountTypes[$key]) === false) {
			$filters = ['accountNumber' => $accountNumber];
			if ($administrationId !== '') {
				$filters['administrationId'] = $administrationId;
			}

			$rows = $this->rows(schema: 'Account', filters: $filters);
			$type = '';
			if ($rows !== []) {
				$type = (string)($this->rowPayload(row: $rows[0])['accountType'] ?? '');
			}

			$this->accountTypes[$key] = $type;
		}

		return $this->accountTypes[$key];

	}//end accountType()

	/**
	 * One transaction by id, null when it cannot be read.
	 *
	 * @param string $transactionId The GLTransaction id.
	 *
	 * @return array<string, mixed>|null The transaction.
	 */
	private function transaction(string $transactionId): ?array {
		if ($transactionId === '') {
			return null;
		}

		$found = $this->objectService->find(
			id: $transactionId,
			register: $this->settings->getRegisterSlug(),
			schema: 'GLTransaction',
			_rbac: false,
			_multitenancy: false
		);
		if ($found === null) {
			return null;
		}

		return $this->rowPayload(row: $found);

	}//end transaction()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rows(string $schema, array $filters): array {
		return $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->settings->getRegisterSlug(),
			schema: $schema,
			filters: $filters
		);

	}//end rows()
}//end class
