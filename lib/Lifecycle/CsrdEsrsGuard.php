<?php

/**
 * CSRD / ESRS Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the CSRD/ESRS sustainability
 * registers (bookkeeping-csrd-esrs, T3). The bulk of the CSRD/ESRS measurement
 * model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-calculations / -aggregations). A small set of preconditions
 * require cross-line / cross-schema lookups that OpenRegister's declarative
 * `requires:` clause cannot yet express; those are referenced from the schema
 * lifecycle transitions and implemented here:
 *
 *  - canSubmitMateriality(): every consulted stakeholder group carries at least
 *                            one consultation method before a MaterialityAssessment
 *                            leaves draft (REQ-CSR-001).
 *  - canApproveMateriality(): an approver is present and every non-material topic
 *                             in the matrix carries a written rationale (REQ-CSR-001).
 *  - canApproveDataPoint():  an EsrsDataPoint must carry a source reference before
 *                            it may be approved — the assurance-critical control
 *                            (REQ-CSR-002 / design D9).
 *  - canRestateDataPoint():  a restated EsrsDataPoint must carry restatedFrom and a
 *                            restatement rationale (REQ-CSR-002 / design D8).
 *  - canIssueOpinion():      an AssuranceEngagement may only issue its opinion once
 *                            every finding is resolved or accepted-risk (REQ-CSR-004).
 *
 * ADR-031 exception reason: array-membership / cross-field completeness checks are
 * not yet expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete this
 * file. ADR-022: object reads use the real OpenRegister ObjectService API
 * (setRegister/setSchema/findAll) only.
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
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the CSRD/ESRS sustainability registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (MaterialityAssessment, EsrsDataPoint, AssuranceEngagement) as
 * OCA\Shillinq\Lifecycle\CsrdEsrsGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 */
class CsrdEsrsGuard {
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
	 * Returns true iff the MaterialityAssessment may leave draft for in-review.
	 *
	 * REQ-CSR-001: at least one stakeholder group must be consulted and every
	 * recorded consultation must carry a group, a method, and a date.
	 *
	 * @param string $assessmentId The MaterialityAssessment id (call-signature parity).
	 * @param array<string,mixed>|null $object The assessment being transitioned.
	 *
	 * @return bool True when the assessment may be submitted.
	 *
	 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
	 */
	public function canSubmitMateriality(string $assessmentId, ?array $object = null): bool {
		try {
			$assessment = $this->resolveObject(schema: 'MaterialityAssessment', id: $assessmentId, object: $object);
			if ($assessment === null) {
				return false;
			}

			$groups = $assessment['stakeholderGroupsConsulted'] ?? [];
			if (is_array($groups) === false || count($groups) < 1) {
				return false;
			}

			foreach ($groups as $group) {
				if ($this->isConsultationComplete(group: $group) === false) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CsrdEsrsGuard: materiality submit check failed — denying transition (fail-closed)',
				['assessmentId' => $assessmentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canSubmitMateriality()

	/**
	 * Returns true iff the MaterialityAssessment may be approved (board sign-off).
	 *
	 * REQ-CSR-001: a board-level approver must be present and every topic flagged
	 * non-material in the double-materiality matrix must carry a written rationale.
	 *
	 * @param string $assessmentId The MaterialityAssessment id (call-signature parity).
	 * @param array<string,mixed>|null $object The assessment being transitioned.
	 *
	 * @return bool True when the assessment may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
	 */
	public function canApproveMateriality(string $assessmentId, ?array $object = null): bool {
		try {
			$assessment = $this->resolveObject(schema: 'MaterialityAssessment', id: $assessmentId, object: $object);
			if ($assessment === null) {
				return false;
			}

			if ((string)($assessment['approver'] ?? '') === '') {
				return false;
			}

			$matrix = $assessment['doubleMaterialityMatrixSnapshot'] ?? [];
			if (is_array($matrix) === false || $matrix === []) {
				return false;
			}

			foreach ($matrix as $entry) {
				if ($this->isMatrixEntryDisclosable(entry: $entry) === false) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CsrdEsrsGuard: materiality approve check failed — denying transition (fail-closed)',
				['assessmentId' => $assessmentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveMateriality()

	/**
	 * Returns true iff the EsrsDataPoint may transition to approved.
	 *
	 * REQ-CSR-002 / design D9: no data point may be approved without a source
	 * reference — the assurance-critical control that makes every value traceable.
	 *
	 * @param string $dataPointId The EsrsDataPoint id (call-signature parity).
	 * @param array<string,mixed>|null $object The data point being transitioned.
	 *
	 * @return bool True when the data point may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
	 */
	public function canApproveDataPoint(string $dataPointId, ?array $object = null): bool {
		try {
			$dataPoint = $this->resolveObject(schema: 'EsrsDataPoint', id: $dataPointId, object: $object);
			if ($dataPoint === null) {
				return false;
			}

			return trim((string)($dataPoint['sourceReference'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'CsrdEsrsGuard: data-point approve check failed — denying transition (fail-closed)',
				['dataPointId' => $dataPointId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveDataPoint()

	/**
	 * Returns true iff the EsrsDataPoint may be restated.
	 *
	 * REQ-CSR-002 / design D8: a restatement must reference the prior-period value
	 * (restatedFrom) and carry a written rationale; the audit trail is immutable.
	 *
	 * @param string $dataPointId The EsrsDataPoint id (call-signature parity).
	 * @param array<string,mixed>|null $object The data point being transitioned.
	 *
	 * @return bool True when the data point may be restated.
	 *
	 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
	 */
	public function canRestateDataPoint(string $dataPointId, ?array $object = null): bool {
		try {
			$dataPoint = $this->resolveObject(schema: 'EsrsDataPoint', id: $dataPointId, object: $object);
			if ($dataPoint === null) {
				return false;
			}

			$restatedFrom = trim((string)($dataPoint['restatedFrom'] ?? ''));
			$rationale = trim((string)($dataPoint['restatementRationale'] ?? ''));

			return $restatedFrom !== '' && $rationale !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'CsrdEsrsGuard: data-point restate check failed — denying transition (fail-closed)',
				['dataPointId' => $dataPointId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canRestateDataPoint()

	/**
	 * Returns true iff the AssuranceEngagement may issue its opinion.
	 *
	 * REQ-CSR-004: the partner may only sign the opinion once every finding is
	 * resolved or accepted-risk; an open finding blocks the opinion.
	 *
	 * @param string $engagementId The AssuranceEngagement id (call-signature parity).
	 * @param array<string,mixed>|null $object The engagement being transitioned.
	 *
	 * @return bool True when the opinion may be issued.
	 *
	 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
	 */
	public function canIssueOpinion(string $engagementId, ?array $object = null): bool {
		try {
			$engagement = $this->resolveObject(schema: 'AssuranceEngagement', id: $engagementId, object: $object);
			if ($engagement === null) {
				return false;
			}

			$findings = $engagement['findings'] ?? [];
			if (is_array($findings) === false) {
				return false;
			}

			foreach ($findings as $finding) {
				if (is_array($finding) === false) {
					return false;
				}

				$status = (string)($finding['status'] ?? 'open');
				if ($status !== 'resolved' && $status !== 'accepted-risk') {
					// An open finding blocks the opinion (REQ-CSR-004).
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CsrdEsrsGuard: issue-opinion check failed — denying transition (fail-closed)',
				['engagementId' => $engagementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canIssueOpinion()

	/**
	 * Returns true iff a stakeholder-consultation row carries a group, a method,
	 * and a date (REQ-CSR-001).
	 *
	 * @param mixed $group The consultation row to validate.
	 *
	 * @return bool True when the consultation row is complete.
	 */
	private function isConsultationComplete(mixed $group): bool {
		if (is_array($group) === false) {
			return false;
		}

		return ($group['group'] ?? '') !== ''
			&& ($group['consultationMethod'] ?? '') !== ''
			&& ($group['date'] ?? '') !== '';

	}//end isConsultationComplete()

	/**
	 * Returns true iff a matrix entry is disclosable: either material, or
	 * non-material with a written rationale (REQ-CSR-001).
	 *
	 * @param mixed $entry The double-materiality matrix entry to validate.
	 *
	 * @return bool True when the entry may be approved.
	 */
	private function isMatrixEntryDisclosable(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		if ((bool)($entry['material'] ?? false) === true) {
			return true;
		}

		return trim((string)($entry['rationale'] ?? '')) !== '';
	}//end isMatrixEntryDisclosable()

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
