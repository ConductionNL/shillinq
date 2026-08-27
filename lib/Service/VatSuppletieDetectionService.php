<?php

/**
 * VAT Suppletie Detection Service
 *
 * Detection + compilation engine behind REQ-VBTW-009 (issue: `VatCorrection`
 * register + REQ-VBTW-009 landed, but no code ever created one —
 * `grep -ril VatCorrection lib/` returned only register JSON).
 *
 * Detects that a filed `VATReturn`'s underlying GL ledger has drifted from
 * what was declared, computes the delta per BTW-rubriek (type × taxRate),
 * decides against the statutory €1.000 suppletie-grens whether the operator
 * may fold the correction into the next regular return or must file a
 * formal suppletie within 8 weeks of discovery, and compiles a
 * `VatCorrection` with a filed/current snapshot (audit trail of the
 * original filed figures) plus a draft GL correction posting.
 *
 * Per ADR-031 this is deliberately imperative: it diffs two independently
 * queried schemas bucket-by-bucket, applies an operator-overridable
 * business decision (the €1.000 grens), and compiles a derived record with
 * a derived GL posting — cross-schema compilation logic, not a single
 * declarative aggregation (see design.md Decision 4).
 *
 * Bridges a pre-existing dual-schema situation: the only engine that
 * computes real per-rubriek totals from GL data is `VATReturnService`
 * against the `BtwAangifte`/`VATDeclaration`/`VATLine` schemas (the
 * mixed-case `VatReturn`'s declared rubrieken aggregation sources GLLine
 * fields — `vatRate`/`reverseCharge` — that do not exist on `GLLine`, so it
 * cannot run). This service therefore takes a `BtwAangifte.id` as
 * input and persists the result as a `VatCorrection` (the spec-mandated,
 * already-landed register), documented explicitly rather than silently
 * assumed — see design.md.
 *
 * `BtwAangifte` was called `VATReturn` until the slug collision with the
 * mixed-case `VatReturn` was fixed. The two were never actually distinct at
 * runtime: OpenRegister matches `LOWER(slug)` with `LIMIT 1` and no
 * ORDER BY, so `setSchema('VATReturn')` resolved to whichever row the
 * database returned first, and every write to the working model was
 * validated against the broken one's `required` list and 500'd. The
 * dual-schema situation this docblock describes was therefore not merely
 * untidy — it made the capability unusable. The two models are still
 * duplicates and should be consolidated; that is a product decision.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Detects filed-vs-ledger drift on a VATReturn and compiles a VatCorrection.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class VatSuppletieDetectionService {
	/**
	 * Statutory suppletie threshold in euros. Verified 2026-07-13 via
	 * WebSearch against belastingdienst.nl (not from training-data memory):
	 * corrections up to and including this amount may ride the next
	 * regular return; above it a formal suppletie is required, and (per
	 * the Belastingdienst's 1 January 2025 update) must be filed within
	 * 8 weeks of discovery or risk a "vergrijpboete".
	 *
	 * @see https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/btw/btw_aangifte_doen_en_betalen/aangifte_corrigeren/
	 *
	 * @var float
	 */
	public const SUPPLETIE_THRESHOLD_EUR = 1000.0;

	/**
	 * The statutory filing-deadline window once a threshold-exceeding
	 * correction is discovered: 8 weeks (ISO 8601 duration).
	 *
	 * @var string
	 */
	public const FILING_DEADLINE_INTERVAL = 'P8W';

	/**
	 * Fallback GL clearing account for the correction posting's offsetting
	 * leg, used when a rubriek's original account cannot be resolved (no
	 * seeded "BTW te betalen" account exists in the codebase today — see
	 * proposal.md Risk 3). Configurable via IAppConfig key
	 * `vatCorrectionClearingAccount`.
	 *
	 * @var string
	 */
	private const DEFAULT_CLEARING_ACCOUNT = '1699';

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService and
	 * a direct dependency on VATReturnService, whose GL-derivation engine
	 * this service re-uses rather than duplicating (REQ-VBTW-013).
	 *
	 * @param IAppConfig $appConfig App config for the register slug + clearing account.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param VATReturnService $vatReturnService The GL-derivation engine this service diffs against.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly VATReturnService $vatReturnService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Detect drift between a filed VATReturn and its underlying GL ledger
	 * (REQ-VBTW-013). Creates a `draft` VatCorrection with the filed +
	 * current snapshots when drift exists; returns null when the ledger
	 * still matches what was filed. Never mutates the original VATReturn,
	 * its VATDeclarations, or its VATLines.
	 *
	 * @param string $vatReturnId The filed VATReturn id.
	 *
	 * @return array<string,mixed>|null The created VatCorrection record, or null if no drift.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function detect(string $vatReturnId): ?array {
		$vatReturn = $this->vatReturnService->getReturn(returnId: $vatReturnId);
		$status = (string)($vatReturn['statusCode'] ?? '');
		if ($status === '' || $status === 'draft') {
			$statusLabel = 'unknown';
			if ($status !== '') {
				$statusLabel = $status;
			}

			throw new RuntimeException(
				sprintf(
					'VATReturn %s is %s; only a filed (submitted or later) return can be checked for drift.',
					$vatReturnId,
					$statusLabel
				)
			);
		}

		$administrationId = (string)($vatReturn['administrationId'] ?? '');
		$startDate = (string)($vatReturn['startDate'] ?? '');
		$endDate = (string)($vatReturn['endDate'] ?? '');

		$filed = $this->vatReturnService->fetchFiledDeclarations(returnId: $vatReturnId);
		$current = $this->vatReturnService->computeCurrentDeclarations(
			administrationId: $administrationId,
			startDate: $startDate,
			endDate: $endDate
		);

		if ($this->snapshotsMatch(filed: $filed, current: $current) === true) {
			return null;
		}

		$correction = [
			'administrationId' => $administrationId,
			'originalVatReturnId' => $vatReturnId,
			'originalReturnId' => $vatReturnId,
			'periodType' => (string)($vatReturn['period'] ?? 'quarter'),
			'periodYear' => (int)($vatReturn['periodYear'] ?? 0),
			'correctionAmount' => 0.0,
			'adjustmentAmount' => 0.0,
			'reason' => 'Automatisch gedetecteerde afwijking tussen de ingediende aangifte en het grootboek.',
			'correctionReason' => 'late-discovery',
			'currency' => (string)($vatReturn['currency'] ?? 'EUR'),
			'state' => 'draft',
			'filedSnapshot' => $filed,
			'currentSnapshot' => $current,
			'categoryDeltas' => [],
			'detectedAt' => $this->now(),
			'preparedAt' => null,
			'thresholdExceeded' => null,
			'filingDeadline' => null,
			'glCorrectionTransactionId' => null,
		];

		$this->applyPeriodNumberFields(correction: $correction, vatReturn: $vatReturn);

		return $this->saveObject(schema: 'VatCorrection', data: $correction);
	}//end detect()

	/**
	 * Compile a `detected` VatCorrection into a `prepared` one: per-rubriek
	 * deltas, the net correction amount, the €1.000-grens decision, the
	 * 8-week filing deadline, and a draft GL correction posting
	 * (REQ-VBTW-014). The operator still decides whether/when to file —
	 * this method never transitions the VatCorrection past `draft`.
	 *
	 * @param string $vatCorrectionId The `detected` VatCorrection id.
	 *
	 * @return array<string,mixed> The updated VatCorrection record.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function prepare(string $vatCorrectionId): array {
		$correction = $this->fetchCorrection(vatCorrectionId: $vatCorrectionId);
		if ((string)($correction['state'] ?? '') !== 'draft' || ($correction['preparedAt'] ?? null) !== null) {
			throw new RuntimeException(
				sprintf('VatCorrection %s is not in the detected sub-state (draft, unprepared).', $vatCorrectionId)
			);
		}

		$filed = (array)($correction['filedSnapshot'] ?? []);
		$current = (array)($correction['currentSnapshot'] ?? []);
		$deltas = $this->computeDeltas(filed: $filed, current: $current);

		$netCorrectionCt = 0;
		foreach ($deltas as $delta) {
			$deltaCt = (int)round(((float)$delta['deltaVATAmount']) * 100);
			if ($delta['type'] === 'collected') {
				$netCorrectionCt += $deltaCt;
			} else {
				// Paid + reverse-charge deltas move deductible VAT the
				// opposite way of net payable (REQ-VBTW-014).
				$netCorrectionCt -= $deltaCt;
			}
		}

		$netCorrection = round(($netCorrectionCt / 100), 2);
		$thresholdExceeded = (abs($netCorrection) >= self::SUPPLETIE_THRESHOLD_EUR);
		$preparedAt = new DateTimeImmutable();

		$administrationId = (string)($correction['administrationId'] ?? '');
		$originalReturnId = (string)($correction['originalVatReturnId'] ?? ($correction['originalReturnId'] ?? ''));
		$deltasWithAccount = $this->attachAccounts(deltas: $deltas, originalReturnId: $originalReturnId);

		$correction['categoryDeltas'] = $deltasWithAccount;
		$correction['correctionAmount'] = $netCorrection;
		$correction['adjustmentAmount'] = $netCorrection;
		$correction['thresholdExceeded'] = $thresholdExceeded;
		$correction['preparedAt'] = $preparedAt->format(DateTimeInterface::ATOM);

		$filingDeadline = null;
		if ($thresholdExceeded === true) {
			$deadline = $preparedAt->add(new DateInterval(self::FILING_DEADLINE_INTERVAL));
			$filingDeadline = $deadline->format('Y-m-d');
		}

		$correction['filingDeadline'] = $filingDeadline;

		$glTransactionId = null;
		if ($deltasWithAccount !== []) {
			$glTransactionId = $this->createCorrectionPosting(
				administrationId: $administrationId,
				vatCorrectionId: $vatCorrectionId,
				deltas: $deltasWithAccount
			);
		}

		$correction['glCorrectionTransactionId'] = $glTransactionId;

		return $this->saveObject(schema: 'VatCorrection', data: $correction);
	}//end prepare()

	/**
	 * Whether the filed and current rubriek snapshots are identical (no
	 * drift). Compares by (type, taxRate) bucket with a half-cent epsilon
	 * to avoid float noise false-positives.
	 *
	 * @param array<int,array<string,mixed>> $filed Filed snapshot buckets.
	 * @param array<int,array<string,mixed>> $current Current snapshot buckets.
	 *
	 * @return bool
	 */
	private function snapshotsMatch(array $filed, array $current): bool {
		return ($this->computeDeltas(filed: $filed, current: $current) === []);
	}//end snapshotsMatch()

	/**
	 * Diff two rubriek snapshots bucket-by-bucket (key = type:taxRate),
	 * returning only buckets with a non-zero VAT-amount delta.
	 *
	 * @param array<int,array<string,mixed>> $filed Filed snapshot buckets.
	 * @param array<int,array<string,mixed>> $current Current snapshot buckets.
	 *
	 * @return array<int,array{type:string,taxRate:float,deltaVATAmount:float,deltaTaxableAmount:float}>
	 */
	private function computeDeltas(array $filed, array $current): array {
		$byKey = [];
		foreach ($filed as $bucket) {
			$key = $this->bucketKey(bucket: $bucket);
			$this->ensureBucketDefaults(byKey: $byKey, key: $key, bucket: $bucket);
			$byKey[$key]['filedVAT'] = (float)($bucket['totalVATAmount'] ?? 0.0);
			$byKey[$key]['filedTaxable'] = (float)($bucket['totalTaxableAmount'] ?? 0.0);
		}

		foreach ($current as $bucket) {
			$key = $this->bucketKey(bucket: $bucket);
			$this->ensureBucketDefaults(byKey: $byKey, key: $key, bucket: $bucket);
			$byKey[$key]['currentVAT'] = (float)($bucket['totalVATAmount'] ?? 0.0);
			$byKey[$key]['currentTaxable'] = (float)($bucket['totalTaxableAmount'] ?? 0.0);
		}

		$deltas = [];
		foreach ($byKey as $bucket) {
			$deltaVAT = round((($bucket['currentVAT']) - ($bucket['filedVAT'])), 2);
			$deltaTaxable = round((($bucket['currentTaxable']) - ($bucket['filedTaxable'])), 2);

			// Half-cent epsilon — ignore float noise, not real drift.
			if (abs($deltaVAT) < 0.005 && abs($deltaTaxable) < 0.005) {
				continue;
			}

			$deltas[] = [
				'type' => $bucket['type'],
				'taxRate' => $bucket['taxRate'],
				'deltaVATAmount' => $deltaVAT,
				'deltaTaxableAmount' => $deltaTaxable,
			];
		}//end foreach

		return $deltas;
	}//end computeDeltas()

	/**
	 * Ensure a bucket accumulator entry exists with all six keys defaulted
	 * to zero before either loop in computeDeltas() writes into it. Without
	 * this, a rubriek present only in one snapshot (e.g. a genuinely new
	 * bucket that never existed at filing time) would leave the other
	 * snapshot's keys unset, which is both a real correctness risk (an
	 * undefined-array-key warning under PHP's strict settings) and — once
	 * ruled out here — lets computeDeltas() read every key unconditionally.
	 *
	 * @param array<string,array<string,mixed>> $byKey Accumulator (by reference).
	 * @param string $key The type:taxRate bucket key.
	 * @param array<string,mixed> $bucket The snapshot bucket supplying type/taxRate.
	 *
	 * @return void
	 */
	private function ensureBucketDefaults(array &$byKey, string $key, array $bucket): void {
		if (isset($byKey[$key]) === true) {
			return;
		}

		$byKey[$key] = [
			'type' => (string)$bucket['type'],
			'taxRate' => (float)$bucket['taxRate'],
			'filedVAT' => 0.0,
			'filedTaxable' => 0.0,
			'currentVAT' => 0.0,
			'currentTaxable' => 0.0,
		];

	}//end ensureBucketDefaults()

	/**
	 * Build the type:taxRate bucket key.
	 *
	 * @param array<string,mixed> $bucket A snapshot bucket.
	 *
	 * @return string
	 */
	private function bucketKey(array $bucket): string {
		return ((string)$bucket['type']) . ':' . number_format((float)$bucket['taxRate'], 2, '.', '');
	}//end bucketKey()

	/**
	 * Resolve the GL account each delta bucket originally posted to (looked
	 * up from the original return's persisted VATLine rows) and attach it
	 * to the delta entry for the correction posting to target.
	 *
	 * @param array<int,array<string,mixed>> $deltas Rubriek deltas.
	 * @param string $originalReturnId The VATReturn the deltas belong to.
	 *
	 * @return array<int,array<string,mixed>> Deltas with `glAccountNumber` attached.
	 */
	private function attachAccounts(array $deltas, string $originalReturnId): array {
		$lines = [];
		if ($originalReturnId !== '') {
			$lines = $this->objectService
				->setRegister($this->register())
				->setSchema('VATLine')
				->findAll(['filters' => ['returnId' => $originalReturnId]]);
		}

		$result = [];
		foreach ($deltas as $delta) {
			$account = null;
			foreach ($lines as $line) {
				if ((string)($line['type'] ?? '') === $delta['type']
					&& abs(((float)($line['taxRate'] ?? -1)) - $delta['taxRate']) < 0.001
				) {
					$account = (string)($line['glAccountNumber'] ?? '');
					break;
				}
			}

			if ($account === null || $account === '') {
				$this->logger->warning(
					'VatSuppletieDetectionService: no original account found for rubriek '
					. 'bucket; correction line will target the clearing account only',
					['type' => $delta['type'], 'taxRate' => $delta['taxRate']]
				);
			}

			$delta['glAccountNumber'] = $account;
			$result[] = $delta;
		}//end foreach

		return $result;
	}//end attachAccounts()

	/**
	 * Compile a draft GLTransaction booking the correction's per-rubriek
	 * deltas against their resolved accounts, balanced by an offsetting
	 * clearing-account line. Never auto-posted (REQ-VBTW-014).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $vatCorrectionId The VatCorrection id (sourceReference).
	 * @param array<int,array<string,mixed>> $deltas Rubriek deltas with glAccountNumber attached.
	 *
	 * @return string The created GLTransaction id.
	 */
	private function createCorrectionPosting(string $administrationId, string $vatCorrectionId, array $deltas): string {
		$clearingAccount = $this->appConfig->getValueString(Application::APP_ID, 'vatCorrectionClearingAccount', self::DEFAULT_CLEARING_ACCOUNT);
		if ($clearingAccount === '') {
			$clearingAccount = self::DEFAULT_CLEARING_ACCOUNT;
		}

		$postingDate = (new DateTimeImmutable())->format('Y-m-d');

		$transaction = [
			'transactionNumber' => sprintf('GLCORR-%s', substr($vatCorrectionId, 0, 12)),
			'postingDate' => $postingDate,
			'periodId' => $postingDate,
			'currency' => 'EUR',
			'description' => sprintf('BTW-suppletie correctie voor VatCorrection %s', $vatCorrectionId),
			'sourceReference' => $vatCorrectionId,
			'state' => 'draft',
			'administrationId' => $administrationId,
		];

		$savedTransaction = $this->saveObject(schema: 'GLTransaction', data: $transaction);
		$transactionId = (string)($savedTransaction['id'] ?? ($savedTransaction['@self']['id'] ?? ''));
		if ($transactionId === '') {
			throw new RuntimeException('GLTransaction save did not return an identifier.');
		}

		$lineNumber = 0;
		$totalDebitCt = 0;
		$totalCreditCt = 0;

		foreach ($deltas as $delta) {
			$amountCt = (int)round(abs((float)$delta['deltaVATAmount']) * 100);
			if ($amountCt <= 0) {
				continue;
			}

			$increases = (((float)$delta['deltaVATAmount']) > 0);
			// Collected (output tax) increase = credit the liability;
			// paid/reverse-charge (deductible) increase = debit the asset.
			$isCollected = ($delta['type'] === 'collected');
			$side = 'debit';
			if ($isCollected === $increases) {
				$side = 'credit';
			}

			$account = $clearingAccount;
			if ((string)($delta['glAccountNumber'] ?? '') !== '') {
				$account = (string)$delta['glAccountNumber'];
			}

			$lineNumber++;
			// The administrationId is DENORMALISED onto every line from the same
			// scope the header carries (REQ-GLS-001) — see
			// GlLineAdministrationBackfillMigrator. A line written without it
			// is invisible to its own administration's SpendAnalytics totals
			// AND flips the backfill completeness gate red instance-wide.
			$this->saveObject(
				schema: 'GLLine',
				data: [
					'transactionId' => $transactionId,
					'administrationId' => $administrationId,
					'lineNumber' => $lineNumber,
					'accountNumber' => $account,
					'side' => $side,
					'amount' => ($amountCt / 100),
					'currency' => 'EUR',
					'description' => sprintf('Suppletie %s %.2f%% delta', $delta['type'], $delta['taxRate']),
				]
			);

			if ($side === 'debit') {
				$totalDebitCt += $amountCt;
			} else {
				$totalCreditCt += $amountCt;
			}
		}//end foreach

		$clearingCt = abs($totalDebitCt - $totalCreditCt);
		if ($clearingCt > 0) {
			$clearingSide = 'debit';
			if ($totalDebitCt > $totalCreditCt) {
				$clearingSide = 'credit';
			}

			$lineNumber++;
			$this->saveObject(
				schema: 'GLLine',
				data: [
					'transactionId' => $transactionId,
					'administrationId' => $administrationId,
					'lineNumber' => $lineNumber,
					'accountNumber' => $clearingAccount,
					'side' => $clearingSide,
					'amount' => ($clearingCt / 100),
					'currency' => 'EUR',
					'description' => 'Suppletie clearing balans',
				]
			);
		}

		return $transactionId;
	}//end createCorrectionPosting()

	/**
	 * Split a VATReturn's `period`/`periodNumber` onto the VatCorrection's
	 * `periodMonth`/`periodQuarter` fields to match the register's shape.
	 *
	 * @param array<string,mixed> $correction The correction payload (by reference).
	 * @param array<string,mixed> $vatReturn The source VATReturn.
	 *
	 * @return void
	 */
	private function applyPeriodNumberFields(array &$correction, array $vatReturn): void {
		$period = (string)($vatReturn['period'] ?? 'quarter');
		$periodNumber = (int)($vatReturn['periodNumber'] ?? 0);

		if ($period === 'month') {
			$correction['periodMonth'] = $periodNumber;
		} elseif ($period === 'quarter') {
			$correction['periodQuarter'] = $periodNumber;
		}

	}//end applyPeriodNumberFields()

	/**
	 * Fetch a VatCorrection by id.
	 *
	 * @param string $vatCorrectionId VatCorrection id.
	 *
	 * @return array<string,mixed>
	 */
	private function fetchCorrection(string $vatCorrectionId): array {
		$correction = $this->objectService
			->setRegister($this->register())
			->setSchema('VatCorrection')
			->find($vatCorrectionId);

		if ($correction === null) {
			throw new RuntimeException(sprintf('VatCorrection %s not found', $vatCorrectionId));
		}

		return $correction->jsonSerialize();
	}//end fetchCorrection()

	/**
	 * Persist a record via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record (with id).
	 */
	private function saveObject(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		return $saved->jsonSerialize();
	}//end saveObject()

	/**
	 * Current UTC timestamp, ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
