<?php

/**
 * Ledger Seal Service
 *
 * Seals posted GLTransactions into one chain per administration and checks
 * that chain (ledger-sealed-entries). A seal is SHA-256 over the previous
 * seal of the administration and the canonical posted content
 * ({@see LedgerSealCanonicaliser}); the first one chains onto 64 zeros.
 *
 * Sealing reads the chain head and writes the next seal under a lock per
 * administration, so two posts at once never chain onto the same
 * predecessor (design D2). When the lock cannot be taken, or the seal cannot
 * be written, sealing throws: an unsealed posted entry is exactly the gap the
 * chain exists to rule out.
 *
 * The check walks the sealed transactions in sequence order, recomputes each
 * seal from the current content and the stored previous seal, and names the
 * first transaction whose content, link or sequence no longer matches
 * (design D3).
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
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ledger;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Seal and check the posted-transaction chain of an administration.
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 */
class LedgerSealService {
	use ReadsSourceRowsInBatches;

	/**
	 * Lock key prefix, one lock per administration (design D2).
	 *
	 * @var string
	 */
	public const LOCK_PREFIX = 'shillinq-gl-seal-';

	/**
	 * Wire the collaborators.
	 *
	 * @param ObjectServiceInterface  $objectService OpenRegister's object service.
	 * @param IAppConfig              $appConfig     The configured register slug.
	 * @param ILockingProvider        $locks         The lock per administration.
	 * @param LedgerSealCanonicaliser $canonicaliser The sealed content and the seal.
	 * @param LoggerInterface         $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ILockingProvider $locks,
		private readonly LedgerSealCanonicaliser $canonicaliser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seal a posted transaction onto its administration's chain.
	 *
	 * A transaction that already carries a seal keeps it.
	 *
	 * @param array<string,mixed> $transaction The posted GLTransaction, with its id.
	 *
	 * @return array{sealSequence:int, previousSealHash:string, sealHash:string, sealedAt:string} The seal fields.
	 *
	 * @throws RuntimeException When the lock cannot be taken or the seal cannot be written.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-posted-transaction-is-sealed-into-its-administrations-chain-req-lse-001
	 */
	public function seal(array $transaction): array {
		$id = self::idOf(row: $transaction);
		$administrationId = (string)($transaction['administrationId'] ?? '');
		if ($id === '' || $administrationId === '') {
			throw new RuntimeException('ledger_seal_failed: the transaction has no id or administration');
		}

		return $this->underLock(
			administrationId: $administrationId,
			work: function () use ($transaction, $id, $administrationId): array {
				if (is_string($transaction['sealHash'] ?? null) === true && $transaction['sealHash'] !== '') {
					return self::sealFields(row: $transaction);
				}

				return $this->writeSeal(transaction: $transaction, id: $id, head: $this->chainHead(administrationId: $administrationId));
			}
		);
	}//end seal()

	/**
	 * Check an administration's chain.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array{intact:bool, entries:int, transactionNumber:?string, transactionId:?string, reason:?string} The outcome.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
	 */
	public function verify(string $administrationId): array {
		$chain = $this->sealedTransactions(administrationId: $administrationId);
		$previous = LedgerSealCanonicaliser::GENESIS;
		$expected = 1;
		foreach ($chain as $transaction) {
			$reason = $this->breakIn(transaction: $transaction, previous: $previous, expected: $expected);
			if ($reason !== null) {
				return [
					'intact' => false,
					'entries' => count($chain),
					'transactionNumber' => (string)($transaction['transactionNumber'] ?? ''),
					'transactionId' => self::idOf(row: $transaction),
					'reason' => $reason,
				];
			}

			$previous = (string)$transaction['sealHash'];
			$expected++;
		}

		return ['intact' => true, 'entries' => count($chain), 'transactionNumber' => null, 'transactionId' => null, 'reason' => null];
	}//end verify()

	/**
	 * Seal every posted transaction of an administration that has no seals yet,
	 * in posting order (design, Migration Plan).
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return integer The number of transactions sealed.
	 *
	 * @throws RuntimeException When the administration already has seals.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/design.md
	 */
	public function sealExisting(string $administrationId): int {
		$sealed = $this->underLock(
			administrationId: $administrationId,
			work: function () use ($administrationId): int {
				$rows = $this->transactionsOf(administrationId: $administrationId);
				foreach ($rows as $row) {
					if (self::sealSequenceOf(row: $row) !== null) {
						throw new RuntimeException(
							'ledger_already_sealed: administration '.$administrationId.' already has seals; rewriting them must be asked for by a person'
						);
					}
				}

				$posted = array_values(array_filter($rows, static fn (array $row): bool => in_array(($row['state'] ?? ''), ['posted', 'reversed'], true)));
				usort(
					$posted,
					static fn (array $a, array $b): int => [(string)($a['postingDate'] ?? ''), (string)($a['transactionNumber'] ?? '')]
						<=> [(string)($b['postingDate'] ?? ''), (string)($b['transactionNumber'] ?? '')]
				);

				$head = ['sequence' => 0, 'hash' => LedgerSealCanonicaliser::GENESIS];
				foreach ($posted as $transaction) {
					$fields = $this->writeSeal(transaction: $transaction, id: self::idOf(row: $transaction), head: $head);
					$head = ['sequence' => $fields['sealSequence'], 'hash' => $fields['sealHash']];
				}

				return count($posted);
			}
		);

		$this->logger->warning(
			'Shillinq: sealed the existing posted transactions of an administration',
			['app' => AppIdentity::APP_ID, 'administrationId' => $administrationId, 'count' => $sealed]
		);

		return $sealed;
	}//end sealExisting()

	/**
	 * Why a transaction breaks the chain, or null when it holds.
	 *
	 * @param array<string,mixed> $transaction The sealed transaction.
	 * @param string              $previous    The seal of its predecessor.
	 * @param integer             $expected    The sequence it should carry.
	 *
	 * @return string|null `sequence`, `link` or `content`, or null.
	 */
	private function breakIn(array $transaction, string $previous, int $expected): ?string {
		if (self::sealSequenceOf(row: $transaction) !== $expected) {
			return 'sequence';
		}

		if ((string)($transaction['previousSealHash'] ?? '') !== $previous) {
			return 'link';
		}

		$lines = $this->linesOf(transactionId: self::idOf(row: $transaction));
		$recomputed = $this->canonicaliser->seal(
			previousSealHash: $previous,
			canonical: $this->canonicaliser->canonical(transaction: $transaction, lines: $lines)
		);
		if (hash_equals((string)($transaction['sealHash'] ?? ''), $recomputed) === false) {
			return 'content';
		}

		return null;
	}//end breakIn()

	/**
	 * Compute and write the seal of one transaction onto the given head.
	 *
	 * @param array<string,mixed>          $transaction The transaction.
	 * @param string                       $id          Its id.
	 * @param array{sequence:int,hash:string} $head     The chain head it follows.
	 *
	 * @return array{sealSequence:int, previousSealHash:string, sealHash:string, sealedAt:string} The seal fields.
	 *
	 * @throws RuntimeException When the seal cannot be written.
	 */
	private function writeSeal(array $transaction, string $id, array $head): array {
		$canonical = $this->canonicaliser->canonical(transaction: $transaction, lines: $this->linesOf(transactionId: $id));
		$fields = [
			'sealSequence' => ($head['sequence'] + 1),
			'previousSealHash' => $head['hash'],
			'sealHash' => $this->canonicaliser->seal(previousSealHash: $head['hash'], canonical: $canonical),
			'sealedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		try {
			$this->objectService->patchObject(
				objectId: $id,
				data: $fields,
				register: $this->register(),
				schema: 'GLTransaction',
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException('ledger_seal_failed: the seal of transaction '.$id.' could not be written: '.$e->getMessage(), 0, $e);
		}

		return $fields;
	}//end writeSeal()

	/**
	 * The highest sealed sequence of an administration and its seal.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array{sequence:int,hash:string} The chain head; sequence 0 and GENESIS when empty.
	 */
	private function chainHead(string $administrationId): array {
		$chain = $this->sealedTransactions(administrationId: $administrationId);
		$last = end($chain);
		if ($last === false) {
			return ['sequence' => 0, 'hash' => LedgerSealCanonicaliser::GENESIS];
		}

		return ['sequence' => (int)self::sealSequenceOf(row: $last), 'hash' => (string)($last['sealHash'] ?? '')];
	}//end chainHead()

	/**
	 * The sealed transactions of an administration, in sequence order.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>> The sealed transactions.
	 */
	private function sealedTransactions(string $administrationId): array {
		$sealed = array_values(
			array_filter(
				$this->transactionsOf(administrationId: $administrationId),
				static fn (array $row): bool => self::sealSequenceOf(row: $row) !== null
			)
		);
		usort($sealed, static fn (array $a, array $b): int => self::sealSequenceOf(row: $a) <=> self::sealSequenceOf(row: $b));

		return $sealed;
	}//end sealedTransactions()

	/**
	 * Every GLTransaction of an administration, as arrays with their ids.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>> The transactions.
	 */
	private function transactionsOf(string $administrationId): array {
		$rows = $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->register(),
			schema: 'GLTransaction',
			filters: ['administrationId' => $administrationId]
		);

		return $this->payloads(rows: $rows);
	}//end transactionsOf()

	/**
	 * The lines of a transaction.
	 *
	 * @param string $transactionId The transaction id.
	 *
	 * @return list<array<string,mixed>> The GLLine rows.
	 */
	private function linesOf(string $transactionId): array {
		$rows = $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->register(),
			schema: 'GLLine',
			filters: ['transactionId' => $transactionId]
		);

		return $this->payloads(rows: $rows);
	}//end linesOf()

	/**
	 * Rows as arrays.
	 *
	 * @param array<mixed> $rows Rows as OpenRegister returns them.
	 *
	 * @return list<array<string,mixed>> The rows.
	 */
	private function payloads(array $rows): array {
		return array_values(array_map(fn (mixed $row): array => $this->rowPayload(row: $row), $rows));
	}//end payloads()

	/**
	 * Run work under the administration's seal lock.
	 *
	 * @param string   $administrationId The administration.
	 * @param callable $work             The work.
	 *
	 * @return mixed What the work returns.
	 *
	 * @throws RuntimeException When the lock cannot be taken.
	 */
	private function underLock(string $administrationId, callable $work): mixed {
		$lock = self::LOCK_PREFIX.$administrationId;
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'ledger seal of '.$administrationId);
		} catch (LockedException $e) {
			throw new RuntimeException('ledger_seal_locked: another seal of administration '.$administrationId.' is being written', 0, $e);
		}

		try {
			return $work();
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end underLock()

	/**
	 * The seal fields a row already carries.
	 *
	 * @param array<string,mixed> $row The transaction.
	 *
	 * @return array{sealSequence:int, previousSealHash:string, sealHash:string, sealedAt:string} The seal fields.
	 */
	private static function sealFields(array $row): array {
		return [
			'sealSequence' => (int)self::sealSequenceOf(row: $row),
			'previousSealHash' => (string)($row['previousSealHash'] ?? ''),
			'sealHash' => (string)($row['sealHash'] ?? ''),
			'sealedAt' => (string)($row['sealedAt'] ?? ''),
		];
	}//end sealFields()

	/**
	 * A row's seal sequence, or null when it has none.
	 *
	 * @param array<string,mixed> $row The transaction.
	 *
	 * @return integer|null The sequence.
	 */
	private static function sealSequenceOf(array $row): ?int {
		$sequence = ($row['sealSequence'] ?? null);
		if (is_int($sequence) === true || (is_string($sequence) === true && ctype_digit($sequence) === true)) {
			return (int)$sequence;
		}

		return null;
	}//end sealSequenceOf()

	/**
	 * A row's id: `id`, else `@self.id`, else `uuid`.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($row['id'] ?? ($self['id'] ?? ($row['uuid'] ?? '')));
	}//end idOf()

	/**
	 * The configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
