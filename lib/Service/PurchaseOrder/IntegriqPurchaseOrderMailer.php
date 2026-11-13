<?php

/**
 * Mails a purchase order to its supplier through integriq's email channel.
 *
 * Renders the order as a PDF and hands recipient, subject, body and the
 * attachment to integriq's NotificationDispatcher, the channel shillinq's
 * appointment confirmations already use. Without integriq nothing is sent
 * and nothing is claimed: the order page shows email as unavailable.
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
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Support\FleetAppId;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PDF and email dispatch of purchase orders through integriq.
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 */
class IntegriqPurchaseOrderMailer implements PurchaseOrderMailerInterface {

	/**
	 * integriq's mail dispatcher, below its namespace.
	 *
	 * @var string
	 */
	public const DISPATCHER = 'Service\\NotificationDispatcher';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface       $container     Resolves integriq's dispatcher.
	 * @param ObjectServiceInterface   $objectService Reads the supplier.
	 * @param IAppConfig               $appConfig     The register slug.
	 * @param PurchaseOrderPdfRenderer $pdfRenderer   Renders the order.
	 * @param LoggerInterface          $logger        Logger.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly PurchaseOrderPdfRenderer $pdfRenderer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether integriq's mail dispatcher is there.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function isAvailable(): bool {
		return $this->dispatcher() !== null;
	}//end isAvailable()

	/**
	 * Render the order and hand it to integriq's mail channel.
	 *
	 * @param string              $administrationId Administration scope.
	 * @param array<string,mixed> $purchaseOrder    The order.
	 *
	 * @return bool True when the channel accepted the message; false without
	 *              integriq, without a supplier address or on a refusal.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function sendPurchaseOrderEmail(string $administrationId, array $purchaseOrder): bool {
		$dispatcher = $this->dispatcher();
		if ($dispatcher === null) {
			return false;
		}

		$supplier = $this->supplier(administrationId: $administrationId, supplierId: (string)($purchaseOrder['supplierId'] ?? ''));
		$email = trim((string)($supplier['email'] ?? ''));
		if ($email === '') {
			$this->logger->info('IntegriqPurchaseOrderMailer: the supplier has no email address', ['poNumber' => (string)($purchaseOrder['poNumber'] ?? '')]);
			return false;
		}

		try {
			$result = $dispatcher->dispatch($this->payload(purchaseOrder: $purchaseOrder, supplier: $supplier, email: $email));
		} catch (Throwable $e) {
			$this->logger->warning('IntegriqPurchaseOrderMailer: the mail channel refused the order', ['exception' => $e->getMessage()]);
			return false;
		}

		return $result !== false;
	}//end sendPurchaseOrderEmail()

	/**
	 * The message integriq sends.
	 *
	 * @param array<string,mixed> $purchaseOrder The order.
	 * @param array<string,mixed> $supplier      The supplier.
	 * @param string              $email         The supplier's address.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function payload(array $purchaseOrder, array $supplier, string $email): array {
		$poNumber = (string)($purchaseOrder['poNumber'] ?? '');
		$name = (string)($supplier['name'] ?? ($supplier['legalName'] ?? ''));
		$salutation = $name;
		if ($salutation === '') {
			$salutation = 'supplier';
		}

		$filename = 'purchase-order';
		if ($poNumber !== '') {
			$filename = $poNumber;
		}

		return [
			'sourceApp' => AppIdentity::APP_ID,
			'to' => $email,
			'subject' => 'Purchase order ' . $poNumber,
			'body' => 'Dear ' . $salutation . ",\n\nPlease find purchase order " . $poNumber . " attached.\n",
			'attachments' => [
				[
					'filename' => $filename . '.pdf',
					'contentType' => 'application/pdf',
					'content' => $this->pdfRenderer->render(purchaseOrder: $purchaseOrder, supplierName: $name),
				],
			],
		];
	}//end payload()

	/**
	 * The supplier record, or [] when it cannot be read.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId       The supplier's id.
	 *
	 * @return array<string,mixed>
	 */
	private function supplier(string $administrationId, string $supplierId): array {
		if ($supplierId === '') {
			return [];
		}

		try {
			$rows = $this->objectService
				->setRegister($this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq'))
				->setSchema('Payee')
				->findAll(['filters' => ['id' => $supplierId, 'administrationId' => $administrationId]]);
		} catch (Throwable $e) {
			$this->logger->warning('IntegriqPurchaseOrderMailer: the supplier could not be read', ['exception' => $e->getMessage()]);
			return [];
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				return (array)$row->jsonSerialize();
			}
		}

		return [];
	}//end supplier()

	/**
	 * integriq's mail dispatcher, or null.
	 *
	 * @return object|null
	 */
	private function dispatcher(): ?object {
		$dispatcher = FleetAppId::getService($this->container, 'integriq', self::DISPATCHER);
		if ($dispatcher === null || method_exists($dispatcher, 'dispatch') === false) {
			return null;
		}

		return $dispatcher;
	}//end dispatcher()
}//end class
