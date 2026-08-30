<?php

/**
 * SEPA Mandate Guard
 *
 * ADR-031 exception-path lifecycle guards for the SepaMandate register
 * (bookkeeping-sepa-direct-debit, T2 compliance). Three transition
 * preconditions are referenced from the SepaMandate schema's
 * x-openregister-lifecycle transitions because they require cross-field
 * temporal evaluation the declarative `requires:` clause cannot yet express:
 *
 *  - canActivate(): a mandate may only be activated once it carries a
 *                   signedAt date and the scheme/account-type pair is
 *                   internally consistent (REQ-SDD-001).
 *  - canCancel():   a mandate may only be cancelled while active and MUST
 *                   carry a cancellationReason (REQ-SDD-008).
 *  - canExpire():   the daily dormancy job may only expire an active
 *                   mandate whose lastUsedAt is more than 36 months in the
 *                   past (REQ-SDD-008).
 *
 * ADR-031 exception reason: scheme/account-type consistency and 36-month
 * date arithmetic are not expressible in the declarative lifecycle DSL.
 * When the engine gains those capabilities, replace these references with
 * declarative conditions and delete this file.
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

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guards for SepaMandate activate, cancel and expire.
 *
 * Referenced from the SepaMandate schema (register.d fragment)
 * x-openregister-lifecycle transitions as
 * OCA\Shillinq\Lifecycle\MandateGuard::canActivate / ::canCancel / ::canExpire.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class MandateGuard {

	/**
	 * SDD rulebook dormancy threshold in whole months (REQ-SDD-008).
	 */
	private const DORMANCY_MONTHS = 36;

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
	 * True iff the mandate may activate: scheme/account-type consistent and signed.
	 *
	 * REQ-SDD-001: CORE is reserved for consumer accounts and B2B for
	 * business accounts; a mandate may only become active once it has a
	 * signedAt date. Fail-closed on malformed input.
	 *
	 * @param string $mandateId The SepaMandate.id (call-signature parity; unused).
	 * @param array<string,mixed>|null $object The mandate object being transitioned.
	 *
	 * @return bool True when the mandate may activate.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canActivate(string $mandateId, ?array $object = null): bool {
		try {
			if ($object === null) {
				return false;
			}

			$signedAt = (string)($object['signedAt'] ?? '');
			if ($signedAt === '') {
				return false;
			}

			return self::schemeMatchesAccountType(
				scheme: (string)($object['scheme'] ?? ''),
				accountType: (string)($object['debtorAccountType'] ?? '')
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MandateGuard: activate check failed — denying transition (fail-closed)',
				['mandateId' => $mandateId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivate()

	/**
	 * True iff the mandate may be cancelled: a cancellationReason is present.
	 *
	 * REQ-SDD-008: cancelling a mandate MUST record a reason, after which no
	 * further collections may reference it. Fail-closed on malformed input.
	 *
	 * @param string $mandateId The SepaMandate.id (call-signature parity; unused).
	 * @param array<string,mixed>|null $object The mandate object being transitioned.
	 *
	 * @return bool True when the mandate may be cancelled.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canCancel(string $mandateId, ?array $object = null): bool {
		try {
			if ($object === null) {
				return false;
			}

			$reason = trim((string)($object['cancellationReason'] ?? ''));
			return $reason !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'MandateGuard: cancel check failed — denying transition (fail-closed)',
				['mandateId' => $mandateId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canCancel()

	/**
	 * True iff the mandate is dormant beyond the 36-month rulebook threshold.
	 *
	 * REQ-SDD-008: an active mandate whose last successful collection
	 * (lastUsedAt) — or, absent any collection, whose signedAt — is more
	 * than 36 months in the past MUST be expired by the daily job. Mandates
	 * never used and never signed cannot be evaluated and are left alone.
	 *
	 * @param string $mandateId The SepaMandate.id (call-signature parity; unused).
	 * @param array<string,mixed>|null $object The mandate object being transitioned.
	 *
	 * @return bool True when the mandate is dormant and may expire.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canExpire(string $mandateId, ?array $object = null): bool {
		try {
			if ($object === null) {
				return false;
			}

			$anchor = (string)($object['lastUsedAt'] ?? '');
			if ($anchor === '') {
				$anchor = (string)($object['signedAt'] ?? '');
			}

			if ($anchor === '') {
				return false;
			}

			return $this->isDormant(anchorDate: $anchor);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MandateGuard: expire check failed — denying transition (fail-closed)',
				['mandateId' => $mandateId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canExpire()

	/**
	 * True iff anchorDate is strictly more than DORMANCY_MONTHS in the past.
	 *
	 * @param string $anchorDate ISO-8601 date (Y-m-d) of last activity.
	 *
	 * @return bool True when the anchor is older than the dormancy threshold.
	 */
	private function isDormant(string $anchorDate): bool {
		$anchor = new DateTimeImmutable($anchorDate);
		$cutoff = (new DateTimeImmutable('today'))
			->sub(new DateInterval('P' . self::DORMANCY_MONTHS . 'M'));

		return $anchor <= $cutoff;
	}//end isDormant()

	/**
	 * True iff the scheme matches the debtor account type per REQ-SDD-001.
	 *
	 * CORE ↔ consumer, B2B ↔ business. Any other pairing is rejected as
	 * sdd.mandate.scheme.mismatch.
	 *
	 * @param string $scheme The mandate scheme (CORE | B2B).
	 * @param string $accountType The debtor account type (consumer | business).
	 *
	 * @return bool True when scheme and account type are consistent.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public static function schemeMatchesAccountType(string $scheme, string $accountType): bool {
		if ($scheme === 'CORE') {
			return $accountType === 'consumer';
		}

		if ($scheme === 'B2B') {
			return $accountType === 'business';
		}

		return false;
	}//end schemeMatchesAccountType()
}//end class
