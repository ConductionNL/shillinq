<?php

/**
 * Import Pipeline Service
 *
 * The staged migration pipeline for administration-import-migration
 * (REQ-AIM-005 .. REQ-AIM-009). It contains ZERO bookkeeping rules: posting
 * composes the existing journal-entry, AR/AP, and NC-contacts surfaces
 * through the real OpenRegister ObjectService API
 * (find/findAll/saveObject/createObject/updateObject/deleteObject) and
 * OCP\Contacts\IManager. The ADR-031 exception scope is the orchestration
 * (parse → stage → resolve mappings → validate → dry-run → post → reverse),
 * NOT any accounting logic.
 *
 * Honesty boundary (documented per the change design D7):
 *  - The PURE computation guards — balance check, AR/AP control-account
 *    double-count guard, mapping completeness, staged-state hash for
 *    dry-run/post consistency, idempotency — are fully real and unit-tested
 *    on staged data with a mocked ObjectService.
 *  - The writes of post and reverse live in ImportPosting: a write the
 *    register or the books refuse is an error finding that stops the post
 *    (posting_failed) or the reversal, never a warning behind a `posted`.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
 * @spec openspec/changes/administration-import-migration/tasks.md#task-12
 * @spec openspec/changes/administration-import-migration/tasks.md#task-13
 * @spec openspec/changes/administration-import-migration/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Staged XAF/CSV import pipeline (REQ-AIM-005 .. REQ-AIM-009).
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * Pre-existing debt (issue #506): this pipeline covers every import stage
 * (parse/map/validate/post) for every supported source format; splitting
 * is out of scope for a mechanical phpcs/phpmd cleanup. Deferred to a
 * follow-up.
 */
class ImportPipelineService {

	/**
	 * Cent tolerance for balance / control-account equality (rounding noise).
	 *
	 * @var float
	 */
	private const TOLERANCE = 0.005;

	/**
	 * Findings severity codes (mirrors AuditfileParser).
	 *
	 * @var string
	 */
	public const SEVERITY_ERROR = 'error';

	/**
	 * Warning-severity finding (informs, does not block).
	 *
	 * @var string
	 */
	public const SEVERITY_WARNING = 'warning';

	/**
	 * Construct the pipeline.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger Logger for diagnostics + graceful-degrade findings.
	 * @param AuditfileParser $parser Deterministic XAF parser.
	 * @param ImportBatchGuard $guard Fail-closed lifecycle guard.
	 * @param ImportPosting $posting Writes and unwinds the opening entry and customers.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditfileParser $parser,
		private readonly ImportBatchGuard $guard,
		private readonly ImportPosting $posting,
	) {
	}//end __construct()

	/**
	 * Compute the idempotency key for a batch (REQ-AIM-009).
	 *
	 * Hash of source files + scope + administration. Stable across retries so
	 * a replayed post is recognised as a no-op.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 *
	 * @return string Hex idempotency key.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-13
	 */
	public function computeIdempotencyKey(array $batch): string {
		$payload = [
			'administrationId' => ($batch['administrationId'] ?? ''),
			'sourceFiles' => ($batch['sourceFiles'] ?? []),
			'scope' => ($batch['scope'] ?? []),
		];

		return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
	}//end computeIdempotencyKey()

	/**
	 * Compute a stable hash of the staged payload + mappings (REQ-AIM-008).
	 *
	 * Recorded at dry-run time; a mismatch at post time means the staged state
	 * changed and posting must be refused (forces a fresh validation/dry-run).
	 *
	 * @param array<string,mixed> $stagingPayload Parsed staged data.
	 * @param array<int,array<string,mixed>> $mappings Resolved mapping rows.
	 *
	 * @return string Hex staged-state hash.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-12
	 */
	public function computeStagedHash(array $stagingPayload, array $mappings): string {
		return hash('sha256', json_encode(['payload' => $stagingPayload, 'mappings' => $mappings], JSON_THROW_ON_ERROR));
	}//end computeStagedHash()

	/**
	 * Parse the batch's auditfile + companion CSVs and persist staged data.
	 *
	 * Reads the XAF via AuditfileParser, applies the chosen profile's dialect
	 * quirks, and persists stagedCounts + stagingPayload on the batch. Parser
	 * findings are merged into the returned report. Pure computation; the
	 * only side-effect is updating the batch object.
	 *
	 * @param string $batchId The ImportBatch UUID / slug.
	 *
	 * @return array<string,mixed> Result with 'stagedCounts', 'stagingPayload', 'findings'.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-13
	 */
	public function stage(string $batchId): array {
		$batch = $this->loadBatch(batchId: $batchId);
		if ($batch === null) {
			return [
				'findings' => [
					$this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'batch-not-found',
						message: 'Import batch not found.',
						context: ['batchId' => $batchId]
					),
				],
			];
		}

		$result = $this->stageBatch(batch: $batch);

		$batch['stagingPayload'] = $result['stagingPayload'];
		$batch['stagedCounts'] = $result['stagedCounts'];
		$this->persistBatch(batch: $batch);

		return $result;

	}//end stage()

	/**
	 * Parse a batch's auditfile into its staged data, without writing anything.
	 *
	 * The lifecycle action calls this while the batch itself is being saved, so
	 * it must not save the batch again.
	 *
	 * @param array<string,mixed> $batch The ImportBatch data, with `sourceXaf` holding the file contents.
	 *
	 * @return array<string,mixed> 'stagedCounts', 'stagingPayload', 'findings'.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function stageBatch(array $batch): array {
		$profile = $this->profileFor(sourceSystem: (string)($batch['sourceSystem'] ?? 'xaf-generic'));
		$findings = [];

		$xml = $this->readSourceXaf(batch: $batch, findings: $findings);
		if ($xml === null) {
			$parsed = $this->emptyParse();
		} else {
			$parsed = $this->parser->parse($xml);
		}

		$parsed = $profile->applyDialectQuirks($parsed);
		$findings = array_merge($findings, ($parsed['findings'] ?? []));

		$stagingPayload = $this->buildStagingPayload(parsed: $parsed, profile: $profile, batch: $batch, findings: $findings);

		return [
			'stagedCounts' => $this->countStaged(stagingPayload: $stagingPayload),
			'stagingPayload' => $stagingPayload,
			'findings' => $findings,
		];

	}//end stageBatch()

	/**
	 * Resolve account mappings for the staged batch (REQ-AIM-004).
	 *
	 * Resolution order per staged source account:
	 *   (1) source RGS code matches a target account's RGS code → rgs-auto, confirmed;
	 *   (2) saved mapping profile hit → profile-default, confirmed;
	 *   (3) code/name similarity → manual, unconfirmed;
	 *   (4) unmapped.
	 * Persists an ImportMapping row per source account. Returns the resolved
	 * rows plus a 'blocking' flag set when any referenced row is unmapped or
	 * unconfirmed (which blocks the mapping → validated transition).
	 *
	 * @param string $batchId The ImportBatch UUID / slug.
	 *
	 * @return array<string,mixed> 'mappings', 'blocking' (bool), 'findings'.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-9
	 */
	public function resolveMappings(string $batchId): array {
		$batch = $this->loadBatch(batchId: $batchId);
		if ($batch === null) {
			return [
				'mappings' => [],
				'blocking' => true,
				'findings' => [
					$this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'batch-not-found',
						message: 'Import batch not found.',
						context: ['batchId' => $batchId]
					),
				],
			];
		}

		return $this->resolveBatchMappings(batch: $batch, batchId: $batchId);
	}//end resolveMappings()

	/**
	 * Resolve and save the account mappings of a batch that is already loaded.
	 *
	 * @param array<string,mixed> $batch   The ImportBatch data with its staged payload.
	 * @param string              $batchId The batch id the mapping rows reference.
	 *
	 * @return array<string,mixed> 'mappings', 'blocking' (bool), 'findings'.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function resolveBatchMappings(array $batch, string $batchId): array {
		$stagingPayload = ($batch['stagingPayload'] ?? []);
		$accounts = ($stagingPayload['ledgerAccounts'] ?? []);
		$targetByRgs = $this->loadTargetAccountsByRgs(administrationId: (string)($batch['administrationId'] ?? ''));
		$profileMap = $this->loadMappingProfile(name: (string)($batch['mappingProfile'] ?? ''));

		$mappings = [];
		foreach ($accounts as $account) {
			$mappings[] = $this->resolveOne(
				account: $account,
				targetByRgs: $targetByRgs,
				profileMap: $profileMap,
				administrationId: (string)($batch['administrationId'] ?? ''),
				batchId: $batchId
			);
		}

		foreach ($mappings as $row) {
			$this->persistMapping(mapping: $row);
		}

		$blocking = $this->mappingsBlock(mappings: $mappings);

		return ['mappings' => $mappings, 'blocking' => $blocking, 'findings' => []];
	}//end resolveBatchMappings()

	/**
	 * Resolve a single source account to a mapping row (REQ-AIM-004).
	 *
	 * @param array<string,mixed> $account Staged source account.
	 * @param array<string,string> $targetByRgs RGS code → target account
	 *                                          code.
	 * @param array<string,string> $profileMap Source code → target code from a saved
	 *                                         profile.
	 * @param string $administrationId Owning administration.
	 * @param string $batchId Owning batch.
	 *
	 * @return array<string,mixed> The ImportMapping field map.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-9
	 */
	private function resolveOne(array $account, array $targetByRgs, array $profileMap, string $administrationId, string $batchId): array {
		$sourceCode = (string)($account['code'] ?? '');
		$sourceName = (string)($account['name'] ?? '');
		$rgs = (string)($account['rgsCode'] ?? '');

		if ($rgs !== '') {
			$sourceRgsCode = $rgs;
		} else {
			$sourceRgsCode = null;
		}

		$base = [
			'batchReference' => $batchId,
			'sourceCode' => $sourceCode,
			'sourceName' => $sourceName,
			'sourceRgsCode' => $sourceRgsCode,
			'administrationId' => $administrationId,
		];

		// (1) RGS auto-match, pre-confirmed.
		if ($rgs !== '' && isset($targetByRgs[$rgs]) === true) {
			return array_merge($base, ['targetAccount' => $targetByRgs[$rgs], 'mappingSource' => 'rgs-auto', 'confirmed' => true]);
		}

		// (2) Saved profile hit, pre-confirmed.
		if ($sourceCode !== '' && isset($profileMap[$sourceCode]) === true) {
			return array_merge($base, ['targetAccount' => $profileMap[$sourceCode], 'mappingSource' => 'profile-default', 'confirmed' => true]);
		}

		// (3) Code/name similarity suggestion — operator must confirm.
		$suggested = $this->suggestByCodeOrName(sourceCode: $sourceCode, targetByRgs: $targetByRgs);
		if ($suggested !== null) {
			return array_merge($base, ['targetAccount' => $suggested, 'mappingSource' => 'manual', 'confirmed' => false]);
		}

		// (4) Unmapped.
		return array_merge($base, ['targetAccount' => null, 'mappingSource' => 'unmapped', 'confirmed' => false]);
	}//end resolveOne()

	/**
	 * Suggest a target account by exact code match (cheap similarity heuristic).
	 *
	 * @param string $sourceCode Source account code.
	 * @param array<string,string> $targetByRgs RGS → target code (values are the candidate
	 *                                          codes).
	 *
	 * @return string|null Suggested target code, or null.
	 */
	private function suggestByCodeOrName(string $sourceCode, array $targetByRgs): ?string {
		if ($sourceCode === '') {
			return null;
		}

		// Exact code identity is the safe, deterministic suggestion.
		foreach ($targetByRgs as $targetCode) {
			if ($targetCode === $sourceCode) {
				return $targetCode;
			}
		}

		return null;
	}//end suggestByCodeOrName()

	/**
	 * Whether any mapping row blocks the mapping → validated transition.
	 *
	 * @param array<int,array<string,mixed>> $mappings Resolved mapping rows.
	 *
	 * @return bool True when at least one row is unmapped or unconfirmed.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-9
	 */
	public function mappingsBlock(array $mappings): bool {
		foreach ($mappings as $row) {
			if (($row['mappingSource'] ?? '') === 'unmapped') {
				return true;
			}

			if (($row['confirmed'] ?? false) !== true) {
				return true;
			}
		}

		return false;
	}//end mappingsBlock()

	/**
	 * Validate a staged batch (REQ-AIM-005/006/007).
	 *
	 * Fully real, pure computation on staged data:
	 *  - balanced opening journal (sum debit == sum credit);
	 *  - target period open (caller-supplied via the period-close surface);
	 *  - AR open items sum EXACTLY equals the AR control-account opening amount;
	 *  - AP open items sum EXACTLY equals the AP control-account opening amount;
	 *  - every referenced mapping confirmed and mapped;
	 *  - relation dedupe preview (KvK → BTW → email) reported as warnings.
	 * Returns 'findings'; any error-severity finding means validation_failed.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data (with stagingPayload + resolved mappings + periodOpen).
	 *
	 * @return array{findings:array<int,array<string,mixed>>,valid:bool}
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
	 */
	public function validate(array $batch): array {
		$findings = [];
		$staging = ($batch['stagingPayload'] ?? []);
		$mappings = ($batch['mappings'] ?? []);

		// (1) Balanced opening journal.
		[$debit, $credit] = $this->sumOpeningBalance(staging: $staging);
		if (abs($debit - $credit) > self::TOLERANCE) {
			$findings[] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'opening-journal-unbalanced',
				message: 'Opening balance is not balanced.',
				context: ['debit' => $debit, 'credit' => $credit, 'difference' => round(($debit - $credit), 2)]
			);
		}

		// (2) Open period.
		if (($batch['periodOpen'] ?? null) === false) {
			$findings[] = $this->finding(
				severity: self::SEVERITY_ERROR,
				code: 'period-closed',
				message: 'Migration date falls in a closed period.',
				context: ['migrationDate' => ($batch['migrationDate'] ?? null)]
			);
		}

		// (3) AR / AP control-account == open-items sum (double-count guard).
		$findings = array_merge($findings, $this->validateControlAccount(staging: $staging, side: 'ar'));
		$findings = array_merge($findings, $this->validateControlAccount(staging: $staging, side: 'ap'));

		// (4) Mappings complete.
		if ($this->mappingsBlock(mappings: $mappings) === true) {
			foreach ($mappings as $row) {
				if (($row['mappingSource'] ?? '') === 'unmapped' || ($row['confirmed'] ?? false) !== true) {
					$findings[] = $this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'account-unmapped',
						message: 'A source account is unmapped or unconfirmed and blocks posting.',
						context: ['sourceCode' => ($row['sourceCode'] ?? '')]
					);
				}
			}
		}

		// (5) Relation dedupe preview (warnings only).
		$findings = array_merge($findings, $this->dedupePreview(staging: $staging));

		$valid = ($this->hasErrors(findings: $findings) === false);

		return ['findings' => $findings, 'valid' => $valid];
	}//end validate()

	/**
	 * Validate one control-account side against the staged open items.
	 *
	 * @param array<string,mixed> $staging Staged payload.
	 * @param string $side 'ar' or 'ap'.
	 *
	 * @return array<int,array<string,mixed>> Findings (empty when matched).
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
	 */
	private function validateControlAccount(array $staging, string $side): array {
		$controlAmount = (float)($staging[$side . 'ControlOpeningAmount'] ?? 0.0);
		$items = ($staging[$side . 'OpenItems'] ?? []);
		$itemSum = 0.0;
		foreach ($items as $item) {
			$itemSum += (float)($item['outstandingAmount'] ?? 0.0);
		}

		if (abs($controlAmount - $itemSum) > self::TOLERANCE) {
			return [
				$this->finding(
					severity: self::SEVERITY_ERROR,
					code: strtoupper($side) . '-control-mismatch',
					message: 'Open items do not reconcile to the control account opening amount.',
					context: [
						'side' => strtoupper($side),
						'controlAmount' => round($controlAmount, 2),
						'openItemsSum' => round($itemSum, 2),
						'difference' => round(($controlAmount - $itemSum), 2),
					]
				),
			];
		}

		return [];
	}//end validateControlAccount()

	/**
	 * Sum the staged opening balance into (debit, credit) totals.
	 *
	 * @param array<string,mixed> $staging Staged payload.
	 *
	 * @return array{0:float,1:float} [debitTotal, creditTotal].
	 */
	private function sumOpeningBalance(array $staging): array {
		$debit = 0.0;
		$credit = 0.0;
		foreach (($staging['openingBalances'] ?? []) as $line) {
			$debit += (float)($line['debit'] ?? 0.0);
			$credit += (float)($line['credit'] ?? 0.0);
		}

		return [$debit, $credit];
	}//end sumOpeningBalance()

	/**
	 * Build the relation dedupe preview (KvK → BTW → email), warnings only.
	 *
	 * @param array<string,mixed> $staging Staged payload.
	 *
	 * @return array<int,array<string,mixed>> Warning findings for possible duplicates.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-11
	 */
	private function dedupePreview(array $staging): array {
		$findings = [];
		$seen = ['kvk' => [], 'vat' => [], 'email' => [], 'name' => []];

		foreach (($staging['relations'] ?? []) as $relation) {
			foreach (['kvk', 'vat', 'email', 'name'] as $key) {
				$value = strtolower(trim((string)($relation[$key] ?? '')));
				if ($value === '') {
					continue;
				}

				if (isset($seen[$key][$value]) === true) {
					$findings[] = $this->finding(
						severity: self::SEVERITY_WARNING,
						code: 'possible-duplicate-relation',
						message: 'A relation may be a duplicate of an earlier one.',
						context: ['matchKey' => $key, 'value' => $value, 'name' => ($relation['name'] ?? '')]
					);
					break;
				}

				$seen[$key][$value] = true;
			}
		}//end foreach

		return $findings;
	}//end dedupePreview()

	/**
	 * Generate and return the dry-run report (REQ-AIM-008).
	 *
	 * Builds the full would-be opening journal (per mapped account), the AR/AP
	 * open-item lists, the contact/master list with dedupe outcomes, the
	 * warning findings, and a stagedHash so a post-dry-run mutation forces
	 * re-validation. Pure computation; NOTHING is written to the books.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data (stagingPayload + mappings).
	 *
	 * @return array<string,mixed> The dry-run report.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-12
	 */
	public function dryRun(array $batch): array {
		$staging = ($batch['stagingPayload'] ?? []);
		$mappings = ($batch['mappings'] ?? []);

		$targetBySource = [];
		foreach ($mappings as $row) {
			if (($row['targetAccount'] ?? null) !== null) {
				$targetBySource[(string)($row['sourceCode'] ?? '')] = $row['targetAccount'];
			}
		}

		$journalLines = [];
		foreach (($staging['openingBalances'] ?? []) as $line) {
			$sourceCode = (string)($line['accountCode'] ?? '');
			$journalLines[] = [
				'sourceAccount' => $sourceCode,
				'targetAccount' => ($targetBySource[$sourceCode] ?? null),
				'debit' => (float)($line['debit'] ?? 0.0),
				'credit' => (float)($line['credit'] ?? 0.0),
			];
		}

		return [
			'openingJournal' => [
				'date' => ($batch['migrationDate'] ?? null),
				'type' => 'opening-balance',
				'lines' => $journalLines,
			],
			'arOpenItems' => ($staging['arOpenItems'] ?? []),
			'apOpenItems' => ($staging['apOpenItems'] ?? []),
			'contacts' => $this->buildContactPreview(staging: $staging),
			'warnings' => $this->dedupePreview(staging: $staging),
			'stagedHash' => $this->computeStagedHash(stagingPayload: $staging, mappings: $mappings),
		];

	}//end dryRun()

	/**
	 * Build the would-be contact/master preview with dedupe outcomes.
	 *
	 * @param array<string,mixed> $staging Staged payload.
	 *
	 * @return array<int,array<string,mixed>> Contact preview rows.
	 */
	private function buildContactPreview(array $staging): array {
		$contacts = [];
		foreach (($staging['relations'] ?? []) as $relation) {
			$key = '';
			foreach (['kvk', 'vat', 'email'] as $dedupeKey) {
				if (trim((string)($relation[$dedupeKey] ?? '')) !== '') {
					$key = $dedupeKey;
					break;
				}
			}

			if ($key === '') {
				$wouldOutcome = 'create-no-key';
			} else {
				$wouldOutcome = 'create-or-link';
			}

			$contacts[] = [
				'name' => ($relation['name'] ?? ''),
				'kvk' => ($relation['kvk'] ?? ''),
				'vat' => ($relation['vat'] ?? ''),
				'email' => ($relation['email'] ?? ''),
				'dedupeKey' => $key,
				'wouldOutcome' => $wouldOutcome,
			];
		}//end foreach

		return $contacts;
	}//end buildContactPreview()

	/**
	 * Post the batch (REQ-AIM-005/006/007/009).
	 *
	 * Real orchestration + guards:
	 *  - Idempotent: if the batch already posted under its idempotencyKey,
	 *    returns the existing postingRefs without writing anything.
	 *  - Dry-run consistency: refuses to post (returns posting_failed) when the
	 *    current staged hash differs from the dry-run's recorded stagedHash.
	 *  - Balance guard re-checked before any write.
	 * Then ImportPosting writes the customers and posts the opening entry
	 * through the JournalEntry lifecycle. A write the register or the books
	 * refuse removes what the post wrote and returns posting_failed with an
	 * error finding; staged open items are refused before any write.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 *
	 * @return array<string,mixed> 'status', 'postingRefs', 'findings', 'idempotent'.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-13
	 */
	public function post(array $batch): array {
		// Idempotency (REQ-AIM-009): an already-posted key is a no-op.
		$existingRefs = ($batch['postingRefs'] ?? null);
		if (($batch['status'] ?? '') === 'posted' && is_array($existingRefs) === true && $existingRefs !== []) {
			return ['status' => 'posted', 'postingRefs' => $existingRefs, 'findings' => [], 'idempotent' => true];
		}

		// Dry-run consistency (REQ-AIM-008): staged state must match the dry-run.
		$recordedHash = (string)(($batch['dryRunReport']['stagedHash'] ?? ''));
		$currentHash = $this->computeStagedHash(stagingPayload: ($batch['stagingPayload'] ?? []), mappings: ($batch['mappings'] ?? []));
		if ($recordedHash === '' || $recordedHash !== $currentHash) {
			return [
				'status' => 'posting_failed',
				'postingRefs' => null,
				'findings' => [
					$this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'staged-state-changed',
						message: 'Staged state changed since the dry-run; a fresh validation and dry-run are required.',
						context: ['recordedHash' => $recordedHash, 'currentHash' => $currentHash]
					),
				],
				'idempotent' => false,
			];
		}

		// Re-check balance fail-closed before any write.
		[$debit, $credit] = $this->sumOpeningBalance(staging: ($batch['stagingPayload'] ?? []));
		if (abs($debit - $credit) > self::TOLERANCE) {
			return [
				'status' => 'posting_failed',
				'postingRefs' => null,
				'findings' => [
					$this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'opening-journal-unbalanced',
						message: 'Opening balance is not balanced.',
						context: ['difference' => round(($debit - $credit), 2)]
					),
				],
				'idempotent' => false,
			];
		}

		$written = $this->posting->post(batch: $batch, report: $this->dryRun(batch: $batch));
		$status  = 'posted';
		if ($written['failed'] === true) {
			$status = 'posting_failed';
		}

		return ['status' => $status, 'postingRefs' => $written['postingRefs'], 'findings' => $written['findings'], 'idempotent' => false];
	}//end post()

	/**
	 * Reverse a posted batch (REQ-AIM-009).
	 *
	 * Guarded by ImportBatchGuard::canReverse (posted + open period). Posts the
	 * reversing entry and deletes the customers the import wrote; a refused
	 * write keeps the batch's status with a reversal-refused error finding.
	 *
	 * @param array<string,mixed> $batch The ImportBatch object data.
	 * @param bool $periodOpen Whether the target period is open.
	 *
	 * @return array<string,mixed> 'status', 'reversalRefs', 'findings'.
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-14
	 */
	public function reverse(array $batch, bool $periodOpen): array {
		if ($this->guard->canReverse(batch: $batch, periodOpen: $periodOpen) === false) {
			return [
				'status' => ($batch['status'] ?? ''),
				'findings' => [
					$this->finding(
						severity: self::SEVERITY_ERROR,
						code: 'reversal-blocked',
						message: 'Reversal is blocked: the batch is not posted or the target period is closed. Follow correction-journal practice.',
						context: ['status' => ($batch['status'] ?? ''), 'periodOpen' => $periodOpen]
					),
				],
			];
		}

		$result = $this->posting->reverse(batch: $batch);
		$status = 'reversed';
		if ($this->hasErrors(findings: $result['findings']) === true) {
			$status = (string)($batch['status'] ?? '');
		}

		return ['status' => $status, 'reversalRefs' => $result['reversalRefs'], 'findings' => $result['findings']];

	}//end reverse()

	/**
	 * Derive the open-item lifecycle state from its due date (REQ-AIM-006).
	 *
	 * @param string $dueDate ISO due date.
	 *
	 * @return string 'overdue' when the due date is in the past, else 'issued'.
	 *
	 * @spec openspec/changes/administration-import-migration/specs/administration-import-migration/spec.md (REQ-AIM-006)
	 */
	public function openItemStateForDueDate(string $dueDate): string {
		if ($dueDate === '') {
			return 'issued';
		}

		$ts = strtotime($dueDate);
		if ($ts === false) {
			return 'issued';
		}

		if ($ts < time()) {
			return 'overdue';
		}

		return 'issued';
	}//end openItemStateForDueDate()

	// ------------------------------------------------------------------
	// Internal helpers.
	// ------------------------------------------------------------------

	/**
	 * Build the staged payload from the parsed structure + profile + companion CSVs.
	 *
	 * @param array<string,mixed> $parsed Parser output.
	 * @param ImportProfileInterface $profile Source profile.
	 * @param array<string,mixed> $batch Batch data.
	 * @param array<int,mixed> $findings Findings accumulator (by reference).
	 *
	 * @return array<string,mixed> Staged payload.
	 */
	private function buildStagingPayload(array $parsed, ImportProfileInterface $profile, array $batch, array &$findings): array {
		unset($findings);
		return [
			'company' => ($parsed['company'] ?? []),
			'ledgerAccounts' => $profile->normalizeLedgerAccounts($parsed),
			'relations' => ($parsed['relations'] ?? []),
			'openingBalances' => ($parsed['openingBalances'] ?? []),
			'journals' => ($parsed['journals'] ?? []),
			// AR/AP open items + control amounts come from the companion CSVs in
			// production via the profile column maps; staged here when present on
			// the batch (kept addressable for validation).
			'arOpenItems' => ($batch['arOpenItems'] ?? []),
			'apOpenItems' => ($batch['apOpenItems'] ?? []),
			'arControlOpeningAmount' => ($batch['arControlOpeningAmount'] ?? 0.0),
			'apControlOpeningAmount' => ($batch['apControlOpeningAmount'] ?? 0.0),
		];

	}//end buildStagingPayload()

	/**
	 * Count the staged artifacts.
	 *
	 * @param array<string,mixed> $stagingPayload Staged payload.
	 *
	 * @return array<string,int> Counts.
	 */
	private function countStaged(array $stagingPayload): array {
		return [
			'ledgerAccounts' => count(($stagingPayload['ledgerAccounts'] ?? [])),
			'openingBalanceLines' => count(($stagingPayload['openingBalances'] ?? [])),
			'arOpenItems' => count(($stagingPayload['arOpenItems'] ?? [])),
			'apOpenItems' => count(($stagingPayload['apOpenItems'] ?? [])),
			'relations' => count(($stagingPayload['relations'] ?? [])),
		];

	}//end countStaged()

	/**
	 * Whether any finding is error-severity.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/administration-import-migration/specs/administration-import-migration/spec.md (REQ-AIM-005)
	 */
	public function hasErrors(array $findings): bool {
		foreach ($findings as $finding) {
			if (($finding['severity'] ?? '') === self::SEVERITY_ERROR) {
				return true;
			}
		}

		return false;
	}//end hasErrors()

	/**
	 * Select the import profile for a source system.
	 *
	 * @param string $sourceSystem The ImportBatch.sourceSystem value.
	 *
	 * @return ImportProfileInterface
	 *
	 * @spec openspec/changes/administration-import-migration/specs/administration-import-migration/spec.md (REQ-AIM-003)
	 */
	public function profileFor(string $sourceSystem): ImportProfileInterface {
		switch ($sourceSystem) {
			case 'e-boekhouden':
				return new ImportProfile\EBoekhoudenProfile();
			case 'exact-online':
				return new ImportProfile\ExactOnlineProfile();
			case 'moneybird':
				return new ImportProfile\MoneybirdProfile();
			case 'snelstart':
				return new ImportProfile\SnelstartProfile();
			case 'xaf-generic':
			default:
				return new ImportProfile\XafGenericProfile();
		}

	}//end profileFor()

	/**
	 * Read the batch's XAF source contents (degrades gracefully).
	 *
	 * @param array<string,mixed> $batch Batch data.
	 * @param array<int,mixed> $findings Findings accumulator (by reference).
	 *
	 * @return string|null Raw XAF contents, or null.
	 */
	private function readSourceXaf(array $batch, array &$findings): ?string {
		// Inline test/staged contents win (unit-testability).
		if (isset($batch['sourceXaf']) === true && is_string($batch['sourceXaf']) === true) {
			return $batch['sourceXaf'];
		}

		$findings[] = $this->finding(
			severity: self::SEVERITY_WARNING,
			code: 'source-not-loaded',
			message: 'Source XAF contents were not loaded in this context (NC Files read is environment-dependent).',
			context: []
		);
		return null;
	}//end readSourceXaf()

	/**
	 * The empty parser structure.
	 *
	 * @return array<string,mixed>
	 */
	private function emptyParse(): array {
		return [
			'company' => [],
			'ledgerAccounts' => [],
			'relations' => [],
			'openingBalances' => [],
			'journals' => [],
			'findings' => [],
		];

	}//end emptyParse()

	/**
	 * Resolve the OR ObjectService, or null when unavailable.
	 *
	 * @return mixed The ObjectService, or null.
	 */
	private function objectService() {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end objectService()

	/**
	 * The shillinq register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		return 'shillinq';
	}//end register()

	/**
	 * Load a batch object via the OR ObjectService.
	 *
	 * @param string $batchId Batch UUID / slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadBatch(string $batchId): ?array {
		try {
			$service = $this->objectService();
			if ($service === null) {
				return null;
			}

			$object = $service->setRegister($this->register())->setSchema('ImportBatch')->find($batchId);
			return $this->toArray(object: $object);
		} catch (\Throwable $e) {
			$this->logger->warning('ImportPipelineService: loadBatch failed', ['batchId' => $batchId, 'exception' => $e->getMessage()]);
			return null;
		}

	}//end loadBatch()

	/**
	 * Persist a batch object via the OR ObjectService.
	 *
	 * @param array<string,mixed> $batch Batch data.
	 *
	 * @return void
	 */
	private function persistBatch(array $batch): void {
		try {
			$service = $this->objectService();
			if ($service !== null) {
				$service->setRegister($this->register())->setSchema('ImportBatch')->saveObject($batch);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('ImportPipelineService: persistBatch degraded', ['exception' => $e->getMessage()]);
		}

	}//end persistBatch()

	/**
	 * Persist a mapping row via the OR ObjectService.
	 *
	 * @param array<string,mixed> $mapping Mapping data.
	 *
	 * @return void
	 */
	private function persistMapping(array $mapping): void {
		try {
			$service = $this->objectService();
			if ($service !== null) {
				$service->setRegister($this->register())->setSchema('ImportMapping')->saveObject($mapping);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('ImportPipelineService: persistMapping degraded', ['exception' => $e->getMessage()]);
		}

	}//end persistMapping()

	/**
	 * Load target accounts keyed by RGS code (for auto-mapping).
	 *
	 * @param string $administrationId Administration.
	 *
	 * @return array<string,string> RGS code → target account code.
	 */
	private function loadTargetAccountsByRgs(string $administrationId): array {
		$byRgs = [];
		try {
			$service = $this->objectService();
			if ($service === null) {
				return $byRgs;
			}

			$accounts = $service->setRegister($this->register())
				->setSchema('Account')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
			foreach (($accounts ?? []) as $account) {
				$row = $this->toArray(object: $account);
				$rgs = (string)($row['rgsCode'] ?? '');
				$code = (string)($row['accountNumber'] ?? ($row['code'] ?? ''));
				if ($rgs !== '' && $code !== '') {
					$byRgs[$rgs] = $code;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('ImportPipelineService: loadTargetAccountsByRgs degraded', ['exception' => $e->getMessage()]);
		}

		return $byRgs;
	}//end loadTargetAccountsByRgs()

	/**
	 * Load a saved mapping profile as source code → target code.
	 *
	 * @param string $name Profile name.
	 *
	 * @return array<string,string>
	 */
	private function loadMappingProfile(string $name): array {
		if ($name === '') {
			return [];
		}

		$map = [];
		try {
			$service = $this->objectService();
			if ($service === null) {
				return $map;
			}

			$rows = $service->setRegister($this->register())
				->setSchema('ImportMapping')
				->findAll(['filters' => ['mappingProfile' => $name, 'confirmed' => true]]);
			foreach (($rows ?? []) as $row) {
				$r = $this->toArray(object: $row);
				$src = (string)($r['sourceCode'] ?? '');
				$tgt = ($r['targetAccount'] ?? null);
				if ($src !== '' && $tgt !== null) {
					$map[$src] = $tgt;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('ImportPipelineService: loadMappingProfile degraded', ['exception' => $e->getMessage()]);
		}

		return $map;
	}//end loadMappingProfile()

	/**
	 * Normalise an OR object (array or entity) to an array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray($object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return [];
	}//end toArray()

	/**
	 * Build a structured finding.
	 *
	 * @param string $severity Severity.
	 * @param string $code Machine code.
	 * @param string $message English source message.
	 * @param array<string,mixed> $context Context data.
	 *
	 * @return array<string,mixed>
	 */
	private function finding(string $severity, string $code, string $message, array $context = []): array {
		return [
			'severity' => $severity,
			'code' => $code,
			'message' => $message,
			'context' => $context,
		];

	}//end finding()
}//end class
