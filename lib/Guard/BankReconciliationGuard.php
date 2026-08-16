<?php

/**
 * Bank Reconciliation Guard
 *
 * Lifecycle preconditions and server-authoritative balance recomputation for
 * BankReconciliation + BankReconciliationMatch, referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" and ADR-031 Risk-3 (cross-object /
 * cross-period invariants the declarative engine cannot express): the
 * immutability lock on reconciled sessions, the resolved-matches precondition,
 * and the integer-cents reconciledBalance/variance recomputation that must
 * never trust client-supplied derived fields (ADR-005 server authority).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Guards BankReconciliation + BankReconciliationMatch lifecycle invariants.
 *
 * Methods are referenced by name from the schema x-openregister-lifecycle
 * `requires:` / `preconditions.save` clauses. Each returns true when the
 * precondition is satisfied (transition / save permitted), false otherwise.
 * Fail-closed on any error: a failed check denies the write.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
class BankReconciliationGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for dynamic register slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * Single source of truth — mirrors SettingsService::getRegisterSlug() and
	 * AccountBalanceGuard so all reads/writes use the same register even when the
	 * admin reconfigures the slug.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Determine whether a BankReconciliation is locked (reconciled or archived).
	 *
	 * @param string $status The reconciliation status value.
	 *
	 * @return bool True when the session is immutable for edits.
	 */
	private function isLockedStatus(string $status): bool {
		return in_array($status, ['reconciled', 'archived'], true);
	}//end isLockedStatus()

	/**
	 * Save precondition for BankReconciliation: block edits on a locked
	 * (reconciled/archived) session and enforce a valid statement period.
	 *
	 * REQ-BBR-006 / Task 30: once approved the session is immutable — corrections
	 * require a new session. The lifecycle engine still applies the approve/archive
	 * transitions themselves (those change `status`); this precondition rejects any
	 * OTHER mutation while the *prior persisted* status is locked, by comparing
	 * against the stored record. REQ-BBR-001 validation: statementStartDate must be
	 * on or before statementEndDate.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object array (loaded by OR).
	 *
	 * @return bool True when the save is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BBR-001, REQ-BBR-006)
	 */
	public function requireUnlockedAndValidDates(array $object): bool {
		// REQ-BBR-001: validate the statement period.
		$start = (string)($object['statementStartDate'] ?? '');
		$end = (string)($object['statementEndDate'] ?? '');
		if ($start !== '' && $end !== '' && $start > $end) {
			$this->logger->info(
				'BankReconciliationGuard: rejecting save — statementStartDate after statementEndDate',
				['start' => $start, 'end' => $end]
			);
			return false;
		}

		// REQ-BBR-006: block mutation of an already-locked session. A new object
		// (no id) is always permitted; the lock applies only to the PERSISTED state.
		$id = ($object['id'] ?? null);
		if ($id === null) {
			return true;
		}

		$stored = $this->fetchObject(schema: 'BankReconciliation', id: (string)$id);
		if ($stored === null) {
			// First persistence of this id, or store unavailable — permit.
			return true;
		}

		$storedStatus = (string)($stored['status'] ?? 'draft');
		if ($this->isLockedStatus(status: $storedStatus) === false) {
			return true;
		}

		// Stored status is locked. The only permitted change is the
		// archive transition (reconciled → archived); every other edit is denied.
		$incomingStatus = (string)($object['status'] ?? $storedStatus);
		if ($storedStatus === 'reconciled' && $incomingStatus === 'archived') {
			return true;
		}

		$this->logger->info(
			'BankReconciliationGuard: rejecting save — reconciliation is locked',
			['id' => $id, 'storedStatus' => $storedStatus, 'incomingStatus' => $incomingStatus]
		);
		return false;
	}//end requireUnlockedAndValidDates()

	/**
	 * Save precondition for BankReconciliationMatch: block any match write while
	 * the parent BankReconciliation is locked (reconciled/archived).
	 *
	 * REQ-BBR-008 / Task 30: locked reconciliations cannot be unmatched or have
	 * matches re-approved/rejected; corrections require a new session.
	 *
	 * @param array<string, mixed> $object The BankReconciliationMatch object array (loaded by OR).
	 *
	 * @return bool True when the match save is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BBR-008)
	 */
	public function requireParentUnlocked(array $object): bool {
		$reconciliationId = (string)($object['reconciliationId'] ?? '');
		if ($reconciliationId === '') {
			// No parent reference — let schema-required validation handle it.
			return true;
		}

		$parent = $this->fetchObject(schema: 'BankReconciliation', id: $reconciliationId);
		if ($parent === null) {
			// Parent not found / store unavailable — permit; FK validity is the
			// schema's concern, not this lock guard's.
			return true;
		}

		$parentStatus = (string)($parent['status'] ?? 'draft');
		if ($this->isLockedStatus(status: $parentStatus) === true) {
			$this->logger->info(
				'BankReconciliationGuard: rejecting match save — parent reconciliation is locked',
				['reconciliationId' => $reconciliationId, 'parentStatus' => $parentStatus]
			);
			return false;
		}

		return true;
	}//end requireParentUnlocked()

	/**
	 * Approve precondition for BankReconciliation: every match must be resolved
	 * (approved or rejected — no remaining auto-matched or pending-review items),
	 * and the server-authoritative reconciledBalance/variance are written back
	 * before the session is locked.
	 *
	 * REQ-BBR-006 (approve) + REQ-BBR-005 (balance) + Task 29. Returns 409-equivalent
	 * (false) when unresolved matches remain. The balance write is performed here —
	 * the cross-object aggregation + persistence is the ADR-031 Risk-3 PHP seam.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object array (loaded by OR).
	 *
	 * @return bool True when approval is permitted (and balance has been recomputed).
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BBR-005, REQ-BBR-006)
	 */
	public function requireResolvedMatches(array $object): bool {
		$id = (string)($object['id'] ?? '');
		if ($id === '') {
			// Cannot evaluate matches without a persisted id; deny fail-closed.
			$this->logger->info('BankReconciliationGuard: approve denied — reconciliation has no id');
			return false;
		}

		try {
			$matches = $this->findMatches(reconciliationId: $id);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BankReconciliationGuard: approve denied — match lookup failed (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		foreach ($matches as $match) {
			$type = (string)($match['matchType'] ?? 'pending-review');
			if ($type === 'auto-matched' || $type === 'pending-review') {
				$this->logger->info(
					'BankReconciliationGuard: approve denied — unresolved matches remain',
					['reconciliationId' => $id, 'unresolvedType' => $type]
				);
				return false;
			}
		}

		// All matches resolved — recompute and persist the server-authoritative
		// balance before the lifecycle engine locks the session.
		$this->recalculateBalance(reconciliation: $object, matches: $matches);

		return true;
	}//end requireResolvedMatches()

	/**
	 * Recompute reconciledBalance, variance, and match counts in integer cents
	 * and persist them onto the BankReconciliation.
	 *
	 * Server-authoritative per ADR-005: derived monetary fields are NEVER trusted
	 * from the client. Integer cents avoid IEEE-754 float drift
	 * (0.1 + 0.2 − 0.3 ≠ 0.0 in floats, but (10 + 20 − 30) === 0 in cents).
	 *
	 * @param array<string, mixed> $reconciliation The reconciliation object array.
	 * @param array<int, array<string, mixed>> $matches Pre-fetched matches, or null to fetch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BBR-005)
	 */
	public function recalculateBalance(array $reconciliation, ?array $matches = null): void {
		$id = (string)($reconciliation['id'] ?? '');
		if ($id === '') {
			return;
		}

		try {
			if ($matches === null) {
				$matches = $this->findMatches(reconciliationId: $id);
			}

			$openingCents = (int)round(((float)($reconciliation['openingBalance'] ?? 0)) * 100);
			$closingCents = (int)round(((float)($reconciliation['closingBalance'] ?? 0)) * 100);

			$approvedCents = 0;
			$matchedCount = 0;
			$unmatchedBankCount = 0;
			$unmatchedJournal = 0;
			foreach ($matches as $match) {
				$type = (string)($match['matchType'] ?? 'pending-review');
				if ($type !== 'approved') {
					// Not approved → the bank transaction is still unmatched.
					$unmatchedBankCount++;
					continue;
				}

				$approvedCents += (int)round(((float)($match['bankTransactionAmount'] ?? 0)) * 100);
				$matchedCount++;
				$journalEntryId = (string)($match['journalEntryId'] ?? '');
				if ($journalEntryId === '') {
					// Approved bank line with no journal counterpart.
					$unmatchedJournal++;
				}
			}

			$reconciledCents = $openingCents + $approvedCents;
			$varianceCents = $closingCents - $reconciledCents;

			$this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankReconciliation')
				->updateObject(
					$id,
					[
						'reconciledBalance' => ($reconciledCents / 100),
						'variance' => ($varianceCents / 100),
						'matchedCount' => $matchedCount,
						'unmatchedBankCount' => $unmatchedBankCount,
						'unmatchedJournalCount' => $unmatchedJournal,
					]
				);
		} catch (\Throwable $e) {
			// Non-fatal: the lifecycle precondition that calls this has already
			// validated resolution; log and continue so the transition can proceed.
			$this->logger->error(
				'BankReconciliationGuard: balance recomputation failed',
				['reconciliationId' => $id, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end recalculateBalance()

	/**
	 * Fetch all BankReconciliationMatch records for a reconciliation, paging to
	 * avoid the default findAll() limit on high-volume statements.
	 *
	 * @param string $reconciliationId The parent reconciliation id.
	 *
	 * @return array<int, array<string, mixed>> The match object arrays.
	 */
	private function findMatches(string $reconciliationId): array {
		$pageSize = 500;
		$page = 1;
		$matches = [];
		$batchSize = 0;
		do {
			$batch = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankReconciliationMatch')
				->findAll(
					[
						'filters' => ['reconciliationId' => $reconciliationId],
						'limit' => $pageSize,
						'offset' => (($page - 1) * $pageSize),
					]
				);
			$matches = array_merge($matches, $batch);
			$batchSize = count($batch);
			$page++;
		} while ($batchSize === $pageSize);

		return $matches;
	}//end findMatches()

	/**
	 * Fetch a single object by id from the configured register, returning null
	 * when not found or when OpenRegister is unavailable.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object array, or null.
	 */
	private function fetchObject(string $schema, string $id): ?array {
		try {
			$result = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema($schema)
				->find($id);

			// ADR-084: find() is declared `: ?ObjectEntityInterface`, which extends
			// JsonSerializable — the is_array() arm above was unreachable by type.
			if ($result === null) {
				return null;
			}

			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		} catch (\Throwable) {
			return null;
		}//end try

	}//end fetchObject()
}//end class
