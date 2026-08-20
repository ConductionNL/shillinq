<?php

/**
 * IAS 19 / RJ 271 Pension Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the IAS 19 / RJ 271 pension
 * registers (bookkeeping-pension-ias19, T3). The bulk of the pension
 * measurement model is declarative (schema metadata + x-openregister-lifecycle
 * + x-openregister-aggregations for roll-forward, sensitivity and disclosure
 * generation). A small set of lifecycle preconditions require cross-field /
 * cross-schema completeness checks that OpenRegister's declarative `requires:`
 * clause cannot yet express; those are referenced from the schema lifecycle
 * transitions and implemented here:
 *
 *  - canActivatePlan():       a PensionPlan may only go active once its type and
 *                             framework are set, and — for DB/CDC/hybrid plans —
 *                             an accrual rate + pensionable-salary definition are
 *                             present (REQ-PEN-001).
 *  - canApproveValuation():   an ActuarialValuation may only be approved once the
 *                             discount-rate source is recorded, and — for DB plans
 *                             (methodology must be PUC) — assumptions are complete;
 *                             DC plans must NOT carry a DBO (REQ-PEN-001, REQ-PEN-002,
 *                             REQ-PEN-008).
 *  - canLockValuation():      an approved ValuationLifecycle may only lock once it
 *                             carries an approver and the HRMQ roster has been
 *                             reconciled (rosterReconciled flag), guarding REQ-PEN-010.
 *
 * ADR-031 exception reason: cross-field completeness and enum-conditional checks
 * are not yet expressible in the declarative lifecycle DSL. When the engine gains
 * those capabilities, replace these references with declarative conditions and
 * delete this file. ADR-022: object reads use the real OpenRegister ObjectService
 * API (setRegister/setSchema/findAll) only.
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
 * @spec openspec/specs/bookkeeping-pension-ias19/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the IAS 19 / RJ 271 pension registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (PensionPlan, ActuarialValuation) as
 * OCA\Shillinq\Lifecycle\PensionIas19Guard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/bookkeeping-pension-ias19/spec.md
 */
class PensionIas19Guard {
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
	 * Returns true iff the PensionPlan may leave draft and become active.
	 *
	 * REQ-PEN-001: a plan needs a type, a regulatory framework and a
	 * pensionable-salary definition. For benefit-accruing plans (DB / CDC /
	 * hybrid) an accrual rate is also required before activation.
	 *
	 * @param string $planId The PensionPlan id (call-signature parity).
	 * @param array<string,mixed>|null $object The plan being transitioned.
	 *
	 * @return bool True when the plan may be activated.
	 *
	 * @spec openspec/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function canActivatePlan(string $planId, ?array $object = null): bool {
		try {
			$plan = $this->resolveObject(schema: 'PensionPlan', id: $planId, object: $object);
			if ($plan === null) {
				return false;
			}

			$planType = (string)($plan['planType'] ?? '');
			if (in_array($planType, ['DB', 'DC', 'CDC', 'hybrid'], true) === false) {
				return false;
			}

			if ((string)($plan['regulatoryFramework'] ?? '') === '') {
				return false;
			}

			if (trim((string)($plan['pensionableSalaryDefinition'] ?? '')) === '') {
				return false;
			}

			// Benefit-accruing plans must carry an accrual rate before activation.
			if (in_array($planType, ['DB', 'CDC', 'hybrid'], true) === true) {
				$accrualRate = $plan['accrualRate'] ?? null;
				if (is_numeric($accrualRate) === false || (float)$accrualRate <= 0.0) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PensionIas19Guard: plan activation check failed — denying transition (fail-closed)',
				['planId' => $planId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivatePlan()

	/**
	 * Returns true iff the ActuarialValuation may transition from draft to approved.
	 *
	 * REQ-PEN-001 / REQ-PEN-002 / REQ-PEN-008: the discount-rate source must be
	 * recorded for audit; DB plans (methodology=PUC) must carry a positive DBO and
	 * complete core assumptions; DC valuations (methodology=DC) must NOT carry a DBO.
	 *
	 * @param string $valuationId The ActuarialValuation id (call-signature parity).
	 * @param array<string,mixed>|null $object The valuation being transitioned.
	 *
	 * @return bool True when the valuation may be approved.
	 *
	 * @spec openspec/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function canApproveValuation(string $valuationId, ?array $object = null): bool {
		try {
			$valuation = $this->resolveObject(schema: 'ActuarialValuation', id: $valuationId, object: $object);
			if ($valuation === null) {
				return false;
			}

			// REQ-PEN-002: discount-rate source must be recorded for the audit trail.
			if (trim((string)($valuation['discountRateSource'] ?? '')) === '') {
				return false;
			}

			$methodology = (string)($valuation['methodology'] ?? '');

			if ($methodology === 'DC') {
				return $this->isDcValuationComplete(valuation: $valuation);
			}

			if ($methodology === 'PUC') {
				return $this->isPucValuationComplete(valuation: $valuation);
			}

			// REQ-PEN-001: DB plans must use PUC; anything else is rejected.
			return false;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PensionIas19Guard: valuation approve check failed — denying transition (fail-closed)',
				['valuationId' => $valuationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveValuation()

	/**
	 * Returns true iff a DC valuation is complete enough to approve.
	 *
	 * REQ-PEN-008: DC valuations carry no DBO measurement.
	 *
	 * @param array<string,mixed> $valuation The valuation under transition.
	 *
	 * @return bool True when the DC valuation may be approved.
	 */
	private function isDcValuationComplete(array $valuation): bool {
		$dboGross = $valuation['dboGross'] ?? null;

		return (is_numeric($dboGross) === false || (float)$dboGross === 0.0);
	}//end isDcValuationComplete()

	/**
	 * Returns true iff a PUC valuation carries a positive DBO and core assumptions.
	 *
	 * REQ-PEN-001: a DB valuation must use PUC, carry a positive DBO, a mortality
	 * table and a numeric salary-growth assumption.
	 *
	 * @param array<string,mixed> $valuation The valuation under transition.
	 *
	 * @return bool True when the PUC valuation may be approved.
	 */
	private function isPucValuationComplete(array $valuation): bool {
		$dboGross = $valuation['dboGross'] ?? null;
		if (is_numeric($dboGross) === false || (float)$dboGross <= 0.0) {
			return false;
		}

		if (trim((string)($valuation['mortalityTable'] ?? '')) === '') {
			return false;
		}

		return is_numeric($valuation['salaryGrowthAssumption'] ?? null);
	}//end isPucValuationComplete()

	/**
	 * Returns true iff the approved ActuarialValuation may be locked.
	 *
	 * REQ-PEN-002 / REQ-PEN-010: an approver must be recorded and the HRMQ
	 * deelnemersbestand must have been reconciled (rosterReconciled flag) before
	 * the valuation is locked into the jaarrekening.
	 *
	 * @param string $valuationId The ActuarialValuation id (call-signature parity).
	 * @param array<string,mixed>|null $object The valuation being transitioned.
	 *
	 * @return bool True when the valuation may be locked.
	 *
	 * @spec openspec/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function canLockValuation(string $valuationId, ?array $object = null): bool {
		try {
			$valuation = $this->resolveObject(schema: 'ActuarialValuation', id: $valuationId, object: $object);
			if ($valuation === null) {
				return false;
			}

			if (trim((string)($valuation['approvedBy'] ?? '')) === '') {
				return false;
			}

			// REQ-PEN-010: the HRMQ roster reconciliation must be signed off.
			// A DC valuation has no roster dependency and may lock once approved.
			if ((string)($valuation['methodology'] ?? '') === 'DC') {
				return true;
			}

			return (bool)($valuation['rosterReconciled'] ?? false) === true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PensionIas19Guard: valuation lock check failed — denying transition (fail-closed)',
				['valuationId' => $valuationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canLockValuation()

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
