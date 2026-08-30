<?php

/**
 * Direct Debit Sequence-Type Guard
 *
 * ADR-031 exception-path guard deriving the SEPA sequence type
 * (FRST / RCUR / OOFF / FNAL) for a DirectDebitCollection from its
 * mandate's history, and refusing collections against a mandate that is
 * not eligible (one-off already used, cancelled, expired, suspended,
 * pending) (REQ-SDD-002, REQ-SDD-008). Operator-supplied sequenceType is
 * rejected (design D2).
 *
 * ADR-031 exception reason: sequence-type derivation queries the mandate
 * and the mandate's prior collection states, which the declarative
 * lifecycle DSL cannot yet express. When the engine gains cross-schema
 * aggregation, replace these references with declarative conditions and
 * delete this file.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Pure sequence-type derivation + collection-eligibility for SEPA Direct Debit.
 *
 * All methods are deterministic over the supplied mandate + prior-collection
 * arrays — no ObjectService dependency — so they unit-test directly and the
 * caller (collection creation flow) supplies the queried history.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class SequenceTypeGuard {

	/**
	 * Mandate states that block any new collection (REQ-SDD-008).
	 */
	private const BLOCKING_MANDATE_STATES = [
		'cancelled',
		'expired',
		'suspended',
		'pending',
	];

	/**
	 * Collection states that count as a successful prior collection for
	 * RCUR derivation (REQ-SDD-002): any state other than rejected/refunded.
	 */
	private const NON_FAILED_STATES = [
		'submitted',
		'accepted_by_bank',
		'presented',
		'succeeded',
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
	 * Derive the sequence type for a new collection against a mandate.
	 *
	 * REQ-SDD-002 rules:
	 *  - One-off mandate → OOFF.
	 *  - Recurring mandate with no prior non-failed collection → FRST.
	 *  - Recurring mandate with at least one prior non-failed collection → RCUR.
	 * FNAL is operator/cancellation-driven and never auto-derived here.
	 *
	 * @param array<string,mixed> $mandate The SepaMandate object.
	 * @param array<int,mixed> $priorCollections The mandate's existing collections (may contain non-array rows from the store).
	 *
	 * @return string One of FRST, RCUR, OOFF.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function deriveSequenceType(array $mandate, array $priorCollections = []): string {
		if (($mandate['type'] ?? '') === 'oneoff') {
			return 'OOFF';
		}

		foreach ($priorCollections as $collection) {
			if (is_array($collection) === false) {
				continue;
			}

			if (in_array(($collection['status'] ?? ''), self::NON_FAILED_STATES, true) === true) {
				return 'RCUR';
			}
		}

		return 'FRST';
	}//end deriveSequenceType()

	/**
	 * True iff a new collection may be scheduled against the mandate.
	 *
	 * REQ-SDD-008: refuse against cancelled/expired/suspended/pending mandates.
	 * REQ-SDD-002: refuse a second collection against a one-off mandate that
	 * already has any collection. Fail-closed on malformed input.
	 *
	 * @param array<string,mixed> $mandate The SepaMandate object.
	 * @param array<int,array<string,mixed>> $priorCollections The mandate's existing collections.
	 *
	 * @return bool True when a collection may be scheduled.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canScheduleCollection(array $mandate, array $priorCollections = []): bool {
		try {
			$status = (string)($mandate['status'] ?? '');
			if ($status === '' || in_array($status, self::BLOCKING_MANDATE_STATES, true) === true) {
				return false;
			}

			if (($mandate['type'] ?? '') === 'oneoff' && count($priorCollections) > 0) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SequenceTypeGuard: schedule check failed — denying collection (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canScheduleCollection()
}//end class
