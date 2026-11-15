<?php

/**
 * Settle Direct Debit Collection Action
 *
 * The declared action of the DirectDebitCollection `succeed` transition: it
 * registers the payment on the collection's invoice through the invoice
 * settlement service, the one path that marks an invoice paid. A collection
 * without an invoice, or a settlement that fails, throws, so the collection is
 * never succeeded while its invoice stays unpaid.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-collection-outcome-settles-or-keeps-the-invoice-req-sdd-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceSettlementServiceInterface;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use RuntimeException;

/**
 * Pays the invoice of a succeeded collection.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-collection-outcome-settles-or-keeps-the-invoice-req-sdd-013
 */
class SettleDirectDebitCollectionAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param InvoiceSettlementServiceInterface $settlement Registers the payment on the invoice.
	 */
	public function __construct(
		private readonly InvoiceSettlementServiceInterface $settlement,
	) {
	}//end __construct()

	/**
	 * Settle the collection's invoice and return the collection unchanged.
	 *
	 * @param array<string,mixed> $objectData   The collection after the transition.
	 * @param array<string,mixed> $previousData The collection before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The collection, unchanged.
	 *
	 * @throws RuntimeException When the collection names no invoice or the settlement failed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.4
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$invoiceId = trim((string)($objectData['invoiceId'] ?? ''));
		if ($invoiceId === '') {
			throw new RuntimeException('The collection names no invoice to settle.');
		}

		$outcomes = $this->settlement->settle(
			match: ['targetType' => 'ar-invoice', 'targetRefs' => [$invoiceId], 'isPartial' => false]
		);
		$outcome = ($outcomes[$invoiceId] ?? ['outcome' => InvoiceSettlementService::FAILED, 'reason' => 'no outcome']);
		if ($outcome['outcome'] === InvoiceSettlementService::FAILED) {
			throw new RuntimeException(sprintf('Invoice %s could not be settled: %s.', $invoiceId, (string)$outcome['reason']));
		}

		return $objectData;

	}//end execute()
}//end class
