<?php

/**
 * Subsidie Verantwoording Guard
 *
 * ADR-031 exception-path lifecycle guard for the SubsidieVerantwoording register
 * (bookkeeping-subsidie-verantwoording, T3 governance). The canApprove()
 * precondition is referenced from the SubsidieVerantwoording schema's
 * x-openregister-lifecycle `approve` transition because it requires a
 * cross-schema lookup (the grant's AuditorStatement state) that OpenRegister's
 * declarative `requires:` clause cannot yet express.
 *
 * REQ-SUBV-003: a `submitted -> approved` transition is BLOCKED while the grant
 * is at or above the administration's auditor threshold (default EUR 25,000) and
 * its AuditorStatement is not yet in an approved or conditional state. Grants
 * below the threshold, or with an approved/conditional auditor statement, are
 * permitted to approve.
 *
 * ADR-031 exception reason: cross-schema state aggregation is not yet
 * expressible in the declarative lifecycle DSL. When the engine gains that
 * capability, replace this reference with a declarative condition and delete
 * this file.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for the SubsidieVerantwoording approve transition.
 *
 * Referenced from the SubsidieVerantwoording schema (register.d fragment)
 * x-openregister-lifecycle transitions.approve.requires as
 * OCA\Shillinq\Lifecycle\SubsidieVerantwoordingGuard::canApprove.
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */
class SubsidieVerantwoordingGuard {
	/**
	 * Default auditor threshold in the administration's base currency (EUR).
	 *
	 * Per REQ-SUBV-006 / design D3 — grants at or above this awarded amount
	 * require an approved auditor statement before the accountability report
	 * may be approved. Operators override via the `auditor_threshold` app config
	 * key per administration.
	 */
	private const DEFAULT_AUDIT_THRESHOLD = 25000.0;

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug and threshold.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the accountability report may transition submitted -> approved.
	 *
	 * REQ-SUBV-003: approval is blocked when the grant's awarded amount is at or
	 * above the auditor threshold AND no AuditorStatement for the grant is in an
	 * `approved` or `conditional` state. A grant below the threshold, or one whose
	 * auditor statement is approved/conditional, is permitted to approve.
	 *
	 * Fail-closed: returns false on any exception (REQ-SUBV-003 / CWE-863).
	 *
	 * @param string $verantwoordingId The SubsidieVerantwoording.id
	 *                                 (call-signature parity; the
	 *                                 in-flight object is preferred).
	 * @param array<string,mixed>|null $object The SubsidieVerantwoording being transitioned.
	 *
	 * @return bool True when the accountability report may be approved.
	 *
	 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
	 */
	public function canApprove(string $verantwoordingId, ?array $object = null): bool {
		try {
			if ($object === null) {
				$object = $this->resolveVerantwoording(verantwoordingId: $verantwoordingId);
			}

			if ($object === null) {
				return false;
			}

			$awardedAmount = (float)($object['awardedAmount'] ?? 0.0);
			if ($awardedAmount < $this->resolveThreshold()) {
				// Below threshold: no auditor statement required, approval permitted.
				return true;
			}

			$grantId = (string)($object['grantId'] ?? '');
			if ($grantId === '') {
				// Large grant with no grant reference cannot be reconciled — fail closed.
				return false;
			}

			return $this->hasApprovedAuditorStatement(grantId: $grantId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SubsidieVerantwoordingGuard: approve check failed — denying approve transition (fail-closed)',
				['verantwoordingId' => $verantwoordingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canApprove()

	/**
	 * Returns true iff the grant has at least one AuditorStatement in approved or conditional state.
	 *
	 * @param string $grantId The Subsidie grant identifier (Subsidie.subsidieNumber).
	 *
	 * @return bool True when a non-blocking auditor statement exists.
	 */
	private function hasApprovedAuditorStatement(string $grantId): bool {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$register = $this->resolveRegister();

		$statements = $objectService
			->setRegister($register)
			->setSchema('AuditorStatement')
			->findAll(['filters' => ['grantId' => $grantId]]);

		foreach ($statements as $statement) {
			$data = $this->toArray(entity: $statement);
			$state = (string)($data['status'] ?? '');
			if ($state === 'approved' || $state === 'conditional') {
				return true;
			}
		}

		return false;
	}//end hasApprovedAuditorStatement()

	/**
	 * Resolve a SubsidieVerantwoording object array by id.
	 *
	 * @param string $verantwoordingId The SubsidieVerantwoording.id to look up.
	 *
	 * @return array<string,mixed>|null The object array, or null when not found.
	 */
	private function resolveVerantwoording(string $verantwoordingId): ?array {
		if ($verantwoordingId === '') {
			return null;
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$register = $this->resolveRegister();

		$records = $objectService
			->setRegister($register)
			->setSchema('SubsidieVerantwoording')
			->findAll(['filters' => ['id' => $verantwoordingId]]);

		foreach ($records as $record) {
			return $this->toArray(entity: $record);
		}

		return null;
	}//end resolveVerantwoording()

	/**
	 * Normalise an OpenRegister entity or array to a plain object array.
	 *
	 * @param mixed $entity The OpenRegister entity or array.
	 *
	 * @return array<string,mixed> The object data.
	 */
	private function toArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Resolve the configured auditor threshold, defaulting to EUR 25,000.
	 *
	 * @return float The auditor threshold in the base currency.
	 */
	private function resolveThreshold(): float {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'auditor_threshold', '');
		if ($raw === '' || is_numeric($raw) === false) {
			return self::DEFAULT_AUDIT_THRESHOLD;
		}

		return (float)$raw;
	}//end resolveThreshold()

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
