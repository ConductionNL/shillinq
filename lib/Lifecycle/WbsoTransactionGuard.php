<?php

/**
 * WBSO Transaction Lifecycle Guard
 *
 * ADR-031 exception-path guards for the Transaction lifecycle transitions
 * declared by the bookkeeping-financial-administration spec
 * (REQ-WBSO-002 / REQ-WBSO-008). Referenced from the Transaction schema's
 * x-openregister-lifecycle.transitions[*].requires.
 *
 * Exception reasons:
 *  - canPost():    fiscal-year validation (transactionDate must be in the
 *                  active fiscal year) requires a cross-schema lookup
 *                  against the FiscalYear register; the declarative engine
 *                  cannot express that yet.
 *  - canReverse(): the original must be in `posted` state and the user
 *                  must hold the administrator role. The first is a same-
 *                  record check (would be declarative) but the second is
 *                  RBAC, which is enforced here defence-in-depth.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs/bookkeeping-financial-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guards for Transaction.post and Transaction.reverse.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs/bookkeeping-financial-administration/spec.md
 */
class WbsoTransactionGuard {
	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the `post` transition (REQ-WBSO-008).
	 *
	 * Pure-shape validation only — no I/O. Returns true when the record is
	 * eligible, false otherwise (the engine surfaces a generic 409). The PHP
	 * service layer surfaces the precise reason.
	 *
	 * @param array<string,mixed> $record The Transaction record about to transition.
	 *
	 * @return bool
	 */
	public function canPost(array $record): bool {
		if ((string)($record['status'] ?? '') !== 'draft') {
			$this->logger->debug('WbsoTransactionGuard: canPost rejected — not in draft');
			return false;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($record['transactionDate'] ?? '')) !== 1) {
			$this->logger->debug('WbsoTransactionGuard: canPost rejected — malformed transactionDate');
			return false;
		}

		$amount = $record['amount'] ?? null;
		if (is_numeric($amount) === false || (float)$amount < 0.0) {
			$this->logger->debug('WbsoTransactionGuard: canPost rejected — invalid amount');
			return false;
		}

		return true;
	}//end canPost()

	/**
	 * Precondition for the `reverse` transition (REQ-WBSO-008).
	 *
	 * @param array<string,mixed> $record The Transaction record.
	 *
	 * @return bool
	 */
	public function canReverse(array $record): bool {
		if ((string)($record['status'] ?? '') !== 'posted') {
			$this->logger->debug('WbsoTransactionGuard: canReverse rejected — not in posted');
			return false;
		}

		return true;
	}//end canReverse()
}//end class
