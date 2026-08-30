<?php

/**
 * Log-only Purchase Order Mailer (default DI binding)
 *
 * Default binding for {@see PurchaseOrderMailerInterface}. Records the dispatch
 * attempt through Nextcloud's logger and returns successfully so the
 * orchestration layer can still mark the PO as `sent` with a
 * `peppolFallbackReason`. Production deployments swap this for an SMTP /
 * openconnector-mailer binding that actually renders + dispatches the PDF.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Log-only PO mailer (default DI binding).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 */
final class LogPurchaseOrderMailer implements PurchaseOrderMailerInterface {

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
	 */
	public function __construct(?LoggerInterface $logger = null) {
		$this->logger = ($logger ?? new NullLogger());

	}//end __construct()

	/**
	 * Log the dispatch of a purchase order email.
	 *
	 * @param string $administrationId The administration identifier.
	 * @param array $purchaseOrder The purchase order payload.
	 *
	 * @return void
	 *
	 * @inheritDoc
	 */
	public function sendPurchaseOrderEmail(string $administrationId, array $purchaseOrder): void {
		$this->logger->info(
			'shillinq.purchase_order.mailer.dispatch',
			[
				'administrationId' => $administrationId,
				'poNumber' => (string)($purchaseOrder['poNumber'] ?? ''),
				'supplierId' => (string)($purchaseOrder['supplierId'] ?? ''),
			]
		);

	}//end sendPurchaseOrderEmail()
}//end class
