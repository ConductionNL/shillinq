<?php

/**
 * Auditor Statement Guard
 *
 * ADR-031 exception-path lifecycle guard for the AuditorStatement register
 * (bookkeeping-subsidie-verantwoording, T3 governance). The canApprove()
 * precondition is referenced from the AuditorStatement schema's
 * x-openregister-lifecycle `approve` transition because it requires
 * aggregation over the embedded `findings` array that OpenRegister's
 * declarative `requires:` clause cannot yet express.
 *
 * REQ-SUBV-005: an `under-review -> approved` transition is permitted only when
 * the statement has no findings, or all findings are marked `resolved`. A
 * statement with at least one unresolved finding may not be approved (the
 * auditor must instead use the `reject` or `conditional` transition).
 *
 * ADR-031 exception reason: cross-array aggregation is not yet expressible in
 * the declarative lifecycle DSL. When the engine gains that capability, replace
 * this reference with a declarative condition and delete this file.
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
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the AuditorStatement approve transition.
 *
 * Referenced from the AuditorStatement schema (register.d fragment)
 * x-openregister-lifecycle transitions.approve.requires as
 * OCA\Shillinq\Lifecycle\AuditorStatementGuard::canApprove.
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */
class AuditorStatementGuard {
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
	 * Returns true iff the auditor statement may transition under-review -> approved.
	 *
	 * REQ-SUBV-005: approval requires no findings, or all findings marked
	 * `resolved`. Any unresolved finding denies the approve transition.
	 *
	 * Fail-closed: returns false on any exception (REQ-SUBV-005 / CWE-863).
	 *
	 * @param string $statementId The AuditorStatement.id
	 *                            (call-signature parity; the
	 *                            in-flight object is preferred).
	 * @param array<string,mixed>|null $object The AuditorStatement being transitioned.
	 *
	 * @return bool True when the auditor statement may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
	 */
	public function canApprove(string $statementId, ?array $object = null): bool {
		try {
			if ($object === null) {
				$object = $this->resolveStatement(statementId: $statementId);
			}

			if ($object === null) {
				return false;
			}

			$findings = ($object['findings'] ?? []);
			if (is_array($findings) === false) {
				return false;
			}

			foreach ($findings as $finding) {
				if (is_array($finding) === false) {
					return false;
				}

				$resolution = (string)($finding['resolution'] ?? 'open');
				if ($resolution !== 'resolved') {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'AuditorStatementGuard: approve check failed — denying approve transition (fail-closed)',
				['statementId' => $statementId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApprove()

	/**
	 * Resolve an AuditorStatement object array by id.
	 *
	 * @param string $statementId The AuditorStatement.id to look up.
	 *
	 * @return array<string,mixed>|null The object array, or null when not found.
	 */
	private function resolveStatement(string $statementId): ?array {
		if ($statementId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$records = $this->objectService
			->setRegister($register)
			->setSchema('AuditorStatement')
			->findAll(['filters' => ['id' => $statementId]]);

		foreach ($records as $record) {
			if (is_array($record) === true) {
				return $record;
			}

			if (is_object($record) === true && method_exists($record, 'getObject') === true) {
				$data = $record->getObject();
				if (is_array($data) === true) {
					return $data;
				}
			}
		}

		return null;
	}//end resolveStatement()

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
