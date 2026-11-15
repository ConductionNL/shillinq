<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * IAS 37 / RJ 252 Provision Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the IAS 37 / RJ 252 voorzieningen
 * registers (bookkeeping-voorzieningen-claims, T3). The bulk of the recognition,
 * measurement and roll-forward model is declarative (schema metadata +
 * x-openregister-lifecycle + x-openregister-aggregations +
 * x-openregister-calculations). A small set of lifecycle preconditions require
 * cross-field / cross-schema completeness checks that OpenRegister's declarative
 * `requires:` clause cannot yet express; those are referenced from the schema
 * lifecycle transitions and implemented here:
 *
 *  - canActivateProvision(): a Provision may only go `active` once the IAS 37 §35
 *                            three-criteria toets is satisfied (legal/constructive
 *                            obligation + obligating event + probabilityOfOutflow
 *                            > 0.5 + bestEstimate + bestEstimateRationale)
 *                            (REQ-PROV-001, REQ-PROV-007); when material
 *                            (> EUR 100K OR > 1% of priorYearBalanceTotal) the
 *                            peerReviewer + peerReviewDate + cfoApprover +
 *                            cfoApprovalDate sign-offs MUST be populated
 *                            (REQ-PROV-010, REQ-PROV-018); when
 *                            provisionType=herstructurering the linked detail's
 *                            detailedPlanDate MUST be on or before its balanceDate
 *                            and planCommunicatedTo MUST be non-empty
 *                            (REQ-PROV-005); when provisionType=claims the linked
 *                            detail's legalAdviceMemo FK MUST be populated
 *                            (REQ-PROV-006); when discontering applies
 *                            (expectedTiming.longTerm > 0 or > 1-year horizon
 *                            material) discountRateApplied MUST be populated
 *                            (REQ-PROV-003).
 *  - canCloseMovement():     a ProvisionMovement may only transition `open` →
 *                            `closed` once the period, provision FK and
 *                            closingBalance are filled and at least one
 *                            linkedJournalEntries reference is present, so the
 *                            audit trail to the GL is closed (REQ-PROV-004,
 *                            REQ-PROV-016).
 *
 * ADR-031 exception reason: cross-field completeness, type-conditional + amount-
 * threshold checks are not yet expressible in the declarative lifecycle DSL.
 * When the engine gains those capabilities, replace these references with
 * declarative conditions and delete this file. ADR-022: object reads use the real
 * OpenRegister ObjectService API (setRegister/setSchema/findAll) only.
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
 * @spec openspec/specs/bookkeeping-voorzieningen-claims/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the IAS 37 / RJ 252 voorzieningen registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (Provision.activate, ProvisionMovement.close) as
 * OCA\Shillinq\Lifecycle\ProvisionGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/bookkeeping-voorzieningen-claims/spec.md
 */
class ProvisionGuard {

	/**
	 * Materiality threshold in EUR triggering peer review + CFO sign-off
	 * (REQ-PROV-018).
	 *
	 * @var float
	 */
	public const MATERIALITY_ABSOLUTE_EUR = 100000.0;

	/**
	 * Materiality ratio of priorYearBalanceTotal triggering peer review + CFO
	 * sign-off (REQ-PROV-018).
	 *
	 * @var float
	 */
	public const MATERIALITY_BALANCE_RATIO = 0.01;

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
	 * Returns true iff the Provision may leave `draft` and become `active`.
	 *
	 * Enforces:
	 *   - REQ-PROV-001 / REQ-PROV-007: three-criteria toets — legal/constructive
	 *     obligation enum, non-empty obligatingEvent, probabilityOfOutflow > 0.5,
	 *     non-zero bestEstimate, non-empty bestEstimateRationale.
	 *   - REQ-PROV-003: when expectedTiming.longTerm > 0 (material future
	 *     horizon), discountRateApplied MUST be populated.
	 *   - REQ-PROV-005: when provisionType=herstructurering, the linked
	 *     HerstructureringsvoorzieningDetail's detailedPlanDate MUST be on or
	 *     before balanceDate and planCommunicatedTo MUST be non-empty.
	 *   - REQ-PROV-006: when provisionType=claims, the linked
	 *     ClaimsVoorzieningDetail's legalAdviceMemo FK MUST be populated.
	 *   - REQ-PROV-010 / REQ-PROV-018: when bestEstimate > EUR 100K OR >
	 *     1% of priorYearBalanceTotal, peerReviewer + peerReviewDate +
	 *     cfoApprover + cfoApprovalDate MUST all be populated.
	 *
	 * @param string $provisionId The Provision id (call-signature parity).
	 * @param array<string,mixed>|null $object The provision being transitioned.
	 *
	 * @return bool True when the provision may be activated.
	 *
	 * @spec openspec/specs/bookkeeping-voorzieningen-claims/spec.md
	 */
	public function canActivateProvision(string $provisionId, ?array $object = null): bool {
		try {
			$provision = $this->resolveObject(schema: 'Provision', id: $provisionId, object: $object);

			return $provision !== null && $this->isActivationReady(provision: $provision) === true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProvisionGuard: provision activation check failed — denying transition (fail-closed)',
				['provisionId' => $provisionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canActivateProvision()

	/**
	 * Returns true iff a ProvisionMovement may transition from `open` to `closed`.
	 *
	 * Enforces:
	 *   - REQ-PROV-004: provision FK, period, openingBalance and a numeric
	 *     closingBalance MUST be present.
	 *   - REQ-PROV-016: at least one linkedJournalEntries reference MUST be
	 *     present, so the audit trail to the GL is closed before the period
	 *     immutability lock engages (REQ-PROV-019).
	 *
	 * @param string $movementId The ProvisionMovement id (call-signature parity).
	 * @param array<string,mixed>|null $object The movement being transitioned.
	 *
	 * @return bool True when the movement may be closed.
	 *
	 * @spec openspec/specs/bookkeeping-voorzieningen-claims/spec.md
	 */
	public function canCloseMovement(string $movementId, ?array $object = null): bool {
		try {
			$movement = $this->resolveObject(schema: 'ProvisionMovement', id: $movementId, object: $object);

			return $movement !== null && $this->isMovementClosable(movement: $movement) === true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProvisionGuard: movement close check failed — denying transition (fail-closed)',
				['movementId' => $movementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canCloseMovement()

	/**
	 * REQ-PROV-004 / REQ-PROV-016: provision FK, period, numeric opening and
	 * closing balances and at least one linked journal entry.
	 *
	 * @param array<string,mixed> $movement The ProvisionMovement payload.
	 *
	 * @return bool True when the movement is complete enough to close.
	 */
	private function isMovementClosable(array $movement): bool {
		return $this->isFilled(record: $movement, field: 'provision') === true
			&& $this->isFilled(record: $movement, field: 'period') === true
			&& is_numeric($movement['openingBalance'] ?? null) === true
			&& is_numeric($movement['closingBalance'] ?? null) === true
			&& count((array)($movement['linkedJournalEntries'] ?? [])) > 0;

	}//end isMovementClosable()

	/**
	 * Run the activation checks in order: recognition, discounting, linked
	 * detail, materiality sign-off. The first failing check stops the run.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when every activation check passes.
	 */
	private function isActivationReady(array $provision): bool {
		return $this->meetsRecognitionCriteria(provision: $provision) === true
			&& $this->hasDiscountRateWhenLongTerm(provision: $provision) === true
			&& $this->linkedDetailIsReady(provision: $provision) === true
			&& $this->hasMaterialitySignOffs(provision: $provision) === true;

	}//end isActivationReady()

	/**
	 * REQ-PROV-001 / REQ-PROV-007 three-criteria toets: legal/constructive
	 * obligation, obligating event, probability > 0.5, a positive best estimate
	 * and its rationale.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when the recognition criteria are met.
	 */
	private function meetsRecognitionCriteria(array $provision): bool {
		$obligation = (string)($provision['legalOrConstructiveObligation'] ?? '');

		return in_array($obligation, ['legal', 'constructive'], true) === true
			&& $this->isFilled(record: $provision, field: 'obligatingEvent') === true
			&& $this->isNumberAbove(value: $provision['probabilityOfOutflow'] ?? null, floor: 0.5) === true
			&& $this->isNumberAbove(value: $provision['bestEstimate'] ?? null, floor: 0.0) === true
			&& $this->isFilled(record: $provision, field: 'bestEstimateRationale') === true;

	}//end meetsRecognitionCriteria()

	/**
	 * REQ-PROV-003 disconteringsvoet: when long-term outflow is material,
	 * discountRateApplied MUST be a positive number.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when no discount rate is needed or one is present.
	 */
	private function hasDiscountRateWhenLongTerm(array $provision): bool {
		$timing = (array)($provision['expectedTiming'] ?? []);
		if ($this->isNumberAbove(value: $timing['longTerm'] ?? 0, floor: 0.0) === false) {
			return true;
		}

		return $this->isNumberAbove(value: $provision['discountRateApplied'] ?? null, floor: 0.0);

	}//end hasDiscountRateWhenLongTerm()

	/**
	 * REQ-PROV-005 / REQ-PROV-006: a restructuring or claims provision needs an
	 * activation-ready linked detail; other types need none.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when the linked detail (if any is required) is ready.
	 */
	private function linkedDetailIsReady(array $provision): bool {
		$provisionType = (string)($provision['provisionType'] ?? '');
		if ($provisionType === 'restructuring') {
			return $this->canActivateHerstructurering(provision: $provision);
		}

		if ($provisionType === 'claims') {
			return $this->canActivateClaims(provision: $provision);
		}

		return true;

	}//end linkedDetailIsReady()

	/**
	 * REQ-PROV-010 / REQ-PROV-018: a material provision needs peer review and
	 * CFO sign-off, each with its date.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when the provision is immaterial or fully signed off.
	 */
	private function hasMaterialitySignOffs(array $provision): bool {
		if ($this->isMaterial(provision: $provision) === false) {
			return true;
		}

		foreach (['peerReviewer', 'peerReviewDate', 'cfoApprover', 'cfoApprovalDate'] as $field) {
			if ($this->isFilled(record: $provision, field: $field) === false) {
				return false;
			}
		}

		return true;

	}//end hasMaterialitySignOffs()

	/**
	 * True when the field holds a non-blank string value.
	 *
	 * @param array<string,mixed> $record The record to read.
	 * @param string              $field  The field name.
	 *
	 * @return bool True when the trimmed value is not empty.
	 */
	private function isFilled(array $record, string $field): bool {
		return trim((string)($record[$field] ?? '')) !== '';

	}//end isFilled()

	/**
	 * True when the value is numeric and strictly greater than the floor.
	 *
	 * @param mixed $value The value to test.
	 * @param float $floor The exclusive lower bound.
	 *
	 * @return bool True when the value is a number above the floor.
	 */
	private function isNumberAbove(mixed $value, float $floor): bool {
		return is_numeric($value) === true && (float)$value > $floor;

	}//end isNumberAbove()

	/**
	 * Returns true when the herstructureringsvoorziening linked detail satisfies
	 * IAS 37 §72 timeliness (detailedPlanDate ≤ balanceDate) and §75 communication
	 * (planCommunicatedTo non-empty).
	 *
	 * @param array<string,mixed> $provision The parent Provision payload.
	 *
	 * @return bool True when the linked Herstructureringsvoorziening detail is
	 *              activation-ready.
	 */
	private function canActivateHerstructurering(array $provision): bool {
		$detail = $this->resolveObject(
			schema: 'HerstructureringsvoorzieningDetail',
			id: (string)($provision['linkedRestructuringProvisionDetail'] ?? ''),
			object: null
		);

		return $detail !== null && $this->planIsTimelyAndCommunicated(detail: $detail) === true;
	}//end canActivateHerstructurering()

	/**
	 * IAS 37 §72 / §75: the detailed plan is dated, dated on or before the
	 * balance date (when one is set), and communicated to at least one party.
	 *
	 * @param array<string,mixed> $detail The Herstructureringsvoorziening detail.
	 *
	 * @return bool True when the plan is timely and communicated.
	 */
	private function planIsTimelyAndCommunicated(array $detail): bool {
		$planDate = (string)($detail['detailedPlanDate'] ?? '');
		if ($planDate === '') {
			return false;
		}

		$communicated = (array)($detail['planCommunicatedTo'] ?? []);
		if (count($communicated) === 0) {
			return false;
		}

		// IAS 37 §72: the detailed plan must exist on or before the balance date.
		$balanceDate = (string)($detail['balanceDate'] ?? '');

		return $balanceDate === '' || strcmp($planDate, $balanceDate) <= 0;
	}//end planIsTimelyAndCommunicated()

	/**
	 * Returns true when the claims-voorziening linked detail carries the
	 * required legal advice memo FK (REQ-PROV-006).
	 *
	 * @param array<string,mixed> $provision The parent Provision payload.
	 *
	 * @return bool True when the linked Claims detail has a legalAdviceMemo FK.
	 */
	private function canActivateClaims(array $provision): bool {
		$detail = $this->resolveObject(
			schema: 'ClaimsVoorzieningDetail',
			id: (string)($provision['linkedClaimsProvisionDetail'] ?? ''),
			object: null
		);

		return $detail !== null && $this->isFilled(record: $detail, field: 'legalAdviceMemo') === true;
	}//end canActivateClaims()

	/**
	 * Materiality test per REQ-PROV-018: bestEstimate > EUR 100K OR > 1% of
	 * priorYearBalanceTotal.
	 *
	 * @param array<string,mixed> $provision The provision payload.
	 *
	 * @return bool True when the provision crosses the materiality threshold.
	 */
	private function isMaterial(array $provision): bool {
		$bestEstimate = $provision['bestEstimate'] ?? null;
		if (is_numeric($bestEstimate) === false) {
			return false;
		}

		$best = (float)$bestEstimate;
		if ($best > self::MATERIALITY_ABSOLUTE_EUR) {
			return true;
		}

		return $this->exceedsBalanceRatio(best: $best, balanceTotal: $provision['priorYearBalanceTotal'] ?? null);
	}//end isMaterial()

	/**
	 * True when the estimate is more than 1% of a positive prior-year balance
	 * total (REQ-PROV-018).
	 *
	 * @param float $best         The best estimate.
	 * @param mixed $balanceTotal The prior-year balance total.
	 *
	 * @return bool True when the ratio crosses the threshold.
	 */
	private function exceedsBalanceRatio(float $best, mixed $balanceTotal): bool {
		if ($this->isNumberAbove(value: $balanceTotal, floor: 0.0) === false) {
			return false;
		}

		return ($best / (float)$balanceTotal) > self::MATERIALITY_BALANCE_RATIO;
	}//end exceedsBalanceRatio()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * 🔴 The fallback used to be `findAll(['filters' => ['id' => $id]])`, which
	 * matches ZERO rows against real OpenRegister for every value: `filters`
	 * addresses the object's JSON properties, and the entity's `id` is its own
	 * column. It returns an empty array rather than raising, so a linked detail
	 * record that plainly exists reads as "not found".
	 *
	 * This guard is reached declaratively — `bookkeeping-voorzieningen-claims.json`
	 * names `ProvisionGuard::canActivateProvision` in the `requires:` clause of
	 * the Provision `activate` transition — so the effect was live: every
	 * `provisionType: restructuring` and every `provisionType: claims` provision
	 * was PERMANENTLY BLOCKED from activation, because
	 * `canActivateHerstructurering()`/`canActivateClaims()` fail closed on an
	 * unresolvable detail. Both IAS 37 §72/§75 and REQ-PROV-006 still apply —
	 * they can simply be evaluated now instead of being unreachable.
	 *
	 * @param string $schema The OpenRegister schema slug to query.
	 * @param string $id The object id to look up if no object given.
	 * @param array<string,mixed>|null $object The in-flight object, if provided by the engine.
	 *
	 * @return array<string,mixed>|null The resolved object, or null when unavailable.
	 */
	private function resolveObject(string $schema, string $id, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($id === '') {
			return null;
		}

		return ObjectIdentifier::findOne(
			scoped: $this->objectService
				->setRegister($this->resolveRegister())
				->setSchema($schema),
			id: $id
		);
	}//end resolveObject()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
