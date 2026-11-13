<?php

/**
 * VAT Suppletie Detection Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

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
		$this->assertFiledReturn(vatReturnId: $vatReturnId, vatReturn: $vatReturn);

		$filed   = $this->vatReturnService->fetchFiledDeclarations(returnId: $vatReturnId);
		$current = $this->currentDeclarations(vatReturn: $vatReturn);

		if ($this->snapshotsMatch(filed: $filed, current: $current) === true) {
			return null;
		}

		$correction = $this->draftCorrection(vatReturnId: $vatReturnId, vatReturn: $vatReturn);
		$correction['filedSnapshot']   = $filed;
		$correction['currentSnapshot'] = $current;
		$correction['detectedAt']      = $this->now();

		$this->applyPeriodNumberFields(correction: $correction, vatReturn: $vatReturn);

		return $this->saveObject(schema: 'VatCorrection', data: $correction);
	}//end detect()

	/**
	 * Refuse a VATReturn that has not been filed yet.
	 *
	 * @param string              $vatReturnId The VATReturn id.
	 * @param array<string,mixed> $vatReturn   The VATReturn.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the return is a draft or has no status.
	 */
	private function assertFiledReturn(string $vatReturnId, array $vatReturn): void {
		$status = (string)($vatReturn['statusCode'] ?? '');
		if ($status !== '' && $status !== 'draft') {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'VATReturn %s is %s; only a filed (submitted or later) return can be checked for drift.',
				$vatReturnId,
				$this->statusLabel(status: $status)
			)
		);

	}//end assertFiledReturn()

	/**
	 * The status as named in the refusal: the status, or 'unknown' when empty.
	 *
	 * @param string $status The VATReturn statusCode.
	 *
	 * @return string
	 */
	private function statusLabel(string $status): string {
		if ($status === '') {
			return 'unknown';
		}

		return $status;

	}//end statusLabel()

	/**
	 * The rubriek totals the ledger yields today for the return's period.
	 *
	 * @param array<string,mixed> $vatReturn The filed VATReturn.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function currentDeclarations(array $vatReturn): array {
		return $this->vatReturnService->computeCurrentDeclarations(
			administrationId: (string)($vatReturn['administrationId'] ?? ''),
			startDate: (string)($vatReturn['startDate'] ?? ''),
			endDate: (string)($vatReturn['endDate'] ?? '')
		);

	}//end currentDeclarations()

	/**
	 * The draft VatCorrection record for a drifted return, snapshots and
	 * detection time still to be filled in.
	 *
	 * @param string              $vatReturnId The filed VATReturn id.
	 * @param array<string,mixed> $vatReturn   The filed VATReturn.
	 *
	 * @return array<string,mixed>
	 */
	private function draftCorrection(string $vatReturnId, array $vatReturn): array {
		return [
			'administrationId' => (string)($vatReturn['administrationId'] ?? ''),
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
			'filedSnapshot' => [],
			'currentSnapshot' => [],
			'categoryDeltas' => [],
			'detectedAt' => null,
			'preparedAt' => null,
			'thresholdExceeded' => null,
			'filingDeadline' => null,
			'glCorrectionTransactionId' => null,
		];

	}//end draftCorrection()

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
		$correction = $this->fetchDetectedCorrection(vatCorrectionId: $vatCorrectionId);
		$deltas     = $this->snapshotDeltas(correction: $correction);

		$netCorrection = $this->netCorrection(deltas: $deltas);
		$preparedAt    = new DateTimeImmutable();
		$deltasWithAccount = $this->attachAccounts(deltas: $deltas, originalReturnId: $this->originalReturnId(correction: $correction));

		$correction['categoryDeltas'] = $deltasWithAccount;
		$correction = $this->stampNetCorrection(correction: $correction, netCorrection: $netCorrection);
		$correction['preparedAt']     = $preparedAt->format(DateTimeInterface::ATOM);
		$correction['filingDeadline'] = $this->filingDeadline(preparedAt: $preparedAt, thresholdExceeded: $correction['thresholdExceeded']);

		$run = new VatCorrectionPostingRun(administrationId: (string)($correction['administrationId'] ?? ''), vatCorrectionId: $vatCorrectionId);
		$correction['glCorrectionTransactionId'] = $this->postCorrection(run: $run, deltas: $deltasWithAccount);

		return $this->saveObject(schema: 'VatCorrection', data: $correction);
	}//end prepare()

	/**
	 * Fetch a VatCorrection that is still in the detected sub-state.
	 *
	 * @param string $vatCorrectionId The VatCorrection id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When it is not a draft or was already prepared.
	 */
	private function fetchDetectedCorrection(string $vatCorrectionId): array {
		$correction = $this->fetchCorrection(vatCorrectionId: $vatCorrectionId);
		if ((string)($correction['state'] ?? '') !== 'draft' || ($correction['preparedAt'] ?? null) !== null) {
			throw new RuntimeException(
				sprintf('VatCorrection %s is not in the detected sub-state (draft, unprepared).', $vatCorrectionId)
			);
		}

		return $correction;

	}//end fetchDetectedCorrection()

	/**
	 * The per-rubriek deltas between a correction's filed and current snapshots.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshotDeltas(array $correction): array {
		$filed   = (array)($correction['filedSnapshot'] ?? []);
		$current = (array)($correction['currentSnapshot'] ?? []);

		return $this->computeDeltas(filed: $filed, current: $current);

	}//end snapshotDeltas()

	/**
	 * The net change in VAT payable, in euros: owed deltas add, deductible
	 * deltas subtract (REQ-VBTW-014).
	 *
	 * @param array<int,array<string,mixed>> $deltas Rubriek deltas.
	 *
	 * @return float
	 */
	private function netCorrection(array $deltas): float {
		$netCorrectionCt = 0;
		foreach ($deltas as $delta) {
			$deltaCt = (int)round(((float)$delta['deltaVATAmount']) * 100);
			if ($this->isOwed(delta: $delta) === true) {
				$netCorrectionCt += $deltaCt;
			} else {
				// Deductible VAT deltas move net payable the opposite
				// way (REQ-VBTW-014).
				$netCorrectionCt -= $deltaCt;
			}
		}

		return round(($netCorrectionCt / 100), 2);

	}//end netCorrection()

	/**
	 * The VATReturn a correction belongs to.
	 *
	 * @param array<string,mixed> $correction The VatCorrection.
	 *
	 * @return string
	 */
	private function originalReturnId(array $correction): string {
		return (string)($correction['originalVatReturnId'] ?? ($correction['originalReturnId'] ?? ''));

	}//end originalReturnId()

	/**
	 * Write the net correction and the €1.000-grens decision onto the correction.
	 *
	 * @param array<string,mixed> $correction    The VatCorrection.
	 * @param float               $netCorrection The net correction in euros.
	 *
	 * @return array<string,mixed>
	 */
	private function stampNetCorrection(array $correction, float $netCorrection): array {
		$correction['correctionAmount']  = $netCorrection;
		$correction['adjustmentAmount']  = $netCorrection;
		$correction['thresholdExceeded'] = (abs($netCorrection) >= self::SUPPLETIE_THRESHOLD_EUR);

		return $correction;

	}//end stampNetCorrection()

	/**
	 * The filing deadline: 8 weeks after preparation when the grens is exceeded.
	 *
	 * @param DateTimeImmutable $preparedAt        When the correction was prepared.
	 * @param bool              $thresholdExceeded Whether the €1.000-grens is exceeded.
	 *
	 * @return string|null Y-m-d, or null below the grens.
	 */
	private function filingDeadline(DateTimeImmutable $preparedAt, bool $thresholdExceeded): ?string {
		if ($thresholdExceeded === false) {
			return null;
		}

		return $preparedAt->add(new DateInterval(self::FILING_DEADLINE_INTERVAL))->format('Y-m-d');

	}//end filingDeadline()

	/**
	 * Compile the draft GL correction posting, when there is anything to book.
	 *
	 * @param VatCorrectionPostingRun        $run    The posting run.
	 * @param array<int,array<string,mixed>> $deltas Rubriek deltas with glAccountNumber attached.
	 *
	 * @return string|null The created GLTransaction id, or null without deltas.
	 */
	private function postCorrection(VatCorrectionPostingRun $run, array $deltas): ?string {
		if ($deltas === []) {
			return null;
		}

		return $this->createCorrectionPosting(run: $run, deltas: $deltas);

	}//end postCorrection()

	/**
	 * Whether the filed and current rubriek snapshots are identical (no
	 * drift). Compares by declaration key (the box, or type and rate for a
	 * return filed before boxes) with a half-cent epsilon
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
	 * Diff two rubriek snapshots bucket-by-bucket (key =
	 * VATReturnService::declarationKey(): the box, or type:taxRate for a
	 * return filed before boxes), returning only buckets with a non-zero
	 * VAT-amount delta.
	 *
	 * @param array<int,array<string,mixed>> $filed Filed snapshot buckets.
	 * @param array<int,array<string,mixed>> $current Current snapshot buckets.
	 *
	 * @return array<int,array{type:string,taxRate:float,returnBox:string,deltaVATAmount:float,deltaTaxableAmount:float}>
	 */
	private function computeDeltas(array $filed, array $current): array {
		$deltas = [];
		foreach ($this->indexBuckets(filed: $filed, current: $current) as $bucket) {
			$delta = $this->bucketDelta(bucket: $bucket);
			if ($delta !== null) {
				$deltas[] = $delta;
			}
		}

		return $deltas;
	}//end computeDeltas()

	/**
	 * Index the filed and current buckets by declaration key, each entry
	 * carrying the filed and the current VAT and taxable amounts.
	 *
	 * @param array<int,array<string,mixed>> $filed   Filed snapshot buckets.
	 * @param array<int,array<string,mixed>> $current Current snapshot buckets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function indexBuckets(array $filed, array $current): array {
		$byKey = [];
		foreach ($filed as $bucket) {
			$key = $this->vatReturnService->declarationKey(bucket: $bucket);
			$this->ensureBucketDefaults(byKey: $byKey, key: $key, bucket: $bucket);
			$byKey[$key]['filedVAT'] = (float)($bucket['totalVATAmount'] ?? 0.0);
			$byKey[$key]['filedTaxable'] = (float)($bucket['totalTaxableAmount'] ?? 0.0);
		}

		foreach ($current as $bucket) {
			$key = $this->vatReturnService->declarationKey(bucket: $bucket);
			$this->ensureBucketDefaults(byKey: $byKey, key: $key, bucket: $bucket);
			$byKey[$key]['currentVAT'] = (float)($bucket['totalVATAmount'] ?? 0.0);
			$byKey[$key]['currentTaxable'] = (float)($bucket['totalTaxableAmount'] ?? 0.0);
		}

		return $byKey;

	}//end indexBuckets()

	/**
	 * The delta of one indexed bucket, or null when it is float noise.
	 *
	 * @param array<string,mixed> $bucket An entry of indexBuckets().
	 *
	 * @return array{type:string,taxRate:float,returnBox:string,deltaVATAmount:float,deltaTaxableAmount:float}|null
	 */
	private function bucketDelta(array $bucket): ?array {
		$deltaVAT = round((($bucket['currentVAT']) - ($bucket['filedVAT'])), 2);
		$deltaTaxable = round((($bucket['currentTaxable']) - ($bucket['filedTaxable'])), 2);

		// Half-cent epsilon — ignore float noise, not real drift.
		if (abs($deltaVAT) < 0.005 && abs($deltaTaxable) < 0.005) {
			return null;
		}

		return [
			'type' => $bucket['type'],
			'taxRate' => $bucket['taxRate'],
			'returnBox' => $bucket['returnBox'],
			'deltaVATAmount' => $deltaVAT,
			'deltaTaxableAmount' => $deltaTaxable,
		];

	}//end bucketDelta()

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
	 * @param string $key The declaration key.
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
			'taxRate' => (float)($bucket['taxRate'] ?? 0.0),
			'returnBox' => (string)($bucket['returnBox'] ?? ''),
			'filedVAT' => 0.0,
			'filedTaxable' => 0.0,
			'currentVAT' => 0.0,
			'currentTaxable' => 0.0,
		];

	}//end ensureBucketDefaults()

	/**
	 * Whether a delta moves VAT owed (true) or VAT deductible (false). A box
	 * delta is owed unless it is input VAT (5b), which includes the VAT owed
	 * on a reverse-charged purchase in 2a, 4a or 4b; a delta of a return filed
	 * before boxes is owed only when it is collected, as before.
	 *
	 * @param array<string,mixed> $delta A delta from computeDeltas().
	 *
	 * @return bool
	 */
	private function isOwed(array $delta): bool {
		$box = (string)($delta['returnBox'] ?? '');
		if ($box !== '') {
			return ($box !== VatLineStamper::INPUT_VAT_BOX);
		}

		return ($delta['type'] === 'collected');
	}//end isOwed()

	/**
	 * Whether a VATLine of the filed return is where a delta's VAT was booked:
	 * for a box, the line of that box on its VAT account; before boxes, the
	 * line of the same type and rate.
	 *
	 * @param array<string,mixed> $line A VATLine of the filed return.
	 * @param array<string,mixed> $delta A delta from computeDeltas().
	 *
	 * @return bool
	 */
	private function lineCarries(array $line, array $delta): bool {
		$box = (string)($delta['returnBox'] ?? '');
		if ($box !== '') {
			return ((string)($line['returnBox'] ?? '') === $box && ((float)($line['vatAmount'] ?? 0.0)) !== 0.0);
		}

		return ((string)($line['type'] ?? '') === $delta['type']
			&& abs(((float)($line['taxRate'] ?? -1)) - $delta['taxRate']) < 0.001);
	}//end lineCarries()

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
		$lines = $this->returnLines(originalReturnId: $originalReturnId);

		$result = [];
		foreach ($deltas as $delta) {
			$account = $this->accountCarrying(lines: $lines, delta: $delta);
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
	 * The persisted VATLine rows of a return; none without a return id.
	 *
	 * @param string $originalReturnId The VATReturn id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function returnLines(string $originalReturnId): array {
		if ($originalReturnId === '') {
			return [];
		}

		return $this->objectService
			->setRegister($this->register())
			->setSchema('VATLine')
			->findAll(['filters' => ['returnId' => $originalReturnId]]);

	}//end returnLines()

	/**
	 * The GL account of the first line that carries the delta, or null.
	 *
	 * @param array<int,array<string,mixed>> $lines The return's VATLine rows.
	 * @param array<string,mixed>            $delta A delta from computeDeltas().
	 *
	 * @return string|null
	 */
	private function accountCarrying(array $lines, array $delta): ?string {
		foreach ($lines as $line) {
			if ($this->lineCarries(line: $line, delta: $delta) === true) {
				return (string)($line['glAccountNumber'] ?? '');
			}
		}

		return null;

	}//end accountCarrying()

	/**
	 * Compile a draft GLTransaction booking the correction's per-rubriek
	 * deltas against their resolved accounts, balanced by an offsetting
	 * clearing-account line. Never auto-posted (REQ-VBTW-014).
	 *
	 * @param VatCorrectionPostingRun        $run    The posting run (administration, VatCorrection id).
	 * @param array<int,array<string,mixed>> $deltas Rubriek deltas with glAccountNumber attached.
	 *
	 * @return string The created GLTransaction id.
	 */
	private function createCorrectionPosting(VatCorrectionPostingRun $run, array $deltas): string {
		$run->clearingAccount = $this->clearingAccount();
		$run->transactionId   = $this->saveCorrectionTransaction(run: $run);

		foreach ($deltas as $delta) {
			$this->postDeltaLine(run: $run, delta: $delta);
		}

		$this->postClearingLine(run: $run);

		return $run->transactionId;
	}//end createCorrectionPosting()

	/**
	 * The configured clearing account, else the default one.
	 *
	 * @return string
	 */
	private function clearingAccount(): string {
		$clearingAccount = $this->appConfig->getValueString(AppIdentity::APP_ID, 'vatCorrectionClearingAccount', self::DEFAULT_CLEARING_ACCOUNT);
		if ($clearingAccount === '') {
			return self::DEFAULT_CLEARING_ACCOUNT;
		}

		return $clearingAccount;

	}//end clearingAccount()

	/**
	 * Save the draft GLTransaction header and return its id.
	 *
	 * @param VatCorrectionPostingRun $run The posting run.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the save returns no identifier.
	 */
	private function saveCorrectionTransaction(VatCorrectionPostingRun $run): string {
		$savedTransaction = $this->saveObject(schema: 'GLTransaction', data: $this->correctionTransaction(run: $run));
		$transactionId    = (string)($savedTransaction['id'] ?? ($savedTransaction['@self']['id'] ?? ''));
		if ($transactionId === '') {
			throw new RuntimeException('GLTransaction save did not return an identifier.');
		}

		return $transactionId;

	}//end saveCorrectionTransaction()

	/**
	 * The draft GLTransaction header, posted today.
	 *
	 * @param VatCorrectionPostingRun $run The posting run.
	 *
	 * @return array<string,mixed>
	 */
	private function correctionTransaction(VatCorrectionPostingRun $run): array {
		$postingDate = (new DateTimeImmutable())->format('Y-m-d');

		return [
			'transactionNumber' => sprintf('GLCORR-%s', substr($run->vatCorrectionId, 0, 12)),
			'postingDate' => $postingDate,
			'periodId' => $postingDate,
			'currency' => 'EUR',
			'description' => sprintf('BTW-suppletie correctie voor VatCorrection %s', $run->vatCorrectionId),
			'sourceReference' => $run->vatCorrectionId,
			'state' => 'draft',
			'administrationId' => $run->administrationId,
		];

	}//end correctionTransaction()

	/**
	 * Book one non-zero delta on its own account, else the clearing account.
	 *
	 * @param VatCorrectionPostingRun $run   The posting run.
	 * @param array<string,mixed>     $delta A delta with glAccountNumber attached.
	 *
	 * @return void
	 */
	private function postDeltaLine(VatCorrectionPostingRun $run, array $delta): void {
		$amountCt = (int)round(abs((float)$delta['deltaVATAmount']) * 100);
		if ($amountCt <= 0) {
			return;
		}

		$side = $this->correctionSide(delta: $delta);
		$this->saveCorrectionLine(
			run: $run,
			line: [
				'accountNumber' => $this->deltaAccount(run: $run, delta: $delta),
				'side' => $side,
				'amount' => ($amountCt / 100),
				'description' => $this->deltaLabel(delta: $delta),
			]
		);
		$run->addToSide(side: $side, amountCt: $amountCt);

	}//end postDeltaLine()

	/**
	 * The side a delta books to: an owed (output tax) increase credits the
	 * liability; a deductible increase debits the asset.
	 *
	 * @param array<string,mixed> $delta A delta from computeDeltas().
	 *
	 * @return string 'debit' or 'credit'.
	 */
	private function correctionSide(array $delta): string {
		$increases = (((float)$delta['deltaVATAmount']) > 0);
		if ($this->isOwed(delta: $delta) === $increases) {
			return 'credit';
		}

		return 'debit';

	}//end correctionSide()

	/**
	 * The account a delta books to: its resolved account, else the clearing account.
	 *
	 * @param VatCorrectionPostingRun $run   The posting run.
	 * @param array<string,mixed>     $delta A delta with glAccountNumber attached.
	 *
	 * @return string
	 */
	private function deltaAccount(VatCorrectionPostingRun $run, array $delta): string {
		if ((string)($delta['glAccountNumber'] ?? '') !== '') {
			return (string)$delta['glAccountNumber'];
		}

		return $run->clearingAccount;

	}//end deltaAccount()

	/**
	 * Book the clearing line that balances the delta lines, when they do not balance.
	 *
	 * @param VatCorrectionPostingRun $run The posting run.
	 *
	 * @return void
	 */
	private function postClearingLine(VatCorrectionPostingRun $run): void {
		$clearingCt = abs($run->totalDebitCt - $run->totalCreditCt);
		if ($clearingCt <= 0) {
			return;
		}

		$this->saveCorrectionLine(
			run: $run,
			line: [
				'accountNumber' => $run->clearingAccount,
				'side' => $this->clearingSide(run: $run),
				'amount' => ($clearingCt / 100),
				'description' => 'Suppletie clearing balans',
			]
		);

	}//end postClearingLine()

	/**
	 * The side of the clearing line: credit when the delta lines debit more.
	 *
	 * @param VatCorrectionPostingRun $run The posting run.
	 *
	 * @return string 'debit' or 'credit'.
	 */
	private function clearingSide(VatCorrectionPostingRun $run): string {
		if ($run->totalDebitCt > $run->totalCreditCt) {
			return 'credit';
		}

		return 'debit';

	}//end clearingSide()

	/**
	 * Save the next GLLine of the correction posting.
	 *
	 * The administrationId is DENORMALISED onto every line from the same
	 * scope the header carries (REQ-GLS-001) — see
	 * GlLineAdministrationBackfillMigrator. A line written without it
	 * is invisible to its own administration's SpendAnalytics totals
	 * AND flips the backfill completeness gate red instance-wide.
	 *
	 * @param VatCorrectionPostingRun                                                 $run  The posting run.
	 * @param array{accountNumber:string,side:string,amount:float,description:string} $line The line's account, side, amount and description.
	 *
	 * @return void
	 */
	private function saveCorrectionLine(VatCorrectionPostingRun $run, array $line): void {
		$run->lineNumber++;
		$this->saveObject(
			schema: 'GLLine',
			data: [
				'transactionId' => $run->transactionId,
				'administrationId' => $run->administrationId,
				'lineNumber' => $run->lineNumber,
				'accountNumber' => $line['accountNumber'],
				'side' => $line['side'],
				'amount' => $line['amount'],
				'currency' => 'EUR',
				'description' => $line['description'],
			]
		);

	}//end saveCorrectionLine()

	/**
	 * The description of a correction posting line: its box, or its type and
	 * rate for a return filed before boxes.
	 *
	 * @param array<string,mixed> $delta A delta from computeDeltas().
	 *
	 * @return string
	 */
	private function deltaLabel(array $delta): string {
		$box = (string)($delta['returnBox'] ?? '');
		if ($box !== '') {
			return sprintf('Suppletie rubriek %s delta', $box);
		}

		return sprintf('Suppletie %s %.2f%% delta', $delta['type'], $delta['taxRate']);
	}//end deltaLabel()

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
		$correction = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister($this->register())->setSchema('VatCorrection'),
			id: $vatCorrectionId
		);

		if ($correction === null) {
			throw new RuntimeException(sprintf('VatCorrection %s not found', $vatCorrectionId));
		}

		return $correction->jsonSerialize();
	}//end fetchCorrection()

	/**
	 * A VatCorrection by id, or null when there is none, for a caller that
	 * has to check the correction's administration before acting on it.
	 *
	 * @param string $vatCorrectionId The VatCorrection id.
	 *
	 * @return array<string,mixed>|null The correction, or null when it does not exist.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
	 */
	public function findCorrection(string $vatCorrectionId): ?array {
		$correction = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister($this->register())->setSchema('VatCorrection'),
			id: $vatCorrectionId
		);

		if ($correction === null) {
			return null;
		}

		return $correction->jsonSerialize();
	}//end findCorrection()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
