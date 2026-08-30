<?php

/**
 * Contract Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guard for the Contract schema. Provides two
 * single-purpose, fail-closed precondition methods referenced from the Contract
 * schema's x-openregister-lifecycle transitions in
 * lib/Settings/register.d/contract-lifecycle-management.json:
 *  - canActivate(): the draft → active precondition (REQ-CLM-002) — requires
 *    startDate, counterpartyReference, and contractOwner all present.
 *  - requireTerminationReason(): the active|expiring → terminated precondition
 *    (REQ-CLM-002) — requires a non-empty terminationReason.
 *
 * ADR-031 exception reason: the declarative lifecycle DSL cannot yet express a
 * multi-field "all required" guard (canActivate) or a conditional-required
 * field guard (requireTerminationReason) inside a `requires:` clause. When the
 * engine gains those capabilities, replace these references with declarative
 * conditions and delete this file.
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
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Fail-closed lifecycle precondition guard for the Contract schema.
 *
 * Referenced from contract-lifecycle-management.json Contract
 * x-openregister-lifecycle transitions.activate.requires
 * (OCA\Shillinq\Lifecycle\ContractLifecycleGuard::canActivate) and
 * transitions.terminate.requires
 * (OCA\Shillinq\Lifecycle\ContractLifecycleGuard::requireTerminationReason).
 *
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 */
class ContractLifecycleGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the draft → active precondition is satisfied (REQ-CLM-002).
	 *
	 * The transition is allowed only when startDate, counterpartyReference, and
	 * contractOwner are all present and non-empty. Fail-closed: any missing or
	 * empty mandatory field denies the transition with a logged reason.
	 *
	 * @param array<string,mixed> $contract The contract field map.
	 *
	 * @return bool True when all mandatory activation fields are present.
	 *
	 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
	 */
	public function canActivate(array $contract): bool {
		try {
			$missing = [];
			foreach (['startDate', 'counterpartyReference', 'contractOwner'] as $field) {
				if ($this->isPresent(value: ($contract[$field] ?? null)) === false) {
					$missing[] = $field;
				}
			}

			if (empty($missing) === false) {
				$this->logger->info(
					'ContractLifecycleGuard: activation denied — mandatory fields missing (fail-closed)',
					['missing' => $missing]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractLifecycleGuard: activation check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canActivate()

	/**
	 * Returns true iff a non-empty terminationReason is present (REQ-CLM-002).
	 *
	 * The terminate transition is allowed only when terminationReason is set.
	 * Fail-closed: an empty or missing reason denies the transition so no state
	 * change is persisted (CWE-863).
	 *
	 * @param array<string,mixed> $contract The contract field map.
	 *
	 * @return bool True when terminationReason is present.
	 *
	 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
	 */
	public function requireTerminationReason(array $contract): bool {
		try {
			if ($this->isPresent(value: ($contract['terminationReason'] ?? null)) === false) {
				$this->logger->info(
					'ContractLifecycleGuard: termination denied — terminationReason missing (fail-closed)'
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractLifecycleGuard: termination check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireTerminationReason()

	/**
	 * Whether a field value is present and non-empty (after trimming strings).
	 *
	 * @param mixed $value The field value.
	 *
	 * @return bool True when the value is a non-empty, non-whitespace scalar.
	 */
	private function isPresent(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		return empty($value) === false;
	}//end isPresent()
}//end class
