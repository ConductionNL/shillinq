<?php

/**
 * CCM Finding & Report Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the Continuous Controls Monitoring
 * registers (bookkeeping-ccm-rule-engine, T3). The finding triage four-state
 * workflow and the audit-committee-report approval gate are declarative
 * (x-openregister-lifecycle on CcmFinding / CcmAuditCommitteeReport). A small set
 * of cross-field preconditions that the declarative `requires:` clause cannot yet
 * express are referenced from those transitions and implemented here:
 *
 *  - canDismiss():        a finding may only be dismissed (false-positive or
 *                         acceptable-risk) when a mandatory resolution rationale
 *                         is present (REQ-CCM-004).
 *  - canConfirm():        a finding may only be confirmed (control-deficiency or
 *                         fraud-suspected) when a mandatory resolution rationale
 *                         is present (REQ-CCM-004).
 *  - canApproveReport():  an audit-committee report may only be approved when an
 *                         approver and a non-empty executive summary are present
 *                         (REQ-CCM-006).
 *
 * ADR-031 exception reason: presence/completeness checks across fields are not yet
 * expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete
 * this file. ADR-022: object reads use the real OpenRegister ObjectService API
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
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the CCM finding triage workflow and the
 * audit-committee report approval gate.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (CcmFinding dismiss/confirm, CcmAuditCommitteeReport approve) as
 * OCA\Shillinq\Lifecycle\CcmFindingGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
class CcmFindingGuard {
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
	 * Returns true iff a finding may be dismissed (false-positive / acceptable-risk).
	 *
	 * REQ-CCM-004: dismissal requires a mandatory free-text resolution rationale.
	 *
	 * @param string $findingId The CcmFinding id (call-signature parity).
	 * @param array<string,mixed>|null $object The finding being transitioned.
	 *
	 * @return bool True when the finding may be dismissed.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function canDismiss(string $findingId, ?array $object = null): bool {
		try {
			$finding = $this->resolveObject(schema: 'CcmFinding', id: $findingId, object: $object);
			if ($finding === null) {
				return false;
			}

			return trim((string)($finding['resolutionRationale'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'CcmFindingGuard: dismiss check failed — denying transition (fail-closed)',
				['findingId' => $findingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canDismiss()

	/**
	 * Returns true iff a finding may be confirmed (control-deficiency / fraud).
	 *
	 * REQ-CCM-004: confirmation/escalation requires a mandatory resolution rationale.
	 *
	 * @param string $findingId The CcmFinding id (call-signature parity).
	 * @param array<string,mixed>|null $object The finding being transitioned.
	 *
	 * @return bool True when the finding may be confirmed.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function canConfirm(string $findingId, ?array $object = null): bool {
		try {
			$finding = $this->resolveObject(schema: 'CcmFinding', id: $findingId, object: $object);
			if ($finding === null) {
				return false;
			}

			return trim((string)($finding['resolutionRationale'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'CcmFindingGuard: confirm check failed — denying transition (fail-closed)',
				['findingId' => $findingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canConfirm()

	/**
	 * Returns true iff an audit-committee report may be approved (chair sign-off).
	 *
	 * REQ-CCM-006: an approver must be present and the executive summary must be
	 * non-empty before the report leaves in-review for approved.
	 *
	 * @param string $reportId The CcmAuditCommitteeReport id (call-signature parity).
	 * @param array<string,mixed>|null $object The report being transitioned.
	 *
	 * @return bool True when the report may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function canApproveReport(string $reportId, ?array $object = null): bool {
		try {
			$report = $this->resolveObject(schema: 'CcmAuditCommitteeReport', id: $reportId, object: $object);
			if ($report === null) {
				return false;
			}

			return trim((string)($report['approver'] ?? '')) !== ''
				&& trim((string)($report['executiveSummary'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'CcmFindingGuard: report approve check failed — denying transition (fail-closed)',
				['reportId' => $reportId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApproveReport()

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
