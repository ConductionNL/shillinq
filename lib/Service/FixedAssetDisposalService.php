<?php

/**
 * Fixed-Asset Disposal Service
 *
 * Change revive-gl-tax-capabilities: the missing disposal-posting trigger.
 * {@see \OCA\Shillinq\Service\DisposalJournalEmitter} shapes a balanced
 * closing `GLTransaction` for a retired `FixedAsset` (REQ-FA-006 /
 * REQ-FA-008) and is fully unit-tested — but nothing ever called it
 * (hydra gate-52, shillinq#417/#446), so an asset could be retired while
 * its carrying amount stayed on the balance sheet forever and the
 * boekwinst/boekverlies never reached the P&L.
 *
 * This service is the orchestrator between the lifecycle transition and
 * the pure-logic emitter:
 *
 *   1. Normalises the persisted `FixedAsset` record into the shape the
 *      emitter expects. The shipped schema names the fields
 *      `purchaseCost` / `capitalizationAccountNumber` /
 *      `accumulatedDepreciationAccountNumber` / `retirementDate` /
 *      `salvageProceeds`, while the emitter (and the demo seed) use
 *      `acquisitionCost` / `assetAccountNumber` /
 *      `accumulatedDepAccountNumber` / `disposalDate` /
 *      `disposalProceeds`. Passing a raw record straight through would
 *      have produced an EMPTY journal — balanced, silent, worthless
 *      (design D4).
 *   2. Resolves the accumulated depreciation that was actually POSTED,
 *      from the latest `DepreciationSchedule` row for the asset, and
 *      hands the resulting book value to the emitter. Reversing a
 *      recomputed figure instead would leave a residual balance on the
 *      accumulated-depreciation account (design D3).
 *   3. Asserts the emitted lines balance (the emitter's own
 *      `linesBalance()`, mirroring `JournalEntryGuard::canPost`) and
 *      refuses to persist an unbalanced journal.
 *   4. Persists the header + lines through the real OpenRegister
 *      ObjectService API, exactly as
 *      {@see \OCA\Shillinq\Service\GRIRClearingService} does.
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Posts the closing GL journal for a disposed FixedAsset (REQ-GLTAX-001).
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class FixedAssetDisposalService {

	/**
	 * App-config key holding the disposal-gain P&L account.
	 *
	 * @var string
	 */
	public const CFG_GAIN_ACCOUNT = 'fixed_asset_disposal_gain_account';

	/**
	 * App-config key holding the disposal-loss P&L account.
	 *
	 * @var string
	 */
	public const CFG_LOSS_ACCOUNT = 'fixed_asset_disposal_loss_account';

	/**
	 * App-config key holding the disposal-proceeds clearing account.
	 *
	 * @var string
	 */
	public const CFG_CLEARING_ACCOUNT = 'fixed_asset_disposal_clearing_account';

	/**
	 * DepreciationSchedule schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_SCHEDULE = 'DepreciationSchedule';

	/**
	 * GLTransaction schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_GL_TXN = 'GLTransaction';

	/**
	 * GLLine schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Journal code prefix for the disposal transaction number.
	 *
	 * @var string
	 */
	private const JOURNAL_CODE = 'FA-DISP';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config (account codes + register slug).
	 * @param DisposalJournalEmitter $emitter The pure-logic disposal journal kernel.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) administrationContext is the
	 * canonical name fleet-wide.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DisposalJournalEmitter $emitter,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Materialise the disposal journal for a retired FixedAsset (REQ-GLTAX-001).
	 *
	 * Wired by {@see \OCA\Shillinq\Listener\FixedAssetDisposalListener} on
	 * the `FixedAsset` `active -> retired` transition.
	 *
	 * @param string $administrationId Tenant scope (server-resolved).
	 * @param array<string,mixed> $asset The retired FixedAsset record.
	 *
	 * @return array<string,mixed> Result envelope: {posted:bool, transaction:?array,
	 *                             lines:array, bookValue:float, gain:float, loss:float,
	 *                             amountCents:int, message:string}.
	 *
	 * @throws \RuntimeException When the administration is inaccessible or the
	 *                           emitted lines do not balance.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function postDisposalJournal(string $administrationId, array $asset): array {
		$this->assertAccess(administrationId: $administrationId);

		$normalised = $this->normaliseAsset(asset: $asset);

		$costCents = (int)round(((float)($normalised['acquisitionCost'] ?? 0)) * 100.0);
		if ($costCents <= 0) {
			return [
				'posted' => false,
				'message' => 'FixedAsset carries no acquisition cost; disposal posting skipped',
			];
		}

		$disposal = [
			'disposalDate' => $this->disposalDate(asset: $normalised),
			'disposalAccountingTreatment' => (string)($normalised['disposalAccountingTreatment'] ?? 'sale'),
			'disposalProceeds' => (float)($normalised['disposalProceeds'] ?? 0),
		];

		$bookValue = $this->postedBookValue(
			administrationId: $administrationId,
			asset: $normalised
		);

		$journal = $this->emitter->emit(
			asset: $normalised,
			disposal: $disposal,
			accounts: $this->accounts(),
			bookValue: $bookValue
		);

		$lines = $journal['lines'];
		if ($this->emitter->linesBalance(lines: $lines) === false) {
			throw new RuntimeException('FixedAssetDisposalService: GL balance invariant failed for the disposal journal');
		}

		if ($lines === []) {
			return [
				'posted' => false,
				'message' => 'Disposal produced no GL lines; nothing to post',
			];
		}

		return $this->persist(
			administrationId: $administrationId,
			journal: $journal,
			asset: $normalised
		);

	}//end postDisposalJournal()

	/**
	 * Normalise a persisted FixedAsset into the shape the emitter reads (design D4).
	 *
	 * The shipped schema and the demo seed disagree on five field names; both
	 * spellings are accepted so hand-created and seeded records post the same
	 * journal.
	 *
	 * @param array<string,mixed> $asset The raw FixedAsset record.
	 *
	 * @return array<string,mixed> The normalised record.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	private function normaliseAsset(array $asset): array {
		$normalised = $asset;

		$normalised['assetNumber'] = $this->firstNonEmpty(
			candidates: [
				($asset['assetNumber'] ?? null),
				($asset['id'] ?? null),
				(($asset['@self'] ?? [])['id'] ?? null),
			]
		);

		$normalised['acquisitionCost'] = $this->firstNumeric(
			candidates: [
				($asset['acquisitionCost'] ?? null),
				($asset['purchaseCost'] ?? null),
			]
		);

		$normalised['assetAccountNumber'] = $this->firstNonEmpty(
			candidates: [
				($asset['assetAccountNumber'] ?? null),
				($asset['capitalizationAccountNumber'] ?? null),
			]
		);

		$normalised['accumulatedDepAccountNumber'] = $this->firstNonEmpty(
			candidates: [
				($asset['accumulatedDepAccountNumber'] ?? null),
				($asset['accumulatedDepreciationAccountNumber'] ?? null),
			]
		);

		$normalised['disposalProceeds'] = $this->firstNumeric(
			candidates: [
				($asset['disposalProceeds'] ?? null),
				($asset['salvageProceeds'] ?? null),
			]
		);

		$normalised['currency'] = $this->firstNonEmpty(candidates: [($asset['currency'] ?? null), 'EUR']);

		return $normalised;
	}//end normaliseAsset()

	/**
	 * Resolve the disposal date from the record (design D4).
	 *
	 * @param array<string,mixed> $asset The normalised FixedAsset record.
	 *
	 * @return string Disposal date (Y-m-d).
	 */
	private function disposalDate(array $asset): string {
		$date = $this->firstNonEmpty(
			candidates: [
				($asset['disposalDate'] ?? null),
				($asset['retirementDate'] ?? null),
			]
		);

		if ($date === '') {
			return date('Y-m-d');
		}

		return substr($date, 0, 10);
	}//end disposalDate()

	/**
	 * Net book value at disposal, derived from the depreciation actually POSTED (design D3).
	 *
	 * Sums nothing: `DepreciationSchedule.accumulatedDepreciation` is already
	 * cumulative through `periodEndDate`, so the latest schedule row on or
	 * before the disposal date carries the total. Returns null when the asset
	 * has no schedule rows at all — the emitter then falls back to
	 * `DepreciationCalculator::currentBookValue()` exactly as before.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $asset The normalised FixedAsset record.
	 *
	 * @return float|null The posted book value, or null when no schedule exists.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	private function postedBookValue(string $administrationId, array $asset): ?float {
		$assetId = (string)($asset['id'] ?? (($asset['@self'] ?? [])['id'] ?? ''));
		$assetNumber = (string)($asset['assetNumber'] ?? '');

		$rows = [];
		if ($assetId !== '') {
			$rows = $this->findAll(
				schema: self::SCHEMA_SCHEDULE,
				filters: [
					'assetRef' => $assetId,
					'administrationId' => $administrationId,
				]
			);
		}

		if ($rows === [] && $assetNumber !== '') {
			$rows = $this->findAll(
				schema: self::SCHEMA_SCHEDULE,
				filters: [
					'assetNumber' => $assetNumber,
					'administrationId' => $administrationId,
				]
			);
		}

		if ($rows === []) {
			return null;
		}

		$disposalDate = $this->disposalDate(asset: $asset);
		$bestDate = '';
		$accumulatedCents = null;
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if ($status === 'planned') {
				continue;
			}

			$periodEnd = substr((string)($row['periodEndDate'] ?? ''), 0, 10);
			if ($periodEnd !== '' && $periodEnd > $disposalDate) {
				continue;
			}

			if ($periodEnd < $bestDate) {
				continue;
			}

			$bestDate = $periodEnd;
			$accumulatedCents = (int)round(((float)($row['accumulatedDepreciation'] ?? 0)) * 100.0);
		}

		if ($accumulatedCents === null) {
			return null;
		}

		$costCents = (int)round(((float)($asset['acquisitionCost'] ?? 0)) * 100.0);
		$bookCents = max(0, ($costCents - $accumulatedCents));

		return round(($bookCents / 100.0), 2);
	}//end postedBookValue()

	/**
	 * Persist the emitted header + lines through the real ObjectService API.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $journal The emitter's payload.
	 * @param array<string,mixed> $asset The normalised FixedAsset record.
	 *
	 * @return array<string,mixed> Result envelope.
	 *
	 * @throws \RuntimeException When persistence fails.
	 */
	private function persist(string $administrationId, array $journal, array $asset): array {
		$header = $journal['header'];
		$lines = $journal['lines'];
		$assetNumber = (string)($asset['assetNumber'] ?? '');
		$postingDate = (string)($header['postingDate'] ?? date('Y-m-d'));
		$periodId = $this->periodId(postingDate: $postingDate);

		$transaction = [
			'transactionNumber' => self::JOURNAL_CODE . '-' . $assetNumber,
			'postingDate' => $postingDate,
			'periodId' => $periodId,
			'currency' => (string)($header['currency'] ?? 'EUR'),
			'description' => (string)($header['description'] ?? 'Fixed asset disposal'),
			'sourceReference' => (string)($header['sourceReference'] ?? ''),
			'state' => 'draft',
			'administrationId' => $administrationId,
		];

		try {
			$saved = $this->saveOnSchema(schema: self::SCHEMA_GL_TXN, data: $transaction);
			$headerId = (string)($saved['id'] ?? (($saved['@self'] ?? [])['id'] ?? ''));

			$lineNumber = 1;
			$persistedLines = [];
			$debitCents = 0;
			foreach ($lines as $line) {
				$amountCents = (int)round(((float)($line['amount'] ?? 0)) * 100.0);
				if ((string)($line['side'] ?? '') === 'debit') {
					$debitCents += $amountCents;
				}

				$persistedLines[] = $this->saveOnSchema(
					schema: self::SCHEMA_GL_LINE,
					data: [
						'transactionId' => $headerId,
						'lineNumber' => $lineNumber,
						'accountNumber' => (string)($line['accountNumber'] ?? ''),
						'side' => (string)($line['side'] ?? ''),
						'amount' => round(((float)($line['amount'] ?? 0)), 2),
						'currency' => (string)($header['currency'] ?? 'EUR'),
						'description' => (string)($line['description'] ?? ''),
						'subLedgerType' => 'fixed-asset',
						'subLedgerRef' => $assetNumber,
						'costCenterCode' => (string)($asset['costCenterCode'] ?? ''),
						'periodId' => $periodId,
						'administrationId' => $administrationId,
					]
				);
				$lineNumber++;
			}//end foreach

			return [
				'posted' => true,
				'transaction' => $saved,
				'lines' => $persistedLines,
				'bookValue' => (float)($journal['bookValue'] ?? 0),
				'gain' => (float)($journal['gain'] ?? 0),
				'loss' => (float)($journal['loss'] ?? 0),
				'amountCents' => $debitCents,
				'message' => 'Fixed-asset disposal journal materialised',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'FixedAssetDisposalService: failed to persist the disposal journal',
				[
					'transactionNumber' => $transaction['transactionNumber'],
					'exception' => $e->getMessage(),
				]
			);
			throw new RuntimeException('Failed to persist the fixed-asset disposal journal');
		}//end try

	}//end persist()

	/**
	 * Resolve the configured gain / loss / clearing accounts.
	 *
	 * An unset key falls through to the emitter's own RGS-3.5 placeholder
	 * defaults (it merges over them), so a missing configuration never
	 * produces an empty account number on a line.
	 *
	 * @return array<string,string> Account overrides for the emitter.
	 */
	private function accounts(): array {
		$accounts = [];
		$map = [
			'gainAccountNumber' => self::CFG_GAIN_ACCOUNT,
			'lossAccountNumber' => self::CFG_LOSS_ACCOUNT,
			'clearingAccountNumber' => self::CFG_CLEARING_ACCOUNT,
		];

		foreach ($map as $key => $configKey) {
			$value = trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
			if ($value !== '') {
				$accounts[$key] = $value;
			}
		}

		return $accounts;
	}//end accounts()

	/**
	 * Derive the fiscal period id (YYYY-MM) from a posting date.
	 *
	 * @param string $postingDate Posting date (Y-m-d).
	 *
	 * @return string Period id.
	 */
	private function periodId(string $postingDate): string {
		if (preg_match('/^(\d{4})-(\d{2})/', $postingDate, $matches) === 1) {
			return $matches[1] . '-' . $matches[2];
		}

		return date('Y-m');
	}//end periodId()

	/**
	 * First non-empty string from the candidates.
	 *
	 * @param array<int,mixed> $candidates Candidate values.
	 *
	 * @return string The first non-empty candidate, '' when none.
	 */
	private function firstNonEmpty(array $candidates): string {
		foreach ($candidates as $candidate) {
			if ($candidate === null) {
				continue;
			}

			$value = trim((string)$candidate);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstNonEmpty()

	/**
	 * First numeric value from the candidates.
	 *
	 * @param array<int,mixed> $candidates Candidate values.
	 *
	 * @return float The first numeric candidate, 0.0 when none.
	 */
	private function firstNumeric(array $candidates): float {
		foreach ($candidates as $candidate) {
			if ($candidate === null || is_numeric($candidate) === false) {
				continue;
			}

			return (float)$candidate;
		}

		return 0.0;
	}//end firstNumeric()

	/**
	 * Persist on the configured register, returning the canonical array payload.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the row type is unsupported.
	 */
	private function saveOnSchema(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, which
		// extends JsonSerializable and declares `getObject(): array` — so the
		// is_object()/method_exists() guards that used to wrap these two calls
		// could never be false, and the trailing throw was unreachable.
		// jsonSerialize() still returns mixed, so that check stays.
		$out = $saved->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return $saved->getObject();
	}//end saveOnSchema()

	/**
	 * Find all records via the real ObjectService API (findAll).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FixedAssetDisposalService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Assert the caller can access the requested administration; mask as
	 * not-found per ADR-005.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is not accessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()
}//end class
