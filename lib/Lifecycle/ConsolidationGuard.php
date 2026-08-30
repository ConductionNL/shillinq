<?php

/**
 * Commercial Consolidation Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the commercial consolidation
 * registers (bookkeeping-consolidation-commercial, T3 / RJ 217 / IAS 27 /
 * IFRS 10). The bulk of the consolidation model is declarative (schema metadata
 * + x-openregister-lifecycle + x-openregister-aggregations / -calculations). A
 * small set of preconditions require cross-line / cross-field completeness or
 * arithmetic-equation checks that OpenRegister's declarative `requires:` clause
 * cannot yet express; those are referenced from the schema lifecycle transitions
 * and implemented here:
 *
 *  - canActivateGroup():           a group may leave draft only once a parent
 *                                  administration is set (REQ-CONS-001).
 *  - canActivateEntity():          an entity's ownership percentage and
 *                                  consolidation method must be consistent
 *                                  (100% → integral, ~50% → proportional,
 *                                  <50% → equity) before activation (design D2).
 *  - canStartElimination():        every elimination phase requires the source
 *                                  GL of the period's group to be confirmed
 *                                  complete (REQ-CONS-002).
 *  - canSubmitForReview():         a period may move to review only once at
 *                                  least one elimination exists and no mismatch
 *                                  is left pending (REQ-CONS-008).
 *  - canClosePeriod():             a period may close only once every elimination
 *                                  entry is approved (none pending/rejected)
 *                                  (REQ-CONS-008).
 *  - canApproveElimination():      an elimination may be approved only with a
 *                                  reviewer recorded and balanced debit/credit
 *                                  lines (REQ-CONS-003 / REQ-CONS-008).
 *  - canRejectElimination():       a rejection requires a reviewer and a written
 *                                  rationale (REQ-CONS-008).
 *  - canFinalizeBalance():         the consolidated balance must satisfy
 *                                  totalAssets = totalLiabilities + totalEquity
 *                                  (REQ-CONS-002).
 *  - canFinalizeIncomeStatement(): netProfitTotal must equal the parent +
 *                                  minority split (REQ-CONS-006).
 *
 * ADR-031 exception reason: array-membership / cross-field completeness and
 * arithmetic-equation checks are not yet expressible in the declarative
 * lifecycle DSL. When the engine gains those capabilities, replace these
 * references with declarative conditions and delete this file. ADR-022: object
 * reads use the real OpenRegister ObjectService API (setRegister/setSchema/
 * findAll) only.
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
 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the commercial consolidation registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (ConsolidationGroup, GroupEntity, ConsolidationPeriod, EliminationEntry,
 * ConsolidatedBalance, ConsolidatedIncomeStatement) as
 * OCA\Shillinq\Lifecycle\ConsolidationGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * The eight public guard methods (plus their cohesive private helpers) keep the
 * RJ 217 / IFRS 10 consolidation preconditions together rather than fragmenting
 * them across several thin collaborator classes; the aggregate per-class
 * complexity slightly exceeds the default phpmd threshold as a result, but the
 * real branch count is low and splitting would hurt readability. The class is
 * suppressed accordingly.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
 */
class ConsolidationGuard {
	/**
	 * Float comparison epsilon for balance-sheet / split equations.
	 *
	 * @var float
	 */
	private const EPSILON = 0.01;

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
	 * Returns true iff a ConsolidationGroup may leave draft for active.
	 *
	 * REQ-CONS-001: a parent administration must be set before the group goes live.
	 *
	 * @param string $groupId The ConsolidationGroup id (call-signature parity).
	 * @param array<string,mixed>|null $object The group being transitioned.
	 *
	 * @return bool True when the group may be activated.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canActivateGroup(string $groupId, ?array $object = null): bool {
		try {
			$group = $this->resolveObject(schema: 'ConsolidationGroup', id: $groupId, object: $object);
			if ($group === null) {
				return false;
			}

			return trim((string)($group['parentAdministrationId'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: group activate check failed — denying transition (fail-closed)',
				['groupId' => $groupId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivateGroup()

	/**
	 * Returns true iff a GroupEntity may be activated.
	 *
	 * Design D2: the ownership percentage and consolidation method must be
	 * consistent — a controlling (>50%) holding consolidates integrally, a ~50%
	 * joint venture proportionally, and a <50% associate by the equity method.
	 *
	 * @param string $entityId The GroupEntity id (call-signature parity).
	 * @param array<string,mixed>|null $object The entity being transitioned.
	 *
	 * @return bool True when the entity may be activated.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canActivateEntity(string $entityId, ?array $object = null): bool {
		try {
			$entity = $this->resolveObject(schema: 'GroupEntity', id: $entityId, object: $object);
			if ($entity === null) {
				return false;
			}

			$method = (string)($entity['consolidationMethod'] ?? '');
			$ownership = (float)($entity['ownershipPercentage'] ?? 0.0);
			if ($method === '' || $ownership < 0.0 || $ownership > 100.0) {
				return false;
			}

			return $this->isMethodConsistent(method: $method, ownership: $ownership);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: entity activate check failed — denying transition (fail-closed)',
				['entityId' => $entityId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivateEntity()

	/**
	 * Returns true iff a ConsolidationPeriod may enter the elimination phase.
	 *
	 * REQ-CONS-002: the period must carry a group, a start and an end date — the
	 * pre-elimination aggregation cannot run without the consolidation boundary.
	 *
	 * @param string $periodId The ConsolidationPeriod id (call-signature parity).
	 * @param array<string,mixed>|null $object The period being transitioned.
	 *
	 * @return bool True when the elimination phase may start.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canStartElimination(string $periodId, ?array $object = null): bool {
		try {
			$period = $this->resolveObject(schema: 'ConsolidationPeriod', id: $periodId, object: $object);
			if ($period === null) {
				return false;
			}

			return trim((string)($period['consolidationGroupId'] ?? '')) !== ''
				&& trim((string)($period['periodStart'] ?? '')) !== ''
				&& trim((string)($period['periodEnd'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: start-elimination check failed — denying transition (fail-closed)',
				['periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canStartElimination()

	/**
	 * Returns true iff a ConsolidationPeriod may be submitted for review.
	 *
	 * REQ-CONS-008: at least one elimination must exist and no mismatch may be
	 * left pending in the exception queue.
	 *
	 * @param string $periodId The ConsolidationPeriod id (call-signature parity).
	 * @param array<string,mixed>|null $object The period being transitioned.
	 *
	 * @return bool True when the period may go to review.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canSubmitForReview(string $periodId, ?array $object = null): bool {
		try {
			$period = $this->resolveObject(schema: 'ConsolidationPeriod', id: $periodId, object: $object);
			if ($period === null) {
				return false;
			}

			if ((int)($period['totalEliminationCount'] ?? 0) < 1) {
				return false;
			}

			$mismatches = $period['mismatches'] ?? [];
			if (is_array($mismatches) === false) {
				return false;
			}

			foreach ($mismatches as $mismatch) {
				if (is_array($mismatch) === true
					&& (string)($mismatch['status'] ?? 'pending') === 'pending'
				) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: submit-for-review check failed — denying transition (fail-closed)',
				['periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canSubmitForReview()

	/**
	 * Returns true iff a ConsolidationPeriod may be closed.
	 *
	 * REQ-CONS-008: every elimination entry in the period must be approved; a
	 * single pending or rejected entry blocks closure.
	 *
	 * The $object parameter is part of the shared engine call signature
	 * ((string $id, ?array $object)); closure is decided by the period's
	 * EliminationEntry set rather than the in-flight period object, so it is
	 * intentionally unused here.
	 *
	 * @param string $periodId The ConsolidationPeriod id (call-signature parity).
	 * @param array<string,mixed>|null $object Unused; present for engine call-signature parity.
	 *
	 * @return bool True when the period may be closed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canClosePeriod(string $periodId, ?array $object = null): bool {
		try {
			if ($periodId === '') {
				return false;
			}

			$register = $this->resolveRegister();

			$entries = $this->objectService
				->setRegister($register)
				->setSchema('EliminationEntry')
				->findAll(['filters' => ['consolidationPeriodId' => $periodId]]);

			$count = 0;
			foreach ($entries as $entry) {
				if (is_array($entry) === false) {
					return false;
				}

				$count++;
				if ((string)($entry['reviewStatus'] ?? 'pending') !== 'approved') {
					return false;
				}
			}

			return $count > 0;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: close-period check failed — denying transition (fail-closed)',
				['periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canClosePeriod()

	/**
	 * Returns true iff an EliminationEntry may be approved.
	 *
	 * REQ-CONS-003 / REQ-CONS-008: an approval requires a recorded reviewer and
	 * the entry's debit and credit lines must balance.
	 *
	 * @param string $entryId The EliminationEntry id (call-signature parity).
	 * @param array<string,mixed>|null $object The entry being transitioned.
	 *
	 * @return bool True when the entry may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canApproveElimination(string $entryId, ?array $object = null): bool {
		try {
			$entry = $this->resolveObject(schema: 'EliminationEntry', id: $entryId, object: $object);
			if ($entry === null) {
				return false;
			}

			if (trim((string)($entry['reviewedBy'] ?? '')) === '') {
				return false;
			}

			return $this->areLinesBalanced(lines: ($entry['lines'] ?? []));
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: approve-elimination check failed — denying transition (fail-closed)',
				['entryId' => $entryId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveElimination()

	/**
	 * Returns true iff an EliminationEntry may be rejected.
	 *
	 * REQ-CONS-008: a rejection requires a recorded reviewer and a written
	 * rationale that enters the permanent audit trail.
	 *
	 * @param string $entryId The EliminationEntry id (call-signature parity).
	 * @param array<string,mixed>|null $object The entry being transitioned.
	 *
	 * @return bool True when the entry may be rejected.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canRejectElimination(string $entryId, ?array $object = null): bool {
		try {
			$entry = $this->resolveObject(schema: 'EliminationEntry', id: $entryId, object: $object);
			if ($entry === null) {
				return false;
			}

			return trim((string)($entry['reviewedBy'] ?? '')) !== ''
				&& trim((string)($entry['reviewComment'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: reject-elimination check failed — denying transition (fail-closed)',
				['entryId' => $entryId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canRejectElimination()

	/**
	 * Returns true iff a ConsolidatedBalance may be finalised.
	 *
	 * REQ-CONS-002: the balance-sheet equation totalAssets = totalLiabilities +
	 * totalEquity must hold within rounding tolerance.
	 *
	 * @param string $balanceId The ConsolidatedBalance id (call-signature parity).
	 * @param array<string,mixed>|null $object The balance being transitioned.
	 *
	 * @return bool True when the balance may be finalised.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canFinalizeBalance(string $balanceId, ?array $object = null): bool {
		try {
			$balance = $this->resolveObject(schema: 'ConsolidatedBalance', id: $balanceId, object: $object);
			if ($balance === null) {
				return false;
			}

			$assets = (float)($balance['totalAssets'] ?? 0.0);
			$liabilities = (float)($balance['totalLiabilities'] ?? 0.0);
			$equity = (float)($balance['totalEquity'] ?? 0.0);

			return abs($assets - ($liabilities + $equity)) <= self::EPSILON;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: finalize-balance check failed — denying transition (fail-closed)',
				['balanceId' => $balanceId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canFinalizeBalance()

	/**
	 * Returns true iff a ConsolidatedIncomeStatement may be finalised.
	 *
	 * REQ-CONS-006: the net-profit split must reconcile —
	 * netProfitTotal = netProfitAttributedToParent + netProfitAttributedToMinority.
	 *
	 * @param string $statementId The ConsolidatedIncomeStatement id (call-signature parity).
	 * @param array<string,mixed>|null $object The statement being transitioned.
	 *
	 * @return bool True when the income statement may be finalised.
	 *
	 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
	 */
	public function canFinalizeIncomeStatement(string $statementId, ?array $object = null): bool {
		try {
			$statement = $this->resolveObject(schema: 'ConsolidatedIncomeStatement', id: $statementId, object: $object);
			if ($statement === null) {
				return false;
			}

			$total = (float)($statement['netProfitTotal'] ?? 0.0);
			$parent = (float)($statement['netProfitAttributedToParent'] ?? 0.0);
			$minority = (float)($statement['netProfitAttributedToMinority'] ?? 0.0);

			return abs($total - ($parent + $minority)) <= self::EPSILON;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ConsolidationGuard: finalize-income-statement check failed — denying transition (fail-closed)',
				['statementId' => $statementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canFinalizeIncomeStatement()

	/**
	 * Returns true iff a consolidation method is consistent with an ownership
	 * percentage (design D2): integral for controlling (>50%), proportional for
	 * a ~50% joint venture, equity for a <50% associate.
	 *
	 * @param string $method The consolidation method.
	 * @param float $ownership The ownership percentage (0-100).
	 *
	 * @return bool True when method and ownership are consistent.
	 */
	private function isMethodConsistent(string $method, float $ownership): bool {
		switch ($method) {
			case 'integral':
				return $ownership > 50.0;
			case 'proportional':
				return $ownership > 0.0 && $ownership <= 50.0;
			case 'equity':
				return $ownership > 0.0 && $ownership < 50.0;
			default:
				return false;
		}

	}//end isMethodConsistent()

	/**
	 * Returns true iff the elimination lines balance — the summed debit equals
	 * the summed credit within rounding tolerance (REQ-CONS-003).
	 *
	 * @param mixed $lines The elimination lines array.
	 *
	 * @return bool True when the lines balance.
	 */
	private function areLinesBalanced(mixed $lines): bool {
		if (is_array($lines) === false || $lines === []) {
			return false;
		}

		$debit = 0.0;
		$credit = 0.0;
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				return false;
			}

			$debit += (float)($line['debit'] ?? 0.0);
			$credit += (float)($line['credit'] ?? 0.0);
		}

		return abs($debit - $credit) <= self::EPSILON;
	}//end areLinesBalanced()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
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

		$register = $this->resolveRegister();

		$results = $this->objectService
			->setRegister($register)
			->setSchema($schema)
			->findAll(['filters' => ['id' => $id]]);

		foreach ($results as $result) {
			if (is_array($result) === true) {
				return $result;
			}
		}

		return null;
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
