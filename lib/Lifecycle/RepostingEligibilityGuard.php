<?php

/**
 * SEPA Reposting-Eligibility Guard
 *
 * ADR-031 exception-path guard deciding whether a rejected/refunded
 * collection may be reposted (REQ-SDD-009). Reposting is permitted only for
 * bank-side problems (insufficient funds, closed account, technical errors)
 * and forbidden for debtor refusals (no mandate, mandate revoked, consumer
 * refund request), which the bookkeeper must pursue via dunning.
 *
 * ADR-031 exception reason: the debtor-refusal-vs-bank-problem heuristic maps
 * ISO 20022 reason codes to a reposting decision, which the declarative
 * lifecycle DSL cannot express. Replace with a declarative condition if/when
 * the engine supports reason-code set membership.
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
 * Reason-code heuristic for SEPA collection reposting eligibility (REQ-SDD-009).
 *
 * Pure over the collection's pain002ReasonCode so it unit-tests directly.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class RepostingEligibilityGuard {

	/**
	 * ISO 20022 reason codes that represent a debtor refusal — never repost.
	 *
	 * MD01 no mandate; MD02 missing mandate data; MD06 consumer refund
	 * request (PSD2 Art. 76); MD07 debtor deceased; RR01-RR04 regulatory;
	 * SL01 debtor-defined specific service refusal.
	 *
	 * @var array<int,string>
	 */
	private const DEBTOR_REFUSAL_CODES = [
		'MD01',
		'MD02',
		'MD06',
		'MD07',
		'RR01',
		'RR02',
		'RR03',
		'RR04',
		'SL01',
	];

	/**
	 * ISO 20022 reason codes that represent a transient bank problem — repostable.
	 *
	 * AM04 insufficient funds; AC01 invalid debit date; AC04 closed account;
	 * AC06 blocked account; AG01 transaction forbidden; MS02/MS03 no reason;
	 * SL02 specific service; TECH technical.
	 *
	 * @var array<int,string>
	 */
	private const BANK_PROBLEM_CODES = [
		'AM04',
		'AC01',
		'AC04',
		'AC06',
		'AG01',
		'MS02',
		'MS03',
		'SL02',
		'TECH',
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
	 * True iff the collection may be reposted (bank-problem reason code).
	 *
	 * REQ-SDD-009: repost only for bank-problem codes; refuse debtor refusals
	 * with sdd.mandate.debtor_refusal. Fails closed (denies repost) on an
	 * unknown or absent reason code, since reposting against an unclassified
	 * rejection risks a charged re-rejection.
	 *
	 * @param string $reasonCode The collection's pain002ReasonCode.
	 * @param array<string,mixed>|null $object The collection object (call-signature parity).
	 *
	 * @return bool True when reposting is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canRepost(string $reasonCode, ?array $object = null): bool {
		try {
			$code = strtoupper(trim($reasonCode));
			if ($code === '' && $object !== null) {
				$code = strtoupper(trim((string)($object['pain002ReasonCode'] ?? '')));
			}

			if ($code === '') {
				return false;
			}

			if (in_array($code, self::DEBTOR_REFUSAL_CODES, true) === true) {
				return false;
			}

			return in_array($code, self::BANK_PROBLEM_CODES, true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RepostingEligibilityGuard: repost check failed — denying (fail-closed)',
				['reasonCode' => $reasonCode, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canRepost()

	/**
	 * True iff the reason code is an explicit debtor refusal (for messaging).
	 *
	 * @param string $reasonCode The pain002ReasonCode to classify.
	 *
	 * @return bool True when the code is a debtor refusal.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function isDebtorRefusal(string $reasonCode): bool {
		return in_array(strtoupper(trim($reasonCode)), self::DEBTOR_REFUSAL_CODES, true);
	}//end isDebtorRefusal()
}//end class
