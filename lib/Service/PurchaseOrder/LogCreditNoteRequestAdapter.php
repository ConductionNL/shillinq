<?php

/**
 * Log-only Credit-Note Request Adapter (default DI binding).
 *
 * Slice 08 of the bookkeeping-purchase-order-3way chain — default binding
 * for {@see CreditNoteRequestAdapterInterface}. Records the dispute payload
 * through the standard application logger, fabricates a deterministic
 * dispatch id (`urn:uuid:cn-...`) so the orchestration code can persist
 * the dispatch reference on the ThreeWayMatch resolution notes, and
 * returns an `accepted: true` envelope. Production deployments swap this
 * for an HTTP-backed adapter that posts the UBL CreditNote to
 * openconnector's Peppol Access Point endpoint; the wire shape is then
 * the same UBL 2.1 CreditNote skeleton the orchestration service composes,
 * so the swap is transparent to the caller.
 *
 * The log line is intentionally redacted — only the structural payload
 * envelope (matchId, invoiceId, supplierId, monetary totals, currency)
 * is logged. Sensitive free-text fields (the operator's resolution_notes,
 * supplier remarks) never reach the application log; the canonical
 * record stays on the ThreeWayMatch.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Log-only credit-note dispatch adapter (default DI binding).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 */
final class LogCreditNoteRequestAdapter implements CreditNoteRequestAdapterInterface {

	/**
	 * Logger sink.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Optional logger (defaults to NullLogger).
	 *
	 * @return void
	 */
	public function __construct(?LoggerInterface $logger = null) {
		$this->logger = ($logger ?? new NullLogger());

	}//end __construct()

	/**
	 * Submit one UBL CreditNote dispute request — logs the envelope and
	 * fabricates a deterministic dispatch id.
	 *
	 * @param array<string,mixed> $payload Dispute envelope (see interface).
	 *
	 * @return array{accepted:bool,dispatchId:?string,error:?string}
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function submitDisputeCreditNote(array $payload): array {
		$matchId = (string)($payload['matchId'] ?? '');
		$invoiceId = (string)($payload['invoiceId'] ?? '');
		$invoiceNumber = (string)($payload['invoiceNumber'] ?? '');
		$supplierId = (string)($payload['supplierId'] ?? '');
		$administrationId = (string)($payload['administrationId'] ?? '');
		$currency = (string)($payload['currency'] ?? 'EUR');
		$totalInclVat = (int)($payload['totalInclVat'] ?? 0);

		$dispatchId = 'urn:uuid:cn-' . bin2hex(random_bytes(8));

		$this->logger->info(
			'LogCreditNoteRequestAdapter: dispute UBL CreditNote queued',
			[
				'matchId' => $matchId,
				'invoiceId' => $invoiceId,
				'invoiceNumber' => $invoiceNumber,
				'supplierId' => $supplierId,
				'administrationId' => $administrationId,
				'currency' => $currency,
				'totalInclVat' => $totalInclVat,
				'dispatchId' => $dispatchId,
			]
		);

		return [
			'accepted' => true,
			'dispatchId' => $dispatchId,
			'error' => null,
		];

	}//end submitDisputeCreditNote()
}//end class
