<?php

/**
 * Journal Entry Guard
 *
 * ADR-031 exception-path lifecycle guards for the JournalEntry register
 * (bookkeeping-journal-entries, T1 foundation). Two preconditions are
 * referenced from the JournalEntry schema's x-openregister-lifecycle
 * transitions because they require cross-line aggregation / cross-schema
 * lookups that OpenRegister's declarative `requires:` clause cannot yet
 * express:
 *
 *  - canPost():  the embedded `lines` must balance (sum of debit amounts
 *                equals sum of credit amounts) before a journal may
 *                materialise its GLTransaction (REQ-JE-007). This mirrors
 *                BalanceGuard but operates on the JournalEntry's own
 *                embedded line preview rather than persisted GLLine rows.
 *  - canVoid():  a posted journal may only be voided once its materialised
 *                GLTransaction has been reversed (REQ-JE-010).
 *
 * ADR-031 exception reason: cross-line SUM and cross-schema state lookups
 * are not yet expressible in the declarative lifecycle DSL. When the engine
 * gains those capabilities, replace these references with declarative
 * conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for JournalEntry post and void transitions.
 *
 * Referenced from the JournalEntry schema (register.d fragment)
 * x-openregister-lifecycle transitions.{post,postDirect}.requires as
 * OCA\Shillinq\Lifecycle\JournalEntryGuard::canPost and transitions.void.requires
 * as OCA\Shillinq\Lifecycle\JournalEntryGuard::canVoid.
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */
class JournalEntryGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the journal entry's embedded lines balance (debit = credit).
	 *
	 * REQ-JE-007: posting a journal materialises exactly one balanced
	 * GLTransaction or fails without partial state. The balance is computed
	 * over the JournalEntry's embedded `lines` preview using integer-cent
	 * arithmetic to avoid IEEE-754 float equality issues.
	 *
	 * Fail-closed: returns false on any exception or malformed input
	 * (REQ-JE-007 / CWE-863).
	 *
	 * @param string $journalEntryId The JournalEntry.id (unused;
	 *                               present for the
	 *                               lifecycle-engine call
	 *                               signature parity with
	 *                               BalanceGuard).
	 * @param array<string,mixed>|null $object The JournalEntry object being transitioned.
	 *
	 * @return bool True when the journal entry's lines balance and it may post.
	 *
	 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
	 */
	public function canPost(string $journalEntryId, ?array $object = null): bool {
		try {
			$lines = $this->resolveLines(journalEntryId: $journalEntryId, object: $object);
			if (count($lines) < 2) {
				return false;
			}

			$debitCents = 0;
			$creditCents = 0;
			foreach ($lines as $line) {
				if (is_array($line) === false) {
					return false;
				}

				$cents = (int)round((float)($line['amount'] ?? 0) * 100);
				if ($cents < 0) {
					return false;
				}

				if (($line['side'] ?? '') === 'debit') {
					$debitCents += $cents;
					continue;
				}

				if (($line['side'] ?? '') === 'credit') {
					$creditCents += $cents;
					continue;
				}

				// Unknown side value — fail closed.
				return false;
			}//end foreach

			return $debitCents > 0 && $debitCents === $creditCents;
		} catch (\Throwable $e) {
			$this->logger->error(
				'JournalEntryGuard: post balance check failed — denying post transition (fail-closed)',
				['journalEntryId' => $journalEntryId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canPost()

	/**
	 * Returns true iff the journal's materialised GLTransaction is already reversed.
	 *
	 * REQ-JE-010: voiding a posted journal requires the materialised
	 * GLTransaction to already be in lifecycle state `reversed`. Without a
	 * materialised transaction (glTransactionId unset) the void is denied —
	 * there is nothing to reverse, so the journal should not be voided.
	 *
	 * Fail-closed: returns false on any exception (REQ-JE-010 / CWE-863).
	 *
	 * @param string $journalEntryId The JournalEntry.id (unused; call-signature parity).
	 * @param array<string,mixed>|null $object The JournalEntry object being transitioned.
	 *
	 * @return bool True when the journal may be voided.
	 *
	 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
	 */
	public function canVoid(string $journalEntryId, ?array $object = null): bool {
		try {
			$glTransactionId = '';
			if ($object !== null && isset($object['glTransactionId']) === true) {
				$glTransactionId = (string)$object['glTransactionId'];
			}

			if ($glTransactionId === '') {
				return false;
			}

			$register = $this->resolveRegister();

			$transactions = $this->objectService
				->setRegister($register)
				->setSchema('GLTransaction')
				->findAll(['filters' => ['id' => $glTransactionId]]);

			foreach ($transactions as $transaction) {
				if (is_array($transaction) === true && ($transaction['state'] ?? '') === 'reversed') {
					return true;
				}
			}

			return false;
		} catch (\Throwable $e) {
			$this->logger->error(
				'JournalEntryGuard: void check failed — denying void transition (fail-closed)',
				['journalEntryId' => $journalEntryId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canVoid()

	/**
	 * Resolve the journal entry's embedded `lines`, preferring the supplied
	 * object and falling back to an ObjectService lookup by id.
	 *
	 * @param string $journalEntryId The JournalEntry.id to look up if no object given.
	 * @param array<string,mixed>|null $object The in-flight object, if provided by the engine.
	 *
	 * @return array<int,mixed> The embedded line rows (possibly empty).
	 */
	private function resolveLines(string $journalEntryId, ?array $object): array {
		if ($object !== null && isset($object['lines']) === true && is_array($object['lines']) === true) {
			return array_values($object['lines']);
		}

		if ($journalEntryId === '') {
			return [];
		}

		$register = $this->resolveRegister();

		$entries = $this->objectService
			->setRegister($register)
			->setSchema('JournalEntry')
			->findAll(['filters' => ['id' => $journalEntryId]]);

		foreach ($entries as $entry) {
			if (is_array($entry) === true && isset($entry['lines']) === true && is_array($entry['lines']) === true) {
				return array_values($entry['lines']);
			}
		}

		return [];
	}//end resolveLines()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
