<?php

/**
 * Lease Contract Guard
 *
 * ADR-031 exception-path lifecycle guard for the LeaseContract register
 * (bookkeeping-ifrs-16-lease, T4-specialized). The draft→active transition is
 * gated by guardActivation() because the precondition — "classification is one
 * of the four IFRS 16 enum values AND the core economic fields needed to build
 * the amortization schedule are present" — combines an enum-membership check
 * with a cross-field completeness check that OpenRegister's declarative
 * `requires:` clause cannot yet express (REQ-LC-004).
 *
 * Fail-closed: any malformed input or missing field returns false, so a lease
 * can never activate (and therefore can never trigger RoU / liability
 * recognition) on incomplete data (ADR-005, CWE-863).
 *
 * ADR-031 exception reason: enum + multi-field completeness is not yet
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
 * @spec openspec/specs/bookkeeping-lease-contracts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for the LeaseContract draft→active transition.
 *
 * Referenced from the LeaseContract schema (register.d fragment)
 * x-openregister-lifecycle transitions.{draft→active}.guard as
 * OCA\Shillinq\Lifecycle\LeaseContractGuard::guardActivation.
 *
 * @spec openspec/specs/bookkeeping-lease-contracts/spec.md
 */
class LeaseContractGuard {
	/**
	 * Valid IFRS 16 classifications (REQ-LC-002, REQ-LC-003).
	 *
	 * @var array<int,string>
	 */
	private const VALID_CLASSIFICATIONS = [
		'IFRS16-capitalised',
		'short-term-exempt',
		'low-value-exempt',
		'operating-pre-IFRS16',
	];

	/**
	 * Economic fields required before a lease may activate (REQ-LC-004).
	 *
	 * @var array<int,string>
	 */
	private const REQUIRED_FIELDS = [
		'leaseNumber',
		'commencementDate',
		'endDate',
		'nonCancellableTermMonths',
		'paymentFrequency',
		'paymentTiming',
		'basePaymentAmount',
		'paymentCurrency',
		'ibrPercent',
	];

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the lease may transition draft→active (REQ-LC-004).
	 *
	 * The classification must be one of the four IFRS 16 enum values and every
	 * economic field needed to build the amortization schedule must be present
	 * and non-empty. Fail-closed on any malformed input.
	 *
	 * @param string $leaseContractId The LeaseContract.id (present for
	 *                                lifecycle-engine call signature parity).
	 * @param array<string,mixed>|null $object The LeaseContract object being transitioned.
	 *
	 * @return bool True when the lease may activate.
	 *
	 * @spec openspec/specs/bookkeeping-lease-contracts/spec.md
	 */
	public function guardActivation(string $leaseContractId, ?array $object = null): bool {
		try {
			return $this->passesActivationChecks(object: $object);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'LeaseContractGuard: activation guard failed closed',
				['leaseContractId' => $leaseContractId, 'exception' => $e->getMessage()]
			);

			return false;
		}//end try

	}//end guardActivation()

	/**
	 * The pure activation predicate (REQ-LC-004), free of fail-closed plumbing.
	 *
	 * Validates the classification enum, the presence of every schedule-building
	 * field, and — for capitalised leases only — the positive economics. Exempt
	 * leases may legitimately carry a zero IBR (REQ-LC-003, REQ-LE-002).
	 *
	 * @param array<string,mixed>|null $object The LeaseContract object being transitioned.
	 *
	 * @return bool True when the lease satisfies every activation precondition.
	 *
	 * @spec openspec/specs/bookkeeping-lease-contracts/spec.md
	 */
	private function passesActivationChecks(?array $object): bool {
		if (is_array($object) === false) {
			return false;
		}

		$classification = (string)($object['classification'] ?? '');
		if (in_array($classification, self::VALID_CLASSIFICATIONS, true) === false) {
			return false;
		}

		if ($this->hasRequiredFields(object: $object) === false) {
			return false;
		}

		if ($classification === 'IFRS16-capitalised'
			&& $this->hasValidCapitalisedEconomics(object: $object) === false
		) {
			return false;
		}

		return true;
	}//end passesActivationChecks()

	/**
	 * Every field required to build the amortization schedule must be present and
	 * non-empty (REQ-LC-004). Fails closed on the first missing/blank field.
	 *
	 * @param array<string,mixed> $object The LeaseContract object.
	 *
	 * @return bool True when all required fields carry a value.
	 */
	private function hasRequiredFields(array $object): bool {
		foreach (self::REQUIRED_FIELDS as $field) {
			if (isset($object[$field]) === false) {
				return false;
			}

			// The isset() above already guarantees the value is non-null.
			if ($object[$field] === '') {
				return false;
			}
		}

		return true;
	}//end hasRequiredFields()

	/**
	 * A capitalised lease must carry a positive base payment and a positive
	 * non-cancellable term (REQ-LC-003).
	 *
	 * @param array<string,mixed> $object The LeaseContract object.
	 *
	 * @return bool True when the capitalised economics are valid.
	 */
	private function hasValidCapitalisedEconomics(array $object): bool {
		if ((float)($object['basePaymentAmount'] ?? 0) <= 0.0) {
			return false;
		}

		return (int)($object['nonCancellableTermMonths'] ?? 0) > 0;
	}//end hasValidCapitalisedEconomics()
}//end class
