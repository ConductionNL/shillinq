<?php

/**
 * Statement Verify Guard — bookkeeping-reconciliation-reports (T4).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
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
	 * Page size for the paged lookups (stays under OR's default page limit).
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Resolution statuses that count as resolved (REQ-REC-004).
	 *
	 * @var array<int, string>
	 */
	private const RESOLVED_STATUSES = ['matched', 'timing', 'pending', 'adjustment'];

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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
			$this->recordVariance(object: $object, id: $id);
		} catch (\Throwable $e) {
			return $this->verificationFailed(id: $id, e: $e);
		}

		return true;
	}//end verifyStatementBalance()

	/**
	 * Fail-closed: any computation failure denies the transition so the operator
	 * can investigate rather than silently proceeding with a stale or zero variance.
	 *
	 * @param string     $id The reconciliation id.
	 * @param \Throwable $e  The failure.
	 *
	 * @return bool Always false.
	 */
	private function verificationFailed(string $id, \Throwable $e): bool {
		$this->logger->error(
			'StatementVerifyGuard: initiate denied — statement-balance verification failed',
			['exception' => $e->getMessage(), 'reconciliationId' => $id]
		);

		return false;
	}//end verificationFailed()

	/**
	 * Compute and persist the expected balance and variance; a non-zero variance
	 * is logged but does NOT block the transition (REQ-REC-002 scenario "variance
	 * surfaces warning but allows proceed") — the operator sees the persisted field.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object array.
	 * @param string               $id     Its id.
	 *
	 * @return void
	 */
	private function recordVariance(array $object, string $id): void {
		$figures = $this->balanceFigures(object: $object);
		$this->persistVarianceFields(id: $id, figures: $figures);
		if ($figures['varianceCents'] !== 0) {
			$this->logger->info(
				'StatementVerifyGuard: statement-balance variance detected on initiate '
				. '(transition allowed; operator must resolve via matching or REQ-REC-004 classification)',
				['reconciliationId' => $id] + $figures
			);
		}
	}//end recordVariance()

	/**
	 * expected = opening + net GL activity, variance = closing - expected, all in cents.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object array.
	 *
	 * @return array{expectedCents: int, closingCents: int, varianceCents: int}
	 */
	private function balanceFigures(array $object): array {
		$openingCents = $this->cents(amount: (float)($object['openingBalance'] ?? 0.0));
		$closingCents = $this->cents(amount: (float)($object['closingBalance'] ?? 0.0));
		$expectedCents = ($openingCents + $this->sumGLNetActivityCents(object: $object));

		return ['expectedCents' => $expectedCents, 'closingCents' => $closingCents, 'varianceCents' => ($closingCents - $expectedCents)];
	}//end balanceFigures()

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
		if ($this->hasSignOff(object: $object) === false) {
			return false;
		}

		$id = (string)($object['id'] ?? '');
		if ($id === '') {
			$this->logger->info('StatementVerifyGuard: verify denied — reconciliation has no id');
			return false;
		}

		$matches = $this->lookedUpMatches(reconId: $id);

		return $matches !== null && $this->allResolved(reconId: $id, matches: $matches);
	}//end requireResolvedAndSignedOff()

	/**
	 * REQ-REC-006: the sign-off comment is required; a refusal is logged.
	 *
	 * @param array<string, mixed> $object The BankReconciliation object array.
	 *
	 * @return bool
	 */
	private function hasSignOff(array $object): bool {
		if (trim((string)($object['signOffComment'] ?? '')) !== '') {
			return true;
		}

		$this->logger->info(
			'StatementVerifyGuard: verify denied — signOffComment is required (REQ-REC-006)',
			['reconciliationId' => ($object['id'] ?? null)]
		);
		return false;
	}//end hasSignOff()

	/**
	 * The session's matches; a failed lookup is logged.
	 *
	 * @param string $reconId The reconciliation id.
	 *
	 * @return array<int, mixed>|null The matches, or null when the lookup failed.
	 */
	private function lookedUpMatches(string $reconId): ?array {
		try {
			return $this->findMatches(reconId: $reconId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatementVerifyGuard: verify denied — match lookup failed (fail-closed)',
				['reconciliationId' => $reconId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end lookedUpMatches()

	/**
	 * Whether every match carries a resolved status; the first that does not is logged.
	 *
	 * @param string            $reconId The reconciliation id (log context).
	 * @param array<int, mixed> $matches The matches.
	 *
	 * @return bool
	 */
	private function allResolved(string $reconId, array $matches): bool {
		foreach ($matches as $match) {
			$status = (string)($match['resolutionStatus'] ?? '');
			if (in_array($status, self::RESOLVED_STATUSES, true) === false) {
				$this->logger->info(
					'StatementVerifyGuard: verify denied — unresolved matches remain (REQ-REC-004)',
					['reconciliationId' => $reconId, 'matchId' => ($match['matchId'] ?? $match['id'] ?? null), 'resolutionStatus' => $status]
				);
				return false;
			}
		}

		return true;
	}//end allResolved()

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
	 * GLLine entries are filtered by accountNumber + entryDate window. We use
	 * accountNumber == bankAccountId by convention; T4 expects bank accounts to
	 * share their IBAN/identifier with the GL account number (or to be aliased
	 * via a future Account.bankAccountId field — out of scope here).
	 *
	 * @param array<string, mixed> $object The BankReconciliation: bankAccountId (REQ-REC-001)
	 *                                     and statementPeriodStart / statementPeriodEnd (YYYY-MM-DD).
	 *
	 * @return int The net GL activity in cents (positive = net debit, i.e.
	 *             cash inflow on an asset account).
	 */
	private function sumGLNetActivityCents(array $object): int {
		$bankAccountId = (string)($object['bankAccountId'] ?? '');
		$periodStart = (string)($object['statementPeriodStart'] ?? '');
		$periodEnd = (string)($object['statementPeriodEnd'] ?? '');
		if ($bankAccountId === '' || $periodStart === '' || $periodEnd === '') {
			return 0;
		}

		$netCents = 0;
		$filters = ['accountNumber' => $bankAccountId, 'entryDate' => ['gte' => $periodStart, 'lte' => $periodEnd]];
		foreach ($this->pages(schema: 'GLLine', filters: $filters) as $batch) {
			$netCents += $this->batchNetCents(batch: $batch);
		}

		return $netCents;
	}//end sumGLNetActivityCents()

	/**
	 * The signed cents of one page of GLLines.
	 *
	 * @param array<int, mixed> $batch The GLLine rows.
	 *
	 * @return int
	 */
	private function batchNetCents(array $batch): int {
		$netCents = 0;
		foreach ($batch as $line) {
			$netCents += $this->signedCents(line: $line);
		}

		return $netCents;
	}//end batchNetCents()

	/**
	 * A GLLine's amount in cents, signed by its `side` (REQ-GL-003): 'debit' adds
	 * to net activity on an asset account, 'credit' subtracts.
	 *
	 * @param mixed $line A GLLine row.
	 *
	 * @return int
	 */
	private function signedCents(mixed $line): int {
		$amountCents = $this->cents(amount: (float)($line['amount'] ?? 0.0));
		if ((string)($line['side'] ?? 'debit') === 'credit') {
			return -$amountCents;
		}

		return $amountCents;
	}//end signedCents()

	/**
	 * Persist the recomputed expectedGLBalance and variance back onto the
	 * BankReconciliation record.
	 *
	 * Server-authoritative per ADR-005: derived monetary fields are NEVER
	 * trusted from the client. Stored as decimals (cents/100) for OR
	 * compatibility but compared in cents.
	 *
	 * @param string                                                         $id      The reconciliation id.
	 * @param array{expectedCents: int, closingCents: int, varianceCents: int} $figures The computed figures
	 *                                                                                  (variance = closing - expected).
	 *
	 * @return void
	 */
	private function persistVarianceFields(string $id, array $figures): void {
		try {
			$this->writeVariance(id: $id, figures: $figures);
		} catch (\Throwable $e) {
			// Non-fatal: the precondition already computed the variance for
			// the warning surface. Log and continue so the transition can proceed.
			$this->logger->warning(
				'StatementVerifyGuard: variance persistence failed (non-fatal — warning surface still works)',
				['reconciliationId' => $id, 'exception' => $e->getMessage()]
			);
		}
	}//end persistVarianceFields()

	/**
	 * Write expectedGLBalance and the absolute variance as decimals.
	 *
	 * @param string                                                         $id      The reconciliation id.
	 * @param array{expectedCents: int, closingCents: int, varianceCents: int} $figures The computed figures.
	 *
	 * @return void
	 */
	private function writeVariance(string $id, array $figures): void {
		$this->objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('BankReconciliation')
			->updateObject(
				$id,
				[
					'expectedGLBalance' => ($figures['expectedCents'] / 100),
					'variance' => abs($figures['varianceCents']) / 100,
				]
			);
	}//end writeVariance()

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
		foreach ($this->pages(schema: 'ReconciliationMatch', filters: ['reconId' => $reconId]) as $batch) {
			$matches = array_merge($matches, $batch);
		}

		return $matches;
	}//end findMatches()

	/**
	 * Page through a schema's rows, one batch at a time, until a short page.
	 * Lazy, so each page is fetched only once the previous one has been used.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The findAll filters.
	 *
	 * @return \Generator<int, array<int, mixed>>
	 */
	private function pages(string $schema, array $filters): \Generator {
		$page = 1;
		do {
			$batch = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => (($page - 1) * self::PAGE_SIZE)]);
			yield $batch;
			$page++;
		} while (count($batch) === self::PAGE_SIZE);
	}//end pages()
}//end class
