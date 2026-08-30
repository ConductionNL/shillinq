<?php

/**
 * Irregularity Report Guard
 *
 * ADR-031 exception-path lifecycle guard for the IrregularityReport register
 * (bookkeeping-single-audit-eu-fondsen, T3 regulatory + compliance). One
 * precondition is referenced from the IrregularityReport schema's
 * x-openregister-lifecycle transitions because it encodes the OLAF €10k
 * IMS-meldplicht that the declarative `requires:` clause cannot yet express:
 *
 *  - canEscalate(): when amountConcerned reaches the OLAF threshold (€10.000),
 *                   an imsReference MUST be present before the report may move
 *                   to vervolgcontrole — i.e. the IMS-melding must have been
 *                   filed (REQ-EUF-007). Below the threshold the IMS-melding is
 *                   not mandatory and escalation is allowed.
 *
 * ADR-031 exception reason: the threshold-conditional mandatory-field check is
 * not yet expressible in the declarative lifecycle DSL. When the engine gains
 * conditional `requires:` support, replace this with a declarative condition
 * and delete this file.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the IrregularityReport escalate transition.
 *
 * Referenced from the IrregularityReport schema (register.d fragment)
 * x-openregister-lifecycle transitions.escalate.requires as
 * OCA\Shillinq\Lifecycle\IrregularityReportGuard::canEscalate.
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class IrregularityReportGuard {

	/**
	 * OLAF IMS-meldplicht threshold in EUR (REQ-EUF-007).
	 *
	 * @var float
	 */
	public const OLAF_THRESHOLD_EUR = 10000.0;

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
	 * Returns true iff the irregularity may be escalated to vervolgcontrole.
	 *
	 * REQ-EUF-007: when amountConcerned >= the OLAF threshold (€10.000), an
	 * imsReference must be present (the IMS-melding has been filed) before the
	 * report may escalate. Below the threshold, escalation is unconditional.
	 *
	 * Fail-closed: returns false on any exception (REQ-EUF-007 / CWE-863).
	 *
	 * @param string $irregularityReportId The IrregularityReport.id (call-signature parity).
	 * @param array<string,mixed>|null $object The IrregularityReport object being transitioned.
	 *
	 * @return bool True when the report may escalate.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canEscalate(string $irregularityReportId, ?array $object = null): bool {
		try {
			$report = $this->resolveReport(irregularityReportId: $irregularityReportId, object: $object);
			if ($report === null) {
				return false;
			}

			$amount = (float)($report['amountConcerned'] ?? 0.0);
			if ($amount < self::OLAF_THRESHOLD_EUR) {
				// Below OLAF threshold — IMS-melding not mandatory.
				return true;
			}

			// At/above threshold — IMS-melding (imsReference) must be present.
			$imsReference = trim((string)($report['imsReference'] ?? ''));
			return $imsReference !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'IrregularityReportGuard: escalate check failed — denying escalate transition (fail-closed)',
				['irregularityReportId' => $irregularityReportId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canEscalate()

	/**
	 * Resolve the IrregularityReport object, preferring the supplied object and
	 * falling back to an ObjectService lookup by id.
	 *
	 * @param string $irregularityReportId The IrregularityReport.id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return array<string,mixed>|null The report, or null when unresolved.
	 */
	private function resolveReport(string $irregularityReportId, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($irregularityReportId === '') {
			return null;
		}

		$reports = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('IrregularityReport')
			->findAll(['filters' => ['id' => $irregularityReportId], 'limit' => 1]);

		foreach ($reports as $report) {
			if (is_array($report) === true) {
				return $report;
			}
		}

		return null;
	}//end resolveReport()

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
