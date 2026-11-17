<?php

/**
 * CounterSaleSettlement
 *
 * What a payment outcome does to the draft invoice of a counter sale
 * (receivables-provider-payouts D6, REQ-RPPO-006): a capture issues it, so it
 * can be settled as paid like any other invoice; a failed or voided payment
 * cancels it, so nothing is booked.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the invoice's own transitions: `issue` books it (and runs every guard
 * an issued invoice passes), `cancel` ends a draft that booked nothing. Only an
 * invoice flagged `counterSale` and still a draft is touched; every other
 * invoice is returned or left exactly as it was.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
 */
class CounterSaleSettlement {

	/**
	 * The ARInvoice transition that books an invoice.
	 */
	public const TRANSITION_ISSUE = 'issue';

	/**
	 * The ARInvoice transition that ends a counter sale without booking.
	 */
	public const TRANSITION_CANCEL = 'cancel';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService OpenRegister's object service.
	 * @param ObjectTransitionRunnerInterface $transitions   Runs the invoice's lifecycle transitions.
	 * @param LoggerInterface                 $logger        Logs a cancel that could not run.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an invoice is a counter sale that has not been issued yet.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public static function isDraftSale(array $invoice): bool {
		return ($invoice['counterSale'] ?? false) === true
			&& (string)($invoice['lifecycleState'] ?? '') === 'draft';
	}//end isDraftSale()

	/**
	 * Issue a counter sale whose payment was captured, so it settles as paid.
	 *
	 * Any other invoice is returned unchanged. A refused or failed `issue`
	 * throws: the caller routes the capture to captured_unapplied, so the money
	 * is surfaced rather than settled against an invoice that booked nothing.
	 *
	 * @param string               $registerSlug The register slug.
	 * @param array<string, mixed> $invoice      The invoice the capture is for.
	 *
	 * @return array<string, mixed> The invoice, issued when it was a draft sale.
	 *
	 * @throws \RuntimeException When the issue transition does not leave the invoice issued.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public function issueOnCapture(string $registerSlug, array $invoice): array {
		if (self::isDraftSale(invoice: $invoice) === false) {
			return $invoice;
		}

		$id = (string)($invoice['id'] ?? '');
		$this->transitions->runAsSystem(objectId: $id, action: self::TRANSITION_ISSUE);

		$issued = $this->reread(registerSlug: $registerSlug, id: $id);
		if ($issued === null || (string)($issued['lifecycleState'] ?? '') !== 'issued') {
			throw new \RuntimeException('The counter sale was not issued, so its payment cannot settle it.');
		}

		return $issued;
	}//end issueOnCapture()

	/**
	 * Cancel the counter sale of a payment request whose payment failed or was voided.
	 *
	 * Fail-soft: a payment outcome is recorded whatever happens here, and a
	 * draft left standing is still a draft that booked nothing.
	 *
	 * @param string               $registerSlug The register slug.
	 * @param array<string, mixed> $request      The PaymentRequest.
	 *
	 * @return bool True when a counter sale was cancelled.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
	 */
	public function cancelOnFailure(string $registerSlug, array $request): bool {
		$invoiceId = (string)($request['invoiceReference'] ?? '');
		if ($invoiceId === '') {
			return false;
		}

		try {
			$invoice = $this->reread(registerSlug: $registerSlug, id: $invoiceId);
			if ($invoice === null || self::isDraftSale(invoice: $invoice) === false) {
				return false;
			}

			$this->transitions->runAsSystem(objectId: (string)$invoice['id'], action: self::TRANSITION_CANCEL);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq: a counter sale whose payment failed could not be cancelled',
				['invoiceReference' => $invoiceId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end cancelOnFailure()

	/**
	 * Read an invoice by id as the system (the caller is a provider event).
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $id           The invoice id.
	 *
	 * @return array<string, mixed>|null The invoice, or null when there is none.
	 */
	private function reread(string $registerSlug, string $id): ?array {
		return ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($registerSlug)->setSchema('ARInvoice'),
			id: $id,
			scope: ['_rbac' => false]
		);
	}//end reread()
}//end class
