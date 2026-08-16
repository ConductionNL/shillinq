<?php

/**
 * Lease Reassessment Service
 *
 * Captures every IFRS 16 modification and remeasurement event on a lease — CPI /
 * fixed-percent indexation, extension- and termination-option reassessment, scope
 * / term / payment modifications, IBR reset, impairment, abandonment, partial and
 * full termination (REQ-LR-001..REQ-LR-007). For each event the service:
 *
 *   1. Reads the LeaseContract via the real OpenRegister ObjectService
 *      (`find`/`findAll`), administration-scoped per ADR-005 IDOR safety.
 *   2. Builds before/after contract snapshots and computes the liability /
 *      RoU-asset delta using the pure-logic LeaseAmortizationCalculator
 *      (cents-only arithmetic, no IEEE-754 drift).
 *   3. Persists an immutable LeaseReassessmentEvent record via `saveObject`,
 *      stamped with the `sourceLease` FK (ADR-022 audit trail).
 *   4. Returns the event payload plus the balanced GL line shape that the
 *      generic bookkeeping-general-ledger surface will post (no parallel GL
 *      table — ADR-031).
 *
 * Material events (RoU adjustment > approval threshold, default EUR 100,000)
 * are returned with status `pending-approval` so the caller routes them through
 * decidesk (REQ-LR-007). Webhook delivery itself is deferred to Phase 2 — this
 * service produces the audit-grade event record the decidesk integration will
 * consume.
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
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Records and computes IFRS 16 lease-reassessment events.
 *
 * Each `record*Event` entry point takes a lease id + the event-specific inputs
 * and returns the persisted event payload (or null on out-of-scope / not-found,
 * with the failure recorded via the logger). The arithmetic is delegated to
 * LeaseAmortizationCalculator so the maths is unit-testable in isolation.
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.LongVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class LeaseReassessmentService {
	/**
	 * Approval-routing threshold (RoU asset delta in cents) above which an event
	 * is created with status `pending-approval` for decidesk routing.
	 *
	 * 100_000 EUR per REQ-LR-007. Stored in cents to stay in the integer space.
	 *
	 * @var int
	 */
	private const DECIDESK_THRESHOLD_CENTS = 10_000_000;

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * The decidesk webhook service is optional: when present and an event
	 * lands with status `pending-approval`, the service tries to deliver
	 * the approval webhook (Task 8.2). The webhook is allowed to fail
	 * soft — the persisted LeaseReassessmentEvent remains the source of
	 * truth for the audit trail.
	 *
	 *                                      lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 arithmetic helper.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param LeaseDecideskWebhookService|null $decideskWebhook Optional decidesk webhook delivery service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LeaseAmortizationCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?LeaseDecideskWebhookService $decideskWebhook = null,
	) {
	}//end __construct()

	/**
	 * Record an indexation (CPI / fixed-percent) remeasurement (REQ-LR-001).
	 *
	 * The base-payment-amount is updated to the indexed value, the post-event
	 * liability is the PV of the remaining unavoidable payments under the new
	 * stream, and the RoU asset is adjusted catch-up by the liability delta
	 * (IFRS 16.42).
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param float $newPaymentAmount The indexed payment per period (decimal money).
	 * @param string $triggerDescription Free-text business reason recorded on the event.
	 * @param string $approver Approver person id (organisations.person-id FK).
	 *
	 * @return array<string,mixed>|null The persisted event payload, or null when out of scope.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordIndexationEvent(
		string $leaseContractId,
		string $administrationId,
		float $newPaymentAmount,
		string $triggerDescription = '',
		string $approver = '',
	): ?array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return null;
		}

		$newLease = $lease;
		$newLease['basePaymentAmount'] = $newPaymentAmount;

		if ($triggerDescription !== '') {
			$resolvedTriggerDescription = $triggerDescription;
		} else {
			$resolvedTriggerDescription = 'Indexation clause triggered';
		}

		return $this->persistEvent(
			eventType: 'indexation-remeasurement',
			lease: $lease,
			newLease: $newLease,
			remeasurementApproach: 'catch-up-adjustment',
			triggerDescription: $resolvedTriggerDescription,
			approver: $approver,
			administrationId: $administrationId,
		);

	}//end recordIndexationEvent()

	/**
	 * Record an extension-option reassessment (REQ-LR-002).
	 *
	 * The new extension-options array carries updated exercise-likelihoods;
	 * scheduleLength then resolves the new term and the calculator produces the
	 * post-event liability. The schedule itself is regenerated from
	 * fromSequence by LeasePaymentScheduleService once the event is approved.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param array<int,array<string,mixed>> $updatedExtensionOptions The new extension-options array.
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordExtensionOptionReassessment(
		string $leaseContractId,
		string $administrationId,
		array $updatedExtensionOptions,
		string $triggerDescription = '',
		string $approver = '',
	): ?array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return null;
		}

		$newLease = $lease;
		$newLease['extensionOptions'] = $updatedExtensionOptions;

		if ($triggerDescription !== '') {
			$resolvedTriggerDescription = $triggerDescription;
		} else {
			$resolvedTriggerDescription = 'Extension-option likelihood revised';
		}

		return $this->persistEvent(
			eventType: 'extension-option-reassessment',
			lease: $lease,
			newLease: $newLease,
			remeasurementApproach: 'catch-up-adjustment',
			triggerDescription: $resolvedTriggerDescription,
			approver: $approver,
			administrationId: $administrationId,
		);

	}//end recordExtensionOptionReassessment()

	/**
	 * Record a scope / term / payment modification per IFRS 16.44 (REQ-LR-003).
	 *
	 * The caller supplies the new contract terms (any subset of the lease
	 * fields). The decision tree branch — separate-lease vs catch-up vs
	 * prospective — is selected by the caller via $approach; the default is
	 * `catch-up-adjustment`, matching the most common case where the same
	 * underlying asset's scope is modified mid-term.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $newTerms Field overrides to apply on the lease snapshot.
	 * @param string $approach catch-up-adjustment|prospective|separate-lease.
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordModification(
		string $leaseContractId,
		string $administrationId,
		array $newTerms,
		string $approach = 'catch-up-adjustment',
		string $triggerDescription = '',
		string $approver = '',
	): ?array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return null;
		}

		if (in_array($approach, ['catch-up-adjustment', 'prospective', 'separate-lease'], true) === false) {
			$approach = 'catch-up-adjustment';
		}

		$newLease = array_merge($lease, $newTerms);
		$eventType = $this->resolveModificationEventType(newTerms: $newTerms);

		if ($triggerDescription !== '') {
			$resolvedTriggerDescription = $triggerDescription;
		} else {
			$resolvedTriggerDescription = 'Lease terms modified';
		}

		return $this->persistEvent(
			eventType: $eventType,
			lease: $lease,
			newLease: $newLease,
			remeasurementApproach: $approach,
			triggerDescription: $resolvedTriggerDescription,
			approver: $approver,
			administrationId: $administrationId,
		);

	}//end recordModification()

	/**
	 * Record an impairment write-down on the RoU asset (REQ-LR-004).
	 *
	 * The recoverable value is the post-impairment carrying amount; the
	 * difference vs the pre-event RoU asset is the impairment loss, recognised
	 * in P&L via a `lease-modification-gain-loss` GL subtype.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param float $recoverableValue Post-impairment carrying amount (decimal money).
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordImpairment(
		string $leaseContractId,
		string $administrationId,
		float $recoverableValue,
		string $triggerDescription = '',
		string $approver = '',
	): ?array {
		$lease = $this->fetchLease(leaseContractId: $leaseContractId, administrationId: $administrationId);
		if ($lease === null) {
			return null;
		}

		// Impairment freezes the liability and writes down only the RoU asset.
		$opening = $this->calculator->openingBalances(lease: $lease);

		$preEventLiabilityCents = $this->calculator->toCents(amount: $opening['liability']);
		$preEventRouCents = $this->calculator->toCents(amount: $opening['rouAsset']);
		$postEventRouCents = $this->calculator->toCents(amount: $recoverableValue);
		$rouDeltaCents = ($postEventRouCents - $preEventRouCents);
		$plImpactCents = -$rouDeltaCents;

		if ($triggerDescription !== '') {
			$resolvedTriggerDescription = $triggerDescription;
		} else {
			$resolvedTriggerDescription = 'Impairment write-down';
		}

		$eventPayload = [
			'eventType' => 'impairment',
			'remeasurementApproach' => 'catch-up-adjustment',
			'oldContractSnapshot' => $this->snapshotLease(lease: $lease),
			'newContractSnapshot' => $this->snapshotLease(lease: $lease),
			'preEventLeaseLiability' => $opening['liability'],
			'postEventLeaseLiability' => $opening['liability'],
			'rouAssetAdjustment' => $this->calculator->fromCents(cents: $rouDeltaCents),
			'plImpact' => $this->calculator->fromCents(cents: $plImpactCents),
			'triggerDescription' => $resolvedTriggerDescription,
			'approver' => $approver,
			'glLines' => $this->buildImpairmentGlLines(rouDeltaCents: $rouDeltaCents),
			'rouAdjustmentMagnitudeCents' => abs($rouDeltaCents),
		];

		return $this->save(
			lease: $lease,
			administrationId: $administrationId,
			payload: $eventPayload,
			preEventLiabilityCents: $preEventLiabilityCents,
		);

	}//end recordImpairment()

	/**
	 * Returns the approval threshold in cents (REQ-LR-007).
	 *
	 * @return int Approval threshold in cents.
	 */
	public function approvalThresholdCents(): int {
		return self::DECIDESK_THRESHOLD_CENTS;
	}//end approvalThresholdCents()

	/**
	 * Build, persist and return an event payload for indexation / extension /
	 * modification flows (REQ-LR-001..REQ-LR-003).
	 *
	 * The pre-event liability is computed off the original lease snapshot and
	 * the post-event off the merged snapshot; the RoU adjustment equals the
	 * liability delta under catch-up-adjustment (IFRS 16.42, 16.45). Prospective
	 * approach freezes the existing carrying amount and only updates the future
	 * schedule (no RoU adjustment recorded here).
	 *
	 * @param string $eventType One of the REQ-LR-001 enum values.
	 * @param array<string,mixed> $lease The pre-event lease snapshot.
	 * @param array<string,mixed> $newLease The post-event lease snapshot.
	 * @param string $remeasurementApproach catch-up-adjustment|prospective|separate-lease.
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,mixed> The persisted event payload.
	 */
	private function persistEvent(
		string $eventType,
		array $lease,
		array $newLease,
		string $remeasurementApproach,
		string $triggerDescription,
		string $approver,
		string $administrationId,
	): array {
		$preOpening = $this->calculator->openingBalances(lease: $lease);
		$postOpening = $this->calculator->openingBalances(lease: $newLease);

		$preEventLiabilityCents = $this->calculator->toCents(amount: $preOpening['liability']);
		$postEventLiabilityCents = $this->calculator->toCents(amount: $postOpening['liability']);
		$liabilityDeltaCents = ($postEventLiabilityCents - $preEventLiabilityCents);

		// Catch-up: RoU mirrors the liability delta (no gain/loss).
		// Prospective: RoU unchanged, the delta is absorbed by future periods.
		// Separate-lease: this event records the split; the new lease is created by the caller.
		if ($remeasurementApproach === 'catch-up-adjustment') {
			$rouDeltaCents = $liabilityDeltaCents;
		} else {
			$rouDeltaCents = 0;
		}

		$payload = [
			'eventType' => $eventType,
			'remeasurementApproach' => $remeasurementApproach,
			'oldContractSnapshot' => $this->snapshotLease(lease: $lease),
			'newContractSnapshot' => $this->snapshotLease(lease: $newLease),
			'preEventLeaseLiability' => $preOpening['liability'],
			'postEventLeaseLiability' => $postOpening['liability'],
			'rouAssetAdjustment' => $this->calculator->fromCents(cents: $rouDeltaCents),
			'plImpact' => 0.0,
			'triggerDescription' => $triggerDescription,
			'approver' => $approver,
			'glLines' => $this->buildGlLines(
				liabilityDeltaCents: $liabilityDeltaCents,
				rouDeltaCents: $rouDeltaCents,
			),
			'rouAdjustmentMagnitudeCents' => abs($rouDeltaCents),
		];

		return $this->save(
			lease: $lease,
			administrationId: $administrationId,
			payload: $payload,
			preEventLiabilityCents: $preEventLiabilityCents,
		);

	}//end persistEvent()

	/**
	 * Persist the event payload to OpenRegister and return the saved row.
	 *
	 * Status is `pending-approval` when the RoU magnitude exceeds the decidesk
	 * threshold, otherwise `approved` (REQ-LR-007). The `sourceLease` FK stamps
	 * the audit trail (ADR-022).
	 *
	 * @param array<string,mixed> $lease The pre-event lease snapshot.
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload The event payload from persistEvent / impairment.
	 * @param int $preEventLiabilityCents Pre-event liability in cents (for event numbering only).
	 *
	 * @return array<string,mixed> The persisted event payload (saved row + status).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $preEventLiabilityCents
	 *     is documented above as used "for event numbering only" but is not
	 *     read in this method body. Flagged during issue #506 as a possible
	 *     gap between docblock and implementation, not confirmed as
	 *     intentional. Left unchanged here (style/quality pass only).
	 */
	private function save(
		array $lease,
		string $administrationId,
		array $payload,
		int $preEventLiabilityCents,
	): array {
		$sourceLease = $this->resolveSourceLease(lease: $lease);
		if ($payload['rouAdjustmentMagnitudeCents'] > self::DECIDESK_THRESHOLD_CENTS) {
			$status = 'pending-approval';
		} else {
			$status = 'approved';
		}

		unset($payload['rouAdjustmentMagnitudeCents']);

		$row = array_merge(
			$payload,
			[
				'reassessmentNumber' => $this->nextReassessmentNumber(lease: $lease, administrationId: $administrationId),
				'eventDate' => $this->today(),
				'sourceLease' => $sourceLease,
				'lease' => $sourceLease,
				'administrationId' => $administrationId,
				'status' => $status,
				'postedToGl' => null,
				'approvalDate' => null,
			]
		);

		try {
			$saved = $this->objectService()->saveObject(
				object: $row,
				register: $this->register(),
				schema: 'LeaseReassessmentEvent',
			);
			if (is_array($saved) === true) {
				// Task 8.2: fire decidesk approval webhook for material events.
				// Fail-soft per ADR-005 — the persisted event is the audit record.
				$this->fireDecideskWebhook(event: $saved);
				return $saved;
			}
		} catch (\Throwable $e) {
			// Fail soft: persistence failure is logged with the lease id; the
			// computed payload is returned so the caller still has the audit
			// record to retry / surface to the operator (ADR-005, no stack
			// traces to the client).
			$this->logger->warning(
				'LeaseReassessmentService: failed to persist reassessment event',
				[
					'leaseContractId' => $sourceLease,
					'eventType' => ($row['eventType'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return $row;
	}//end save()

	/**
	 * Resolve the modification event-type from the field deltas.
	 *
	 * Payment-modification when only basePaymentAmount changes; term-modification
	 * when nonCancellableTermMonths changes; scope-modification otherwise (the
	 * IFRS 16.44 default for any other field change).
	 *
	 * @param array<string,mixed> $newTerms The field overrides.
	 *
	 * @return string The resolved event-type enum value.
	 */
	private function resolveModificationEventType(array $newTerms): string {
		$keys = array_keys($newTerms);

		if ($keys === ['basePaymentAmount']) {
			return 'payment-modification';
		}

		if (in_array('nonCancellableTermMonths', $keys, true) === true) {
			return 'term-modification';
		}

		if (in_array('ibrPercent', $keys, true) === true && count($keys) === 1) {
			return 'IBR-reset';
		}

		return 'scope-modification';
	}//end resolveModificationEventType()

	/**
	 * Build the GL-line shape for a catch-up reassessment (REQ-LR-003).
	 *
	 * Liability-increase (delta > 0): Cr. lease-liability-noncurrent, Dr. RoU.
	 * Liability-decrease (delta < 0): Dr. lease-liability-noncurrent, Cr. RoU.
	 * Prospective: no lines (the future schedule absorbs the change).
	 *
	 * @param int $liabilityDeltaCents Post − pre liability, in cents.
	 * @param int $rouDeltaCents Post − pre RoU asset, in cents.
	 *
	 * @return array<int,array<string,mixed>> Balanced GL lines (may be empty).
	 */
	private function buildGlLines(int $liabilityDeltaCents, int $rouDeltaCents): array {
		if ($rouDeltaCents === 0) {
			return [];
		}

		$rouAmount = $this->calculator->fromCents(cents: abs($rouDeltaCents));
		$liabilityAmount = $this->calculator->fromCents(cents: abs($liabilityDeltaCents));

		if ($rouDeltaCents > 0) {
			return [
				[
					'side' => 'debit',
					'amount' => $rouAmount,
					'leaseAccountSubtype' => 'rou-asset',
					'narrative' => 'IFRS 16 RoU adjustment on reassessment',
				],
				[
					'side' => 'credit',
					'amount' => $liabilityAmount,
					'leaseAccountSubtype' => 'lease-liability-noncurrent',
					'narrative' => 'IFRS 16 lease liability adjustment on reassessment',
				],
			];
		}

		return [
			[
				'side' => 'debit',
				'amount' => $liabilityAmount,
				'leaseAccountSubtype' => 'lease-liability-noncurrent',
				'narrative' => 'IFRS 16 lease liability reduction on reassessment',
			],
			[
				'side' => 'credit',
				'amount' => $rouAmount,
				'leaseAccountSubtype' => 'rou-asset',
				'narrative' => 'IFRS 16 RoU reduction on reassessment',
			],
		];

	}//end buildGlLines()

	/**
	 * Build the GL-line shape for an impairment (REQ-LR-004).
	 *
	 * Impairment loss debits a P&L `lease-modification-gain-loss` subtype and
	 * credits the RoU asset by the same magnitude. A recovery (positive delta)
	 * reverses the entry.
	 *
	 * @param int $rouDeltaCents Post − pre RoU asset, in cents (negative for impairment).
	 *
	 * @return array<int,array<string,mixed>> Balanced GL lines (empty when delta is zero).
	 */
	private function buildImpairmentGlLines(int $rouDeltaCents): array {
		if ($rouDeltaCents === 0) {
			return [];
		}

		$amount = $this->calculator->fromCents(cents: abs($rouDeltaCents));

		if ($rouDeltaCents < 0) {
			return [
				[
					'side' => 'debit',
					'amount' => $amount,
					'leaseAccountSubtype' => 'lease-modification-gain-loss',
					'narrative' => 'IFRS 16 impairment loss on RoU asset',
				],
				[
					'side' => 'credit',
					'amount' => $amount,
					'leaseAccountSubtype' => 'rou-asset',
					'narrative' => 'IFRS 16 RoU write-down for impairment',
				],
			];
		}

		return [
			[
				'side' => 'debit',
				'amount' => $amount,
				'leaseAccountSubtype' => 'rou-asset',
				'narrative' => 'IFRS 16 RoU write-up on impairment reversal',
			],
			[
				'side' => 'credit',
				'amount' => $amount,
				'leaseAccountSubtype' => 'lease-modification-gain-loss',
				'narrative' => 'IFRS 16 impairment reversal gain',
			],
		];

	}//end buildImpairmentGlLines()

	/**
	 * Capture an immutable snapshot of the lease fields relevant to a reassessment.
	 *
	 * Includes the economic fields the calculator depends on plus the lease
	 * identifiers; clients and auditors compare old vs new snapshots to confirm
	 * which fields changed (REQ-LR-006).
	 *
	 * @param array<string,mixed> $lease The LeaseContract array.
	 *
	 * @return array<string,mixed> The snapshot.
	 */
	private function snapshotLease(array $lease): array {
		$keys = [
			'leaseNumber',
			'lessor',
			'assetClass',
			'classification',
			'commencementDate',
			'endDate',
			'nonCancellableTermMonths',
			'paymentFrequency',
			'paymentTiming',
			'basePaymentAmount',
			'paymentCurrency',
			'ibrPercent',
			'extensionOptions',
			'terminationOptions',
			'restorationObligation',
			'initialDirectCosts',
			'leaseIncentivesReceived',
			'prepaidRentBalance',
			'accruedRentBalance',
		];

		$snapshot = [];
		foreach ($keys as $key) {
			if (array_key_exists($key, $lease) === true) {
				$snapshot[$key] = $lease[$key];
			}
		}

		return $snapshot;
	}//end snapshotLease()

	/**
	 * Resolve the source-lease FK to stamp on the event (ADR-022 audit trail).
	 *
	 * Prefers @self.slug, then @self.id, then the lease's own slug / id fields.
	 *
	 * @param array<string,mixed> $lease The LeaseContract array.
	 *
	 * @return string The source-lease identifier.
	 */
	private function resolveSourceLease(array $lease): string {
		$self = ($lease['@self'] ?? null);
		if (is_array($self) === true) {
			if (isset($self['slug']) === true && (string)$self['slug'] !== '') {
				return (string)$self['slug'];
			}

			if (isset($self['id']) === true && (string)$self['id'] !== '') {
				return (string)$self['id'];
			}
		}

		if (isset($lease['leaseNumber']) === true && (string)$lease['leaseNumber'] !== '') {
			return (string)$lease['leaseNumber'];
		}

		return (string)($lease['id'] ?? '');
	}//end resolveSourceLease()

	/**
	 * Build the next sequential reassessment-number for a lease (REQ-LR-001).
	 *
	 * Counts prior events on the same lease (administration-scoped) and emits
	 * `<leaseNumber>-reassess-NNN` (zero-padded). Falls back to `-reassess-001`
	 * when the count read fails — the underlying OR layer guarantees uniqueness
	 * via id even if two events race the count.
	 *
	 * @param array<string,mixed> $lease The LeaseContract array.
	 * @param string $administrationId Administration scope.
	 *
	 * @return string The reassessment-number.
	 */
	private function nextReassessmentNumber(array $lease, string $administrationId): string {
		$leaseNumber = (string)($lease['leaseNumber'] ?? $this->resolveSourceLease(lease: $lease));
		$sourceLease = $this->resolveSourceLease(lease: $lease);

		try {
			$existing = $this->objectService()
				->setRegister($this->register())
				->setSchema('LeaseReassessmentEvent')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'sourceLease' => $sourceLease,
						],
					]
				);
			if (is_array($existing) === true) {
				$count = count($existing);
			} else {
				$count = 0;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'LeaseReassessmentService: failed to count prior events',
				['sourceLease' => $sourceLease, 'exception' => $e->getMessage()]
			);
			$count = 0;
		}//end try

		return sprintf('%s-reassess-%03d', $leaseNumber, ($count + 1));
	}//end nextReassessmentNumber()

	/**
	 * Fetch a lease and verify it belongs to the given administration (ADR-005).
	 *
	 * Mirrors LeasePaymentScheduleService::fetchLease so behaviour is consistent
	 * across the lease services.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,mixed>|null The lease, or null when not found / out of scope.
	 */
	private function fetchLease(string $leaseContractId, string $administrationId): ?array {
		try {
			$matches = $this->objectService()
				->setRegister($this->register())
				->setSchema('LeaseContract')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'LeaseReassessmentService: failed to read leases',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		foreach ($matches as $lease) {
			if (is_array($lease) === false) {
				continue;
			}

			$id = (string)($lease['id'] ?? ($lease['@self']['id'] ?? ''));
			$slug = (string)($lease['@self']['slug'] ?? '');
			if ($id === $leaseContractId || $slug === $leaseContractId) {
				return $lease;
			}
		}

		return null;
	}//end fetchLease()

	/**
	 * Today's date in YYYY-MM-DD form, used for event-date stamping.
	 *
	 * @return string Today's date.
	 */
	private function today(): string {
		return date('Y-m-d');
	}//end today()

	/**
	 * Resolve OpenRegister's ObjectService lazily.
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Best-effort decidesk webhook delivery for material reassessment events.
	 *
	 * Wired by the optional LeaseDecideskWebhookService injection: if the
	 * service is configured AND the persisted event has status
	 * `pending-approval`, fire the approval webhook. Any error inside the
	 * webhook layer is already swallowed and logged by the webhook
	 * service — this method must never raise to the caller because the
	 * persisted event is the source of truth (Task 8.2).
	 *
	 * @param array<string,mixed> $event The persisted LeaseReassessmentEvent row.
	 *
	 * @return void
	 */
	private function fireDecideskWebhook(array $event): void {
		if ($this->decideskWebhook === null) {
			return;
		}

		try {
			$this->decideskWebhook->deliver(event: $event);
		} catch (\Throwable $e) {
			// Defensive guard. Webhook service swallows its own errors.
			$this->logger->debug(
				'LeaseReassessmentService: decidesk webhook helper raised — suppressed',
				['message' => $e->getMessage()]
			);
		}

	}//end fireDecideskWebhook()
}//end class
