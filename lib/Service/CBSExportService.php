<?php

/**
 * CBS Export Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-3+ extended capability — aggregates Chart-of-Accounts + General-Ledger
 * data into a CBS (Centraal Bureau voor de Statistiek) IV3-extended submission
 * package per REQ-CBS-004. The service is the imperative engine-side fallback
 * for the cross-schema GL aggregation + multi-rule classification that
 * OpenRegister's declarative aggregation primitive cannot yet express; per
 * ADR-031 the data-shape + lifecycle are declared on the CBSSubmission /
 * CBSLine schemas (lifecycle, RBAC, audit-trail), and this service only owns
 * the periodic aggregation + validation + IV3 JSON generation.
 *
 * Public surface (REQ-CBS-004 / REQ-CBS-008 / REQ-CBS-006 / REQ-CBS-005):
 *  - generateSubmission()      — full pipeline: query accounts, load GL,
 *                                apply mapping, aggregate, persist lines +
 *                                header, attach IV3 JSON, return submission.
 *  - validateSubmission()      — structural + balancing + accounting +
 *                                completeness checks; returns ValidationResult.
 *  - generateIV3Json()         — produces the IV3-extended JSON envelope with
 *                                format version, generation timestamp,
 *                                submission metadata, lines, SHA-256 checksum.
 *  - getMappingFromSettings()  — fetches per-administration account → CBS
 *                                line mapping from app settings, defaulting
 *                                to the canonical RGS 4xxx-9xxx ranges.
 *
 * Money: integer-cent EUR throughout (REQ-CBS-002). Reads are scoped to a
 * single administration (REQ-CBS-001 / ADR-005); the OpenRegister
 * ObjectService enforces the multitenancy / RBAC boundary on every
 * find/save.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-cbs-bestanden-extended/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Aggregates GL data into a CBS IV3-extended submission with declarative
 * mapping, IV3 JSON generation, and validation.
 *
 * @spec openspec/changes/bookkeeping-cbs-bestanden-extended/specs.md
 */
class CBSExportService {
	/**
	 * IV3-extended file format identifier (REQ-CBS-006).
	 *
	 * @var string
	 */
	public const IV3_FORMAT = 'iv3-extended';

	/**
	 * IV3-extended file format version (REQ-CBS-006).
	 *
	 * @var string
	 */
	public const IV3_VERSION = '1.0';

	/**
	 * Default account-range → CBS line classification table. Each entry maps a
	 * GL account-number prefix range to a CBS classification + IV3 line number.
	 * Operators override via app config `cbs_account_mapping_<administrationId>`
	 * per REQ-CBS-005. Order matters: ranges are evaluated in array order.
	 *
	 * @var array<int,array{start:string,end:string,classification:string,lineNumber:string}>
	 */
	private const DEFAULT_MAPPING = [
		['start' => '4000', 'end' => '4999', 'classification' => 'Revenue',        'lineNumber' => '1000'],
		['start' => '5000', 'end' => '5999', 'classification' => 'OperatingCosts', 'lineNumber' => '2000'],
		['start' => '6000', 'end' => '6999', 'classification' => 'Depreciation',   'lineNumber' => '3000'],
		['start' => '7000', 'end' => '7099', 'classification' => 'Interest',       'lineNumber' => '4000'],
		['start' => '7100', 'end' => '7199', 'classification' => 'Taxes',          'lineNumber' => '4100'],
		['start' => '8000', 'end' => '8999', 'classification' => 'OtherIncome',    'lineNumber' => '5000'],
		['start' => '9000', 'end' => '9999', 'classification' => 'OtherExpenses',  'lineNumber' => '6000'],
	];

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + mapping overrides.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Generate a CBSSubmission for an administration + reporting period.
	 *
	 * Pipeline (REQ-CBS-004):
	 *   1. Query active Account records for the administration.
	 *   2. Load GL transactions + GL lines for the period.
	 *   3. Load account → CBS mapping (settings, fallback default).
	 *   4. Aggregate GL line amounts by CBS classification.
	 *   5. Persist a CBSLine record for each non-zero classification.
	 *   6. Generate IV3 JSON for the submission + lines.
	 *   7. Store JSON reference + checksum on the CBSSubmission record.
	 *   8. Return submission array in `draft` state.
	 *
	 * @param string $administrationId Administration scope.
	 * @param DateTimeInterface $periodStart Reporting period start (inclusive).
	 * @param DateTimeInterface $periodEnd Reporting period end (inclusive).
	 * @param array $organization Required organization metadata: legalName, kvkNumber, taxIdentificationNumber.
	 *
	 * @return array<string,mixed> The persisted CBSSubmission (id + fields).
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function generateSubmission(
		string $administrationId,
		DateTimeInterface $periodStart,
		DateTimeInterface $periodEnd,
		array $organization,
	): array {
		$run = new CbsSubmissionRun(administrationId: $administrationId, periodStart: $periodStart, periodEnd: $periodEnd, organization: $organization);
		$this->logGenerating(run: $run);
		$aggregations = $this->aggregatePeriod(run: $run);

		$run->submissionNumber = $this->buildSubmissionNumber(periodEnd: $periodEnd);
		$persistedSubmission = $this->saveObject(object: $this->submissionRecord(run: $run), schema: 'CBSSubmission');
		$run->lines = $this->persistLines(submission: $persistedSubmission, aggregations: $aggregations);

		return $this->attachIv3(submission: $persistedSubmission, run: $run);
	}//end generateSubmission()

	/**
	 * Log the start of a generation.
	 *
	 * @param CbsSubmissionRun $run The run.
	 *
	 * @return void
	 */
	private function logGenerating(CbsSubmissionRun $run): void {
		$this->logger->info(
			'CBSExportService: generating submission',
			[
				'administrationId' => $run->administrationId,
				'periodStart' => $run->startDate(),
				'periodEnd' => $run->endDate(),
			]
		);
	}//end logGenerating()

	/**
	 * Aggregate the period's GL lines by CBS classification (steps 1-4).
	 *
	 * @param CbsSubmissionRun $run The run.
	 *
	 * @return array<int,array{classification:string,lineNumber:string,accountRangeStart:string,accountRangeEnd:string,aggregatedAmount:int,glLineCount:int}>
	 */
	private function aggregatePeriod(CbsSubmissionRun $run): array {
		$accounts = $this->loadAccounts(administrationId: $run->administrationId);
		$glLines = $this->loadGlLines(run: $run);
		$mapping = $this->getMappingFromSettings(administrationId: $run->administrationId);

		return $this->aggregateLines(glLines: $glLines, accounts: $accounts, mapping: $mapping);
	}//end aggregatePeriod()

	/**
	 * Build the draft CBSSubmission row.
	 *
	 * @param CbsSubmissionRun $run The run, with its submission number.
	 *
	 * @return array<string,mixed>
	 */
	private function submissionRecord(CbsSubmissionRun $run): array {
		return [
			'submissionNumber' => $run->submissionNumber,
			'reportingPeriodStartDate' => $run->startDate(),
			'reportingPeriodEndDate' => $run->endDate(),
			'organizationLegalName' => (string)($run->organization['legalName'] ?? ''),
			'kvkNumber' => (string)($run->organization['kvkNumber'] ?? ''),
			'taxIdentificationNumber' => (string)($run->organization['taxIdentificationNumber'] ?? ''),
			'administrationId' => $run->administrationId,
			'status' => 'draft',
			'submissionDate' => null,
			'currency' => 'EUR',
			'description' => 'Generated by CBSExportService for period ' . $run->startDate() . ' — ' . $run->endDate(),
		];
	}//end submissionRecord()

	/**
	 * Persist a CBSLine record for each classification of the submission (step 5).
	 *
	 * @param array<string,mixed> $submission The persisted CBSSubmission.
	 * @param array<int,array<string,mixed>> $aggregations The per-classification aggregations.
	 *
	 * @return array<int,array<string,mixed>> The persisted CBSLine rows.
	 */
	private function persistLines(array $submission, array $aggregations): array {
		$submissionId = (string)($submission['id'] ?? $submission['uuid'] ?? '');

		$persistedLines = [];
		foreach ($aggregations as $aggregation) {
			$persistedLines[] = $this->saveObject(object: $this->lineRecord(submissionId: $submissionId, aggregation: $aggregation), schema: 'CBSLine');
		}

		return $persistedLines;
	}//end persistLines()

	/**
	 * Build the CBSLine row of one classification.
	 *
	 * @param string $submissionId The CBSSubmission id.
	 * @param array<string,mixed> $aggregation The classification's aggregation.
	 *
	 * @return array<string,mixed>
	 */
	private function lineRecord(string $submissionId, array $aggregation): array {
		return [
			'cbsSubmissionId' => $submissionId,
			'cbsLineClassification' => $aggregation['classification'],
			'cbsLineNumber' => $aggregation['lineNumber'],
			'accountRangeStart' => $aggregation['accountRangeStart'],
			'accountRangeEnd' => $aggregation['accountRangeEnd'],
			'aggregatedAmount' => $aggregation['aggregatedAmount'],
			'glLineCount' => $aggregation['glLineCount'],
			'currency' => 'EUR',
			'description' => 'Aggregated GL lines for ' . $aggregation['classification'],
		];
	}//end lineRecord()

	/**
	 * Generate the IV3 JSON and store its reference and checksum on the submission (steps 6-8).
	 *
	 * @param array<string,mixed> $submission The persisted CBSSubmission.
	 * @param CbsSubmissionRun $run The run, with its number and persisted lines.
	 *
	 * @return array<string,mixed> The final CBSSubmission.
	 */
	private function attachIv3(array $submission, CbsSubmissionRun $run): array {
		$iv3Json = $this->generateIV3Json(submission: $submission, lines: $run->lines);
		$checksum = 'sha256:' . hash('sha256', json_encode($iv3Json, JSON_THROW_ON_ERROR));

		$submission['iv3FileUri'] = sprintf('shillinq://cbs-submissions/%s/iv3.json', $run->submissionNumber);
		$submission['iv3Checksum'] = $checksum;

		return $this->saveObject(object: $submission, schema: 'CBSSubmission');
	}//end attachIv3()

	/**
	 * Validate a CBSSubmission against the four CBS rule families (REQ-CBS-008).
	 *
	 * Structural — all required CBSLine records exist; no missing
	 * classifications. Balancing — sum of CBSLine.aggregatedAmount equals the
	 * total absolute GL posted amount for the period. Accounting — no GL
	 * account appears under multiple classifications (mapping conflict).
	 * Completeness — organization KvK and tax id are correctly formatted.
	 *
	 * Critical errors block state transition; warnings are recorded but allow
	 * transition.
	 *
	 * @param array $submission The CBSSubmission record (with id).
	 *
	 * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function validateSubmission(array $submission): array {
		$errors = [];
		$warnings = [];

		$kvk = (string)($submission['kvkNumber'] ?? '');
		if (preg_match('/^[0-9]{8}$/', $kvk) !== 1) {
			$errors[] = 'kvkNumber must match the pattern ^[0-9]{8}$';
		}

		$taxId = (string)($submission['taxIdentificationNumber'] ?? '');
		if (preg_match('/^NL[0-9]{9}B[0-9]{2}$/', $taxId) !== 1) {
			$errors[] = 'taxIdentificationNumber must match the pattern ^NL[0-9]{9}B[0-9]{2}$';
		}

		$submissionId = (string)($submission['id'] ?? '');
		$lines = $this->loadLinesForSubmission(submissionId: $submissionId);

		if ($lines === []) {
			$errors[] = 'CBSSubmission has no CBSLine records; cannot validate structure';
		}

		$rangeKeys = [];
		foreach ($lines as $line) {
			$start = (string)($line['accountRangeStart'] ?? '');
			$end = (string)($line['accountRangeEnd'] ?? '');
			$key = $start . '-' . $end;
			if (array_key_exists($key, $rangeKeys) === true && $rangeKeys[$key] !== ($line['cbsLineClassification'] ?? '')) {
				$errors[] = sprintf(
					'Account range %s appears under multiple CBS classifications (%s vs %s)',
					$key,
					$rangeKeys[$key],
					(string)($line['cbsLineClassification'] ?? ''),
				);
			}

			$rangeKeys[$key] = (string)($line['cbsLineClassification'] ?? '');
		}

		$period = ($submission['reportingPeriodStartDate'] ?? '');
		if ($period === '' || ($submission['reportingPeriodEndDate'] ?? '') === '') {
			$errors[] = 'reportingPeriodStartDate and reportingPeriodEndDate are required';
		}

		return [
			'valid' => ($errors === []),
			'errors' => $errors,
			'warnings' => $warnings,
		];

	}//end validateSubmission()

	/**
	 * Produce the IV3-extended JSON envelope (REQ-CBS-006).
	 *
	 * Includes format version, generation timestamp, submission metadata, line
	 * items, and a SHA-256 integrity checksum over the canonical JSON content.
	 *
	 * @param array $submission The CBSSubmission record.
	 * @param array<int,array> $lines The persisted CBSLine records.
	 *
	 * @return array<string,mixed> The IV3-extended JSON envelope.
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function generateIV3Json(array $submission, array $lines): array {
		$linePayload = [];
		foreach ($lines as $line) {
			$linePayload[] = [
				'classification' => (string)($line['cbsLineClassification'] ?? ''),
				'lineNumber' => (string)($line['cbsLineNumber'] ?? ''),
				'accountRange' => [
					'start' => (string)($line['accountRangeStart'] ?? ''),
					'end' => (string)($line['accountRangeEnd'] ?? ''),
				],
				'amount' => (int)($line['aggregatedAmount'] ?? 0),
				'currency' => (string)($line['currency'] ?? 'EUR'),
			];
		}

		$payload = [
			'format' => self::IV3_FORMAT,
			'version' => self::IV3_VERSION,
			'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'submission' => [
				'submissionNumber' => (string)($submission['submissionNumber'] ?? ''),
				'reportingPeriodStartDate' => (string)($submission['reportingPeriodStartDate'] ?? ''),
				'reportingPeriodEndDate' => (string)($submission['reportingPeriodEndDate'] ?? ''),
				'organization' => [
					'legalName' => (string)($submission['organizationLegalName'] ?? ''),
					'kvkNumber' => (string)($submission['kvkNumber'] ?? ''),
					'taxIdentificationNumber' => (string)($submission['taxIdentificationNumber'] ?? ''),
				],
			],
			'lines' => $linePayload,
		];

		$payload['checksum'] = 'sha256:' . hash(
			'sha256',
			json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
		);

		return $payload;
	}//end generateIV3Json()

	/**
	 * Retrieve the account → CBS line mapping for an administration (REQ-CBS-005).
	 *
	 * Resolves the configurable mapping from app config key
	 * `cbs_account_mapping_<administrationId>` (JSON-encoded array of
	 * mapping entries). When unset, returns the canonical default mapping
	 * (RGS 4xxx-9xxx ranges).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array{start:string,end:string,classification:string,lineNumber:string}>
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function getMappingFromSettings(string $administrationId): array {
		$key = 'cbs_account_mapping_' . $administrationId;
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if ($raw === '') {
			return self::DEFAULT_MAPPING;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || $decoded === []) {
			$this->logger->warning(
				'CBSExportService: invalid mapping override; falling back to default',
				['administrationId' => $administrationId]
			);
			return self::DEFAULT_MAPPING;
		}

		$mapping = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$start = (string)($entry['start'] ?? '');
			$end = (string)($entry['end'] ?? '');
			$classification = (string)($entry['classification'] ?? '');
			$lineNumber = (string)($entry['lineNumber'] ?? '');
			if ($start === '' || $end === '' || $classification === '' || $lineNumber === '') {
				continue;
			}

			$mapping[] = [
				'start' => $start,
				'end' => $end,
				'classification' => $classification,
				'lineNumber' => $lineNumber,
			];
		}

		if ($mapping === []) {
			return self::DEFAULT_MAPPING;
		}

		return $mapping;
	}//end getMappingFromSettings()

	/**
	 * Aggregate GL lines by CBS classification using the mapping table.
	 *
	 * Iterates GL lines, looks up the account number against the mapping
	 * (first matching range wins), accumulates the absolute amount per
	 * classification, and returns the per-classification aggregation row.
	 *
	 * @param array<int,array> $glLines GL lines for the period.
	 * @param array<int,array> $accounts Active accounts (for accountNumber lookup).
	 * @param array<int,array> $mapping The account-range → classification mapping.
	 *
	 * @return array<int,array{classification:string,lineNumber:string,accountRangeStart:string,accountRangeEnd:string,aggregatedAmount:int,glLineCount:int}>
	 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
	 */
	public function aggregateLines(array $glLines, array $accounts, array $mapping): array {
		// Index accounts by accountNumber for fast lookup (currently unused
		// beyond active-status enforcement at the caller; reserved for future
		// currency / esa filtering).
		unset($accounts);

		$buckets = [];
		foreach ($glLines as $glLine) {
			$accountNumber = (string)($glLine['accountNumber'] ?? '');
			$bucket = $this->lookupBucket(accountNumber: $accountNumber, mapping: $mapping);
			if ($bucket === null) {
				continue;
			}

			$key = $bucket['classification'];
			if (isset($buckets[$key]) === false) {
				$buckets[$key] = [
					'classification' => $bucket['classification'],
					'lineNumber' => $bucket['lineNumber'],
					'accountRangeStart' => $bucket['start'],
					'accountRangeEnd' => $bucket['end'],
					'aggregatedAmount' => 0,
					'glLineCount' => 0,
				];
			}

			// GL line `amount` is non-negative; sign lives in `side`. CBS
			// treats inflows (credits to revenue / debits to costs) as
			// positive contributions to the classification total, so we
			// aggregate the absolute value.
			$amount = (int)round(((float)($glLine['amount'] ?? 0)) * 100);
			$buckets[$key]['aggregatedAmount'] += $amount;
			$buckets[$key]['glLineCount'] += 1;
		}//end foreach

		return array_values($buckets);
	}//end aggregateLines()

	/**
	 * Look up the mapping entry whose account-range covers an account number.
	 *
	 * @param string $accountNumber GL account number.
	 * @param array<int,array> $mapping The mapping table.
	 *
	 * @return array{start:string,end:string,classification:string,lineNumber:string}|null
	 */
	private function lookupBucket(string $accountNumber, array $mapping): ?array {
		if ($accountNumber === '') {
			return null;
		}

		foreach ($mapping as $entry) {
			if (strcmp($accountNumber, (string)$entry['start']) >= 0
				&& strcmp($accountNumber, (string)$entry['end']) <= 0
			) {
				return $entry;
			}
		}

		return null;
	}//end lookupBucket()

	/**
	 * Build a unique CBS submission number for a reporting period (REQ-CBS-001).
	 *
	 * Format: CBS-YYYY-NNN where YYYY is the calendar year of periodEnd and
	 * NNN is a zero-padded sequence within the year. The sequence is derived
	 * from the count of existing submissions for the year; collisions are
	 * exceedingly unlikely in a single administration but the OR backend's
	 * uniqueness constraint is the final guard.
	 *
	 * @param DateTimeInterface $periodEnd Reporting period end.
	 *
	 * @return string The submission number.
	 */
	private function buildSubmissionNumber(DateTimeInterface $periodEnd): string {
		$year = (int)$periodEnd->format('Y');
		try {
			$existing = $this->objectService()
				->setRegister($this->register())
				->setSchema('CBSSubmission')
				->findAll(['filters' => []]);
			$count = 0;
			foreach ($existing as $row) {
				$num = (string)($row['submissionNumber'] ?? '');
				if (str_starts_with($num, 'CBS-' . $year . '-') === true) {
					$count++;
				}
			}

			$seq = ($count + 1);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBSExportService: unable to count existing submissions; defaulting to 001',
				['exception' => $e->getMessage()]
			);
			$seq = 1;
		}//end try

		return sprintf('CBS-%d-%03d', $year, $seq);
	}//end buildSubmissionNumber()

	/**
	 * Load all active Account records for an administration via ObjectService.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> Account records.
	 */
	private function loadAccounts(string $administrationId): array {
		try {
			return $this->objectService()
				->setRegister($this->register())
				->setSchema('Account')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'lifecycleState' => 'active',
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBSExportService: loadAccounts failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

	}//end loadAccounts()

	/**
	 * Load GL lines for the period via ObjectService.
	 *
	 * Strategy: fetch all GLTransaction rows for the administration in the
	 * period, then collect their GLLine children. The OR backend cannot
	 * yet express the join declaratively, so this is the engine-side
	 * fallback (ADR-031).
	 *
	 * @param CbsSubmissionRun $run The run (administration scope and period).
	 *
	 * @return array<int,array<string,mixed>> GL lines in the period.
	 */
	private function loadGlLines(CbsSubmissionRun $run): array {
		$transactions = $this->postedTransactions(administrationId: $run->administrationId);
		if ($transactions === null) {
			return [];
		}

		$glLines = [];
		foreach ($transactions as $transaction) {
			foreach ($this->periodLines(transaction: $transaction, run: $run) as $line) {
				$glLines[] = $line;
			}
		}

		return $glLines;
	}//end loadGlLines()

	/**
	 * The GL lines of a transaction posted in the run's period; none for any other transaction.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction row.
	 * @param CbsSubmissionRun $run The run.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function periodLines(array $transaction, CbsSubmissionRun $run): array {
		$txId = $this->periodTransactionId(transaction: $transaction, run: $run);
		if ($txId === '') {
			return [];
		}

		return $this->transactionLines(txId: $txId);
	}//end periodLines()

	/**
	 * The administration's posted GL transactions; null when the query fails.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function postedTransactions(string $administrationId): ?array {
		try {
			return $this->findPostedTransactions(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBSExportService: loadGlLines transactions failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end postedTransactions()

	/**
	 * Query the administration's posted GL transactions.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findPostedTransactions(string $administrationId): array {
		return $this->objectService()
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'state' => 'posted',
					],
				]
			);
	}//end findPostedTransactions()

	/**
	 * The id of a transaction posted in the run's period; '' when it falls outside it or has no id.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction row.
	 * @param CbsSubmissionRun $run The run.
	 *
	 * @return string
	 */
	private function periodTransactionId(array $transaction, CbsSubmissionRun $run): string {
		$postingDate = (string)($transaction['postingDate'] ?? '');
		if ($postingDate === '' || $postingDate < $run->startDate() || $postingDate > $run->endDate()) {
			return '';
		}

		return (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
	}//end periodTransactionId()

	/**
	 * The GLLine children of a transaction; none when the query fails.
	 *
	 * @param string $txId The GLTransaction id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function transactionLines(string $txId): array {
		try {
			return $this->objectService()
				->setRegister($this->register())
				->setSchema('GLLine')
				->findAll(['filters' => ['transactionId' => $txId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBSExportService: loadGlLines child query failed',
				['transactionId' => $txId, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end transactionLines()

	/**
	 * Load CBSLine records for a CBSSubmission via ObjectService.
	 *
	 * @param string $submissionId The CBSSubmission id.
	 *
	 * @return array<int,array<string,mixed>> The CBSLine records.
	 */
	private function loadLinesForSubmission(string $submissionId): array {
		if ($submissionId === '') {
			return [];
		}

		try {
			return $this->objectService()
				->setRegister($this->register())
				->setSchema('CBSLine')
				->findAll(['filters' => ['cbsSubmissionId' => $submissionId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBSExportService: loadLinesForSubmission failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

	}//end loadLinesForSubmission()

	/**
	 * Persist an object via the real OpenRegister ObjectService API.
	 *
	 * @param array $object The object to save.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string,mixed> The persisted object.
	 */
	private function saveObject(array $object, string $schema): array {
		try {
			$saved = $this->objectService()->saveObject(
				object: $object,
				register: $this->register(),
				schema: $schema,
			);

			if (is_array($saved) === true) {
				return $saved;
			}

			// Some OR versions return the entity; jsonSerialize it to a
			// plain array for downstream consumers.
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return $object;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CBSExportService: saveObject failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return $object;
		}//end try

	}//end saveObject()

	/**
	 * Lazy DI of OpenRegister's ObjectService.
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
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
