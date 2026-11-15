<?php

/**
 * AP Transaction Settlement Guard
 *
 * Guards `APTransaction.matchFull` and `APTransaction.matchPartial`. Both
 * record that the bank paid the transaction, so they run only inside the
 * settlement of a confirmed bank reconciliation match; a direct transition
 * through the object API is refused.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use Psr\Log\LoggerInterface;

/**
 * Allows the payment transitions only while a confirmed bank match settles the transaction.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
final class APTransactionSettlementGuard implements LifecycleGuardInterface {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'An AP transaction is paid by confirming its bank reconciliation match, not directly.';

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
	 * Allow the transition only for a transaction the settlement is paying.
	 *
	 * @param array<string, mixed> $object The AP transaction as it would be saved.
	 * @param string               $action `matchFull` or `matchPartial`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if (InvoiceSettlementService::isSettling(invoiceId: trim((string)($object['id'] ?? ''))) === true) {
			return GuardResult::allow();
		}

		$this->logger->warning(
			'APTransactionSettlementGuard: payment transition outside a bank match settlement refused',
			['action' => $action, 'userId' => $userId, 'id' => (string)($object['id'] ?? '')]
		);
		return GuardResult::deny(self::MESSAGE);
	}//end check()
}//end class
