<?php

/**
 * Statement Verify Guard — bookkeeping-reconciliation-reports (T4).
 *
 * Lifecycle preconditions for the `BankReconciliation` register declared in
 * lib/Settings/register.d/bookkeeping-reconciliation-reports.json. Two thin
 * PHP seams referenced by name from the schema's x-openregister-lifecycle
 * `requires:` clauses:
 *
 *   - verifyStatementBalance()       — REQ-REC-002: computes expectedGLBalance
 *                                      from the GL aggregation, derives
 *                                      variance = closingBalance - expected,
 *                                      and persists both back onto the
 *                                      reconciliation. Non-zero variance
 *                                      surfaces a warning but does not block
 *                                      the transition (REQ-REC-002 scenario
 *                                      "variance surfaces warning but allows
 *                                      proceed"). This guard is the single
 *                                      ADR-031 §exception declared in the
 *                                      proposal — GL-balance lookup is a
 *                                      cross-object aggregation the
 *                                      declarative engine cannot yet express.
 *
 *   - requireResolvedAndSignedOff()  — REQ-REC-004 + REQ-REC-006: rejects the
 *                                      verify transition when any
 *                                      ReconciliationMatch on the session has
 *                                      a null resolutionStatus (unclassified)
 *                                      or when signOffComment is empty.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Guards BankReconciliation lifecycle preconditions per REQ-REC-002 and
 * REQ-REC-004 + REQ-REC-006.
 *
 * Both methods are referenced by FQN from
 * `lib/Settings/register.d/bookkeeping-reconciliation-reports.json`. They
 * MUST return `bool` per the OR lifecycle contract: true = transition
 * permitted, false = denied. Both fail-closed on any internal error per
 * ADR-031 §"PHP guards default to deny on failure".
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class StatementVerifyGuard {
	/**
	 * Construct the guard with OR's ObjectService injected (ADR-083 rule 1).
	 *
	 * @param IAppConfig $appConfig App config for dynamic register
	 *                              slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed
	 *                                diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's published
	 *                                             object surface (ADR-084),
	 *                                             aliased in Application.php.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if
	 * unset. Mirrors SettingsService::getRegisterSlug() so every guard reads
	 * the same register.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Verify the statement closing balance against the expected GL balance
	 * on `draft → in-progress`.
	 *
	 * REQ-REC-002 formula:
	 *   expectedGLBalance =
	 *     Account.balanceAtPeriodStart
	 *     + SUM(GLLine.debit - GLLine.credit)
	 *       WHERE GLLine.entryDate BETWEEN statementPeriodStart AND statementPeriodEnd
	 *
	 * The reconciliation's openingBalance is taken to represent
	 * Account.balanceAtPeriodStart for the purposes of T4 (the statement
	 * vendor-supplied opening balance equals the GL opening balance at the
	 * start of the period — any divergence is itself an audit finding that
	 * surfaces through variance).
	 *
	 * Computation runs in integer cents to avoid IEEE-754 float drift per
	 * ADR-005 server authority. The computed expectedGLBalance and
	 * `|closingBalance - expectedGLBalance|` variance are persisted back
	 * onto the reconciliation record before the transition runs.
	 *
	 * Always returns true — per REQ-REC-002 scenario "variance surfaces
	 * warning but allows proceed", a non-zero variance does NOT block the
	 * transition. The warning surfaces via the persisted `variance` field
	 * and the audit-trailed lifecycle event.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object
	 *                                     array as loaded by OR.
	 *
	 * @return bool Always true (per REQ-REC-002) once the variance
	 *              recomputation completes. Returns false only when the
	 *              reconciliation has no id (cannot persist results).
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-002)
	 */
	public function verifyStatementBalance(array $object): bool {
		$id = (string)($object['id'] ?? '');
		if ($id === '') {
			$this->logger->info('StatementVerifyGuard: initiate denied — reconciliation has no id');
			return false;
		}

		try {
			$openingCents = $this->cents(amount: (float)($object['openingBalance'] ?? 0.0));
			$closingCents = $this->cents(amount: (float)($object['closingBalance'] ?? 0.0));

			$netActivityCents = $this->sumGLNetActivityCents(
				bankAccountId: (string)($object['bankAccountId'] ?? ''),
				periodStart: (string)($object['statementPeriodStart'] ?? ''),
				periodEnd: (string)($object['statementPeriodEnd'] ?? ''),
			);

			$expectedCents = ($openingCents + $netActivityCents);
			$varianceCents = ($closingCents - $expectedCents);

			$this->persistVarianceFields(
				id: $id,
				expectedCents: $expectedCents,
				varianceCents: $varianceCents,
			);

			if ($varianceCents !== 0) {
				// Per REQ-REC-002 scenario "variance surfaces warning but allows proceed":
				// we log a warning but do NOT block the transition. The operator
				// sees the variance in the persisted field + the UI surface.
				$this->logger->info(
					'StatementVerifyGuard: statement-balance variance detected on initiate '
					. '(transition allowed; operator must resolve via matching or REQ-REC-004 classification)',
					[
						'reconciliationId' => $id,
						'expectedCents' => $expectedCents,
						'closingCents' => $closingCents,
						'varianceCents' => $varianceCents,
					]
				);
			}
		} catch (\Throwable $e) {
			// Fail-closed: any computation failure denies the transition so
			// the operator can investigate rather than silently proceeding
			// with a stale or zero variance.
			$this->logger->error(
				'StatementVerifyGuard: initiate denied — statement-balance verification failed',
				['exception' => $e->getMessage(), 'reconciliationId' => $id]
			);
			return false;
		}//end try

		return true;
	}//end verifyStatementBalance()

	/**
	 * Reject the `in-progress → verified` transition when unresolved
	 * matches remain or the sign-off comment is empty.
	 *
	 * REQ-REC-004: every ReconciliationMatch on the session must have a
	 * non-null `resolutionStatus` (one of matched, timing, pending,
	 * adjustment).
	 *
	 * REQ-REC-006: the verifier MUST supply a non-empty `signOffComment`
	 * on the reconciliation before the transition runs.
	 *
	 * Fail-closed on any error.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object
	 *                                     array as loaded by OR.
	 *
	 * @return bool True when the transition is permitted, false otherwise.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004, REQ-REC-006)
	 */
	public function requireResolvedAndSignedOff(array $object): bool {
		// REQ-REC-006: sign-off comment required.
		$signOff = trim((string)($object['signOffComment'] ?? ''));
		if ($signOff === '') {
			$this->logger->info(
				'StatementVerifyGuard: verify denied — signOffComment is required (REQ-REC-006)',
				['reconciliationId' => ($object['id'] ?? null)]
			);
			return false;
		}

		$id = (string)($object['id'] ?? '');
		if ($id === '') {
			$this->logger->info('StatementVerifyGuard: verify denied — reconciliation has no id');
			return false;
		}

		try {
			$matches = $this->findMatches(reconId: $id);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatementVerifyGuard: verify denied — match lookup failed (fail-closed)',
				['reconciliationId' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}

		$allowed = ['matched', 'timing', 'pending', 'adjustment'];
		foreach ($matches as $match) {
			$status = (string)($match['resolutionStatus'] ?? '');
			if (in_array($status, $allowed, true) === false) {
				$this->logger->info(
					'StatementVerifyGuard: verify denied — unresolved matches remain (REQ-REC-004)',
					[
						'reconciliationId' => $id,
						'matchId' => ($match['matchId'] ?? $match['id'] ?? null),
						'resolutionStatus' => $status,
					]
				);
				return false;
			}
		}

		return true;
	}//end requireResolvedAndSignedOff()

	/**
	 * Convert a monetary float into integer cents per ADR-005 server
	 * authority — rounded to the nearest cent to avoid IEEE-754 drift.
	 *
	 * @param float $amount The amount in major units (e.g. EUR).
	 *
	 * @return int The amount in cents.
	 */
	private function cents(float $amount): int {
		return (int)round($amount * 100);
	}//end cents()

	/**
	 * Sum SUM(GLLine.debit - GLLine.credit) in cents for the period — the
	 * net GL activity per REQ-REC-002 formula.
	 *
	 * Iterates GLTransaction → GLLine relations bounded by the period dates
	 * and the bank account's GL account. Matches T2 `bookkeeping-bank-reconciliation`'s
	 * own period-bounded aggregation contract.
	 *
	 * @param string $bankAccountId The bank account / IBAN identifier
	 *                              (REQ-REC-001).
	 * @param string $periodStart ISO-8601 date string (YYYY-MM-DD)
	 *                            for the start of the period.
	 * @param string $periodEnd ISO-8601 date string (YYYY-MM-DD)
	 *                          for the end of the period.
	 *
	 * @return int The net GL activity in cents (positive = net debit, i.e.
	 *             cash inflow on an asset account).
	 */
	private function sumGLNetActivityCents(
		string $bankAccountId,
		string $periodStart,
		string $periodEnd,
	): int {
		if ($bankAccountId === '' || $periodStart === '' || $periodEnd === '') {
			return 0;
		}

		// Page through GLLine entries filtered by accountNumber + entryDate
		// window. We use accountNumber == bankAccountId by convention; T4
		// expects bank accounts to share their IBAN/identifier with the GL
		// account number (or to be aliased via a future Account.bankAccountId
		// field — out of scope here).
		$netCents = 0;
		$pageSize = 500;
		$page = 1;
		$batchSize = 0;
		do {
			$batch = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('GLLine')
				->findAll(
					[
						'filters' => [
							'accountNumber' => $bankAccountId,
							'entryDate' => [
								'gte' => $periodStart,
								'lte' => $periodEnd,
							],
						],
						'limit' => $pageSize,
						'offset' => (($page - 1) * $pageSize),
					]
				);

			$batchSize = count($batch);
			foreach ($batch as $line) {
				// Polarity is encoded by the `side` enum on GLLine per
				// REQ-GL-003 — 'debit' contributes positively to net activity
				// on an asset account, 'credit' contributes negatively.
				$amountCents = $this->cents(amount: (float)($line['amount'] ?? 0.0));
				$side = (string)($line['side'] ?? 'debit');
				if ($side === 'credit') {
					$netCents -= $amountCents;
				} else {
					$netCents += $amountCents;
				}
			}

			$page++;
		} while ($batchSize === $pageSize);

		return $netCents;
	}//end sumGLNetActivityCents()

	/**
	 * Persist the recomputed expectedGLBalance and variance back onto the
	 * BankReconciliation record.
	 *
	 * Server-authoritative per ADR-005: derived monetary fields are NEVER
	 * trusted from the client. Stored as decimals (cents/100) for OR
	 * compatibility but compared in cents.
	 *
	 * @param string $id The reconciliation id.
	 * @param int $expectedCents The computed expected GL balance in cents.
	 * @param int $varianceCents The computed variance in cents
	 *                           (closing - expected).
	 *
	 * @return void
	 */
	private function persistVarianceFields(string $id, int $expectedCents, int $varianceCents): void {
		try {
			$this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankReconciliation')
				->updateObject(
					$id,
					[
						'expectedGLBalance' => ($expectedCents / 100),
						'variance' => abs($varianceCents) / 100,
					]
				);
		} catch (\Throwable $e) {
			// Non-fatal: the precondition already computed the variance for
			// the warning surface. Log and continue so the transition can
			// proceed.
			$this->logger->warning(
				'StatementVerifyGuard: variance persistence failed (non-fatal — warning surface still works)',
				['reconciliationId' => $id, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end persistVarianceFields()

	/**
	 * Fetch all ReconciliationMatch records for a reconciliation session,
	 * paging through OR's default page limit.
	 *
	 * @param string $reconId The parent BankReconciliation id.
	 *
	 * @return array<int, array<string, mixed>> The match object arrays.
	 */
	private function findMatches(string $reconId): array {
		$matches = [];
		$pageSize = 500;
		$page = 1;
		$batchSize = 0;
		do {
			$batch = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('ReconciliationMatch')
				->findAll(
					[
						'filters' => ['reconId' => $reconId],
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
}//end class
