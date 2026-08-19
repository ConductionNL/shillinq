<?php

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

use OCA\Shillinq\AppInfo\Application;
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
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
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
			if ($provision === null) {
				return false;
			}

			// REQ-PROV-001 three-criteria toets: legal/constructive obligation classification.
			$obligation = (string)($provision['legalOrConstructiveObligation'] ?? '');
			if (in_array($obligation, ['legal', 'constructive'], true) === false) {
				return false;
			}

			// REQ-PROV-001 three-criteria toets: obligating event narrative.
			if (trim((string)($provision['obligatingEvent'] ?? '')) === '') {
				return false;
			}

			// REQ-PROV-001 / REQ-PROV-007 three-criteria toets: probability > 0.5.
			$probability = $provision['probabilityOfOutflow'] ?? null;
			if (is_numeric($probability) === false || (float)$probability <= 0.5) {
				return false;
			}

			// REQ-PROV-001 three-criteria toets: reliable estimate present.
			$bestEstimate = $provision['bestEstimate'] ?? null;
			if (is_numeric($bestEstimate) === false || (float)$bestEstimate <= 0.0) {
				return false;
			}

			// REQ-PROV-001 three-criteria toets: bestEstimate rationale narrative.
			if (trim((string)($provision['bestEstimateRationale'] ?? '')) === '') {
				return false;
			}

			// REQ-PROV-003 disconteringsvoet enforcement: when long-term outflow
			// is material, discountRateApplied MUST be populated.
			$timing = (array)($provision['expectedTiming'] ?? []);
			$longTerm = $timing['longTerm'] ?? 0;
			if (is_numeric($longTerm) === true && (float)$longTerm > 0.0) {
				$rate = $provision['discountRateApplied'] ?? null;
				if (is_numeric($rate) === false || (float)$rate <= 0.0) {
					return false;
				}
			}

			// REQ-PROV-005 herstructurering plan timeliness + communication.
			$provisionType = (string)($provision['provisionType'] ?? '');
			if ($provisionType === 'restructuring') {
				if ($this->canActivateHerstructurering(provision: $provision) === false) {
					return false;
				}
			}

			// REQ-PROV-006 claims-voorziening legal advice memo gate.
			if ($provisionType === 'claims') {
				if ($this->canActivateClaims(provision: $provision) === false) {
					return false;
				}
			}

			// REQ-PROV-010 / REQ-PROV-018 materiality peer-review + CFO sign-off.
			if ($this->isMaterial(provision: $provision) === true) {
				if (trim((string)($provision['peerReviewer'] ?? '')) === '') {
					return false;
				}

				if (trim((string)($provision['peerReviewDate'] ?? '')) === '') {
					return false;
				}

				if (trim((string)($provision['cfoApprover'] ?? '')) === '') {
					return false;
				}

				if (trim((string)($provision['cfoApprovalDate'] ?? '')) === '') {
					return false;
				}
			}

			return true;
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
			if ($movement === null) {
				return false;
			}

			if (trim((string)($movement['provision'] ?? '')) === '') {
				return false;
			}

			if (trim((string)($movement['period'] ?? '')) === '') {
				return false;
			}

			if (is_numeric($movement['openingBalance'] ?? null) === false) {
				return false;
			}

			if (is_numeric($movement['closingBalance'] ?? null) === false) {
				return false;
			}

			$entries = (array)($movement['linkedJournalEntries'] ?? []);
			if (count($entries) === 0) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProvisionGuard: movement close check failed — denying transition (fail-closed)',
				['movementId' => $movementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canCloseMovement()

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
		$detailId = (string)($provision['linkedRestructuringProvisionDetail'] ?? '');
		if ($detailId === '') {
			return false;
		}

		$detail = $this->resolveObject(
			schema: 'HerstructureringsvoorzieningDetail',
			id: $detailId,
			object: null
		);
		if ($detail === null) {
			return false;
		}

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
		if ($balanceDate !== '' && strcmp($planDate, $balanceDate) > 0) {
			return false;
		}

		return true;
	}//end canActivateHerstructurering()

	/**
	 * Returns true when the claims-voorziening linked detail carries the
	 * required legal advice memo FK (REQ-PROV-006).
	 *
	 * @param array<string,mixed> $provision The parent Provision payload.
	 *
	 * @return bool True when the linked Claims detail has a legalAdviceMemo FK.
	 */
	private function canActivateClaims(array $provision): bool {
		$detailId = (string)($provision['linkedClaimsProvisionDetail'] ?? '');
		if ($detailId === '') {
			return false;
		}

		$detail = $this->resolveObject(
			schema: 'ClaimsVoorzieningDetail',
			id: $detailId,
			object: null
		);
		if ($detail === null) {
			return false;
		}

		if (trim((string)($detail['legalAdviceMemo'] ?? '')) === '') {
			return false;
		}

		return true;
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

		$balanceTotal = $provision['priorYearBalanceTotal'] ?? null;
		if (is_numeric($balanceTotal) === true && (float)$balanceTotal > 0.0) {
			$ratio = $best / (float)$balanceTotal;
			if ($ratio > self::MATERIALITY_BALANCE_RATIO) {
				return true;
			}
		}

		return false;
	}//end isMaterial()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
