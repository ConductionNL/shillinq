<?php

/**
 * Retainer Billing Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the retainer-billing-engine
 * registers (RetainerPool, RetainerDrawdown, RetainerTrueUp). The bulk of the
 * retainer model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-aggregations). A small set of preconditions require
 * cross-record / cross-field lookups that OpenRegister's declarative `requires:`
 * clause cannot yet express; those are referenced from the schema lifecycle
 * transitions and implemented here:
 *
 *  - canActivatePool():       a RetainerPool may only activate when no other
 *                             active/draft pool overlaps its effective period for
 *                             the same (clientId, projectId) pair (REQ-RETN-001).
 *  - canMaterializeDrawdown(): a drawdown may only materialize when its
 *                             drawdownAmount equals hoursOrAmount × the pool's
 *                             configured retainerRate — the immutability control
 *                             that keeps pool consumption separate from the
 *                             timesheet rate (REQ-RETN-002, design D2).
 *  - canApproveTrueUp():      a RetainerTrueUp may only be approved when an
 *                             approver identity is recorded (REQ-RETN-011). The
 *                             retainer:approve-true-up permission is enforced at
 *                             the controller layer; this guard fails closed on a
 *                             missing approver.
 *
 * ADR-031 exception reason: non-overlapping-period detection and the drawdown
 * rate-immutability cross-lookup are not yet expressible in the declarative
 * lifecycle DSL. When the engine gains those capabilities, replace these
 * references with declarative conditions and delete this file. ADR-022: object
 * reads use the real OpenRegister ObjectService API (setRegister/setSchema/
 * findAll) only — never findObject/createFromArray/deleteFromId.
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
 * @spec openspec/specs/retainer-billing-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the retainer-billing-engine registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (RetainerPool.activate, RetainerDrawdown.materialize, RetainerTrueUp.approve)
 * as OCA\Shillinq\Lifecycle\RetainerGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/retainer-billing-management/spec.md
 */
class RetainerGuard {
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
	 * Returns true iff the RetainerPool may be activated.
	 *
	 * REQ-RETN-001: no other active/draft pool may overlap this pool's effective
	 * period for the same (clientId, projectId) pair. Overlapping periods are
	 * rejected so historical retainer tracking stays unambiguous.
	 *
	 * @param string $poolId The RetainerPool id (call-signature parity).
	 * @param array<string,mixed>|null $object The pool being transitioned.
	 *
	 * @return bool True when the pool may be activated.
	 *
	 * @spec openspec/specs/retainer-billing-management/spec.md
	 */
	public function canActivatePool(string $poolId, ?array $object = null): bool {
		try {
			$pool = $this->resolveObject(schema: 'RetainerPool', id: $poolId, object: $object);
			if ($pool === null) {
				return false;
			}

			$start = trim((string)($pool['periodStart'] ?? ''));
			$end = trim((string)($pool['periodEnd'] ?? ''));
			if ($start === '' || $end === '' || $start > $end) {
				return false;
			}

			$clientId = (string)($pool['clientId'] ?? '');
			if ($clientId === '') {
				return false;
			}

			$projectId = (string)($pool['projectId'] ?? '');
			$selfId = (string)($pool['poolId'] ?? $poolId);

			$candidates = $this->findPoolsForClient(clientId: $clientId);
			foreach ($candidates as $candidate) {
				if ($this->poolConflicts(
					candidate: $candidate,
					selfId: $selfId,
					projectId: $projectId,
					start: $start,
					end: $end
				) === true
				) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RetainerGuard: pool activation check failed — denying transition (fail-closed)',
				['poolId' => $poolId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivatePool()

	/**
	 * Returns true iff the RetainerDrawdown may be materialized.
	 *
	 * REQ-RETN-002 / design D2: drawdownAmount MUST equal hoursOrAmount × the
	 * pool's configured retainerRate (not the timesheet rate). The drawdownRate
	 * recorded on the drawdown must also match the pool rate so historical
	 * drawdowns are immutable against later RateCard changes.
	 *
	 * @param string $drawdownId The RetainerDrawdown id (call-signature parity).
	 * @param array<string,mixed>|null $object The drawdown being transitioned.
	 *
	 * @return bool True when the drawdown may be materialized.
	 *
	 * @spec openspec/specs/retainer-billing-management/spec.md
	 */
	public function canMaterializeDrawdown(string $drawdownId, ?array $object = null): bool {
		try {
			$drawdown = $this->resolveObject(schema: 'RetainerDrawdown', id: $drawdownId, object: $object);
			if ($drawdown === null) {
				return false;
			}

			$poolId = (string)($drawdown['poolId'] ?? '');
			if ($poolId === '' || $this->drawdownAmountIsConsistent(drawdown: $drawdown) === false) {
				return false;
			}

			// The recorded drawdownRate must match the pool's configured retainerRate
			// (rate immutability — design D2). When the pool cannot be resolved
			// (cross-app), accept the recorded rate as authoritative.
			$pool = $this->resolveObject(schema: 'RetainerPool', id: $poolId, object: null);
			if ($pool !== null && array_key_exists('retainerRate', $pool) === true) {
				return $this->amountsEqual(left: (float)$drawdown['drawdownRate'], right: (float)$pool['retainerRate']);
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RetainerGuard: drawdown materialize check failed — denying transition (fail-closed)',
				['drawdownId' => $drawdownId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canMaterializeDrawdown()

	/**
	 * Returns true iff a drawdown carries non-negative hours/rate and its
	 * drawdownAmount equals hoursOrAmount × drawdownRate (REQ-RETN-002).
	 *
	 * @param array<string,mixed> $drawdown The drawdown to validate.
	 *
	 * @return bool True when the drawdown amount is internally consistent.
	 */
	private function drawdownAmountIsConsistent(array $drawdown): bool {
		if (array_key_exists('hoursOrAmount', $drawdown) === false
			|| array_key_exists('drawdownRate', $drawdown) === false
			|| array_key_exists('drawdownAmount', $drawdown) === false
		) {
			return false;
		}

		$hours = (float)$drawdown['hoursOrAmount'];
		$rate = (float)$drawdown['drawdownRate'];
		$amount = (float)$drawdown['drawdownAmount'];
		if ($hours < 0.0 || $rate < 0.0) {
			return false;
		}

		return $this->amountsEqual(left: $amount, right: ($hours * $rate));
	}//end drawdownAmountIsConsistent()

	/**
	 * Returns true iff the RetainerTrueUp may be approved.
	 *
	 * REQ-RETN-011: an approver identity must be recorded before a true-up moves
	 * to approved. The retainer:approve-true-up permission is enforced at the
	 * controller layer; this guard fails closed when no approver is present.
	 *
	 * @param string $trueUpId The RetainerTrueUp id (call-signature parity).
	 * @param array<string,mixed>|null $object The true-up being transitioned.
	 *
	 * @return bool True when the true-up may be approved.
	 *
	 * @spec openspec/specs/retainer-billing-management/spec.md
	 */
	public function canApproveTrueUp(string $trueUpId, ?array $object = null): bool {
		try {
			$trueUp = $this->resolveObject(schema: 'RetainerTrueUp', id: $trueUpId, object: $object);
			if ($trueUp === null) {
				return false;
			}

			return trim((string)($trueUp['approvedBy'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'RetainerGuard: true-up approve check failed — denying transition (fail-closed)',
				['trueUpId' => $trueUpId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveTrueUp()

	/**
	 * Returns true iff a candidate pool conflicts with the activating pool: same
	 * (clientId already filtered) and projectId, a non-archived/non-inactive
	 * status, a different identity, and an overlapping period.
	 *
	 * @param mixed $candidate The candidate pool row.
	 * @param string $selfId The activating pool's poolId.
	 * @param string $projectId The activating pool's projectId.
	 * @param string $start The activating pool's periodStart.
	 * @param string $end The activating pool's periodEnd.
	 *
	 * @return bool True when the candidate conflicts.
	 */
	private function poolConflicts(mixed $candidate, string $selfId, string $projectId, string $start, string $end): bool {
		if ($this->isEligibleSibling(candidate: $candidate, selfId: $selfId, projectId: $projectId) === false) {
			return false;
		}

		$candidateStart = trim((string)($candidate['periodStart'] ?? ''));
		$candidateEnd = trim((string)($candidate['periodEnd'] ?? ''));
		if ($candidateStart === '' || $candidateEnd === '') {
			return false;
		}

		// Inclusive-period overlap: start <= candidateEnd AND candidateStart <= end.
		return $start <= $candidateEnd && $candidateStart <= $end;
	}//end poolConflicts()

	/**
	 * Returns true iff the candidate is an eligible sibling that could conflict:
	 * a real array, a different non-empty poolId, an active/draft status, and the
	 * same projectId scope (REQ-RETN-001).
	 *
	 * @param mixed $candidate The candidate pool row.
	 * @param string $selfId The activating pool's poolId.
	 * @param string $projectId The activating pool's projectId.
	 *
	 * @return bool True when the candidate is an eligible sibling.
	 */
	private function isEligibleSibling(mixed $candidate, string $selfId, string $projectId): bool {
		if (is_array($candidate) === false) {
			return false;
		}

		$candidateId = (string)($candidate['poolId'] ?? '');
		if ($candidateId === '' || $candidateId === $selfId) {
			return false;
		}

		$status = (string)($candidate['status'] ?? '');
		if ($status === 'inactive' || $status === 'archived') {
			return false;
		}

		return (string)($candidate['projectId'] ?? '') === $projectId;
	}//end isEligibleSibling()

	/**
	 * Returns true iff two monetary/rate values are equal within a cent epsilon.
	 *
	 * @param float $left Left operand.
	 * @param float $right Right operand.
	 *
	 * @return bool True when the values are equal within 0.005.
	 */
	private function amountsEqual(float $left, float $right): bool {
		return abs($left - $right) < 0.005;
	}//end amountsEqual()

	/**
	 * Find all RetainerPool rows for a given client (ADR-022 real API).
	 *
	 * @param string $clientId The client entity reference to filter by.
	 *
	 * @return array<int,array<string,mixed>> The matching pool rows.
	 */
	private function findPoolsForClient(string $clientId): array {
		$register = $this->resolveRegister();

		$results = $this->objectService
			->setRegister($register)
			->setSchema('RetainerPool')
			->findAll(['filters' => ['clientId' => $clientId]]);

		$pools = [];
		foreach ($results as $result) {
			if (is_array($result) === true) {
				$pools[] = $result;
			}
		}

		return $pools;
	}//end findPoolsForClient()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * 🔴 The fallback used to be `findAll(['filters' => ['id' => $id]])`, which
	 * matches ZERO rows against real OpenRegister for every value: `filters`
	 * addresses the object's JSON properties, and the entity's `id` is its own
	 * column. It returns an empty array rather than raising, so the caller
	 * reads a record that plainly exists as "not found".
	 *
	 * That made `canMaterializeDrawdown()` **FAIL OPEN**: its rate-immutability
	 * check is guarded by `if ($pool !== null …)`, the pool was never resolved,
	 * and the method returned true without ever comparing `drawdownRate` to the
	 * pool's `retainerRate` (REQ-RETN-002 / design D2). The narrow cross-app
	 * concession — "when the pool cannot be resolved, accept the recorded rate"
	 * — silently widened to 100% of calls.
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
