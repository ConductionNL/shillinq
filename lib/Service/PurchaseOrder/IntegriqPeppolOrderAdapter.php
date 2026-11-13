<?php

/**
 * Hands a purchase order to integriq's Peppol connector.
 *
 * The supplier's Peppol id is read from shillinq's own party records, then
 * checked against integriq's participant lookup. A UBL Order is stored in the
 * buyer's files and integriq is asked to transmit it with the
 * `nl.conduction.peppol.outbound.requested` CloudEvent; integriq reports the
 * outcome with `nl.conduction.peppol.delivery.status`.
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
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Support\FleetAppId;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Peppol transmission of purchase orders through integriq.
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
 */
class IntegriqPeppolOrderAdapter implements PeppolTransmissionAdapterInterface {

	/**
	 * The CloudEvent type integriq's outbound consumer takes.
	 *
	 * @var string
	 */
	public const EVENT_OUTBOUND_REQUESTED = 'nl.conduction.peppol.outbound.requested';

	/**
	 * The Peppol document type of a purchase order.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE = 'Order';

	/**
	 * The CloudEvent source shillinq emits under.
	 *
	 * @var string
	 */
	public const EVENT_SOURCE = 'shillinq';

	/**
	 * The folder below the buyer's files the UBL documents go into.
	 *
	 * @var string
	 */
	public const FOLDER = 'Shillinq/PurchaseOrders';

	/**
	 * Constructor.
	 *
	 * @param PeppolTransmissionPortInterface $partyDirectory Reads a party's Peppol id from shillinq's records.
	 * @param ContainerInterface              $container      Resolves integriq's services.
	 * @param IRootFolder                     $rootFolder     Stores the UBL Order in the buyer's files.
	 * @param IUserSession                    $userSession    The buyer.
	 * @param LoggerInterface                 $logger         Logger.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
	 */
	public function __construct(
		private readonly PeppolTransmissionPortInterface $partyDirectory,
		private readonly ContainerInterface $container,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The supplier's Peppol id when integriq knows it as a participant taking Orders.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $partyId          The supplier's id.
	 *
	 * @return string|null The participant id, or null when the supplier is not a participant.
	 *
	 * @throws RuntimeException When integriq is not installed.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
	 */
	public function lookupParticipant(string $administrationId, string $partyId): ?string {
		$peppolId = $this->partyDirectory->lookupParticipant(administrationId: $administrationId, partyId: $partyId);
		if ($peppolId === null || trim($peppolId) === '') {
			return null;
		}

		$lookup = $this->integriqService(relative: 'Service\\PeppolTransmissionService', method: 'lookupParticipant');
		$result = (array)$lookup->lookupParticipant(peppolId: $peppolId);
		if (($result['exists'] ?? false) !== true) {
			return null;
		}

		$documentTypes = (array)($result['supportedDocTypes'] ?? []);
		if ($documentTypes !== [] && $this->takesOrders(documentTypes: $documentTypes) === false) {
			return null;
		}

		return $peppolId;
	}//end lookupParticipant()

	/**
	 * Ask integriq to transmit a stored document.
	 *
	 * @param string $participantId  The recipient's Peppol id.
	 * @param string $documentType   The Peppol document type.
	 * @param string $payloadFileUri The stored document.
	 *
	 * @return string The reference the delivery status will name.
	 *
	 * @throws RuntimeException When integriq is not installed.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
	 */
	public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
		$this->emit(
			data: [
				'sourceApp' => self::EVENT_SOURCE,
				'objectType' => 'document',
				'objectUri' => $payloadFileUri,
				'recipientPeppolId' => $participantId,
				'documentType' => $documentType,
				'payloadFileUri' => $payloadFileUri,
			]
		);

		return $payloadFileUri;
	}//end submit()

	/**
	 * Store the UBL Order and ask integriq to transmit it.
	 *
	 * @param string              $participantId The supplier's Peppol id.
	 * @param string              $ublOrderXml   The UBL 2.1 Order.
	 * @param array<string,mixed> $purchaseOrder The order being sent.
	 *
	 * @return string The order's object URI, which the delivery status names.
	 *
	 * @throws RuntimeException When the order has no id, nobody is signed in or integriq is not installed.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
	 */
	public function submitOrder(string $participantId, string $ublOrderXml, array $purchaseOrder = []): string {
		$orderId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));
		if ($orderId === '') {
			throw new RuntimeException('A purchase order without an id cannot be sent by Peppol');
		}

		// Refuse before anything is stored when integriq cannot take the request.
		$this->integriqService(relative: 'Service\\EventService', method: 'emitCloudEvent');
		$fileUri = $this->store(purchaseOrder: $purchaseOrder, orderId: $orderId, ublOrderXml: $ublOrderXml);
		$objectUri = 'openregister://shillinq/PurchaseOrder/' . $orderId;
		$this->emit(
			data: [
				'sourceApp' => self::EVENT_SOURCE,
				'objectType' => 'purchase-order',
				'objectUri' => $objectUri,
				'recipientPeppolId' => $participantId,
				'documentType' => self::DOCUMENT_TYPE,
				'payloadFileUri' => $fileUri,
			]
		);

		return $objectUri;
	}//end submitOrder()

	/**
	 * Emit an outbound request through integriq.
	 *
	 * @param array<string,mixed> $data The CloudEvent data.
	 *
	 * @return void
	 */
	private function emit(array $data): void {
		$emitter = $this->integriqService(relative: 'Service\\EventService', method: 'emitCloudEvent');
		$emitter->emitCloudEvent(
			type: self::EVENT_OUTBOUND_REQUESTED,
			source: self::EVENT_SOURCE,
			subject: (string)$data['objectUri'],
			data: $data,
			userId: $this->userSession->getUser()?->getUID()
		);
	}//end emit()

	/**
	 * Write the UBL Order into the buyer's files.
	 *
	 * @param array<string,mixed> $purchaseOrder The order.
	 * @param string              $orderId       The order's id.
	 * @param string              $ublOrderXml   The document.
	 *
	 * @return string `nextcloud-file:<id>`, the form integriq reads.
	 *
	 * @throws RuntimeException When nobody is signed in.
	 */
	private function store(array $purchaseOrder, string $orderId, string $ublOrderXml): string {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			throw new RuntimeException('A purchase order is sent by Peppol only by a signed-in user');
		}

		$folder = $this->rootFolder->getUserFolder($userId);
		$path = self::FOLDER . '/' . $this->safeName(name: (string)($purchaseOrder['administrationId'] ?? 'default'));
		foreach (explode('/', $path) as $segment) {
			$folder = $this->child(folder: $folder, name: $segment);
		}

		$name = $this->safeName(name: (string)($purchaseOrder['poNumber'] ?? $orderId)) . '.xml';
		$file = null;
		if ($folder->nodeExists($name) === true) {
			$existing = $folder->get($name);
			if ($existing instanceof File) {
				$existing->putContent($ublOrderXml);
				$file = $existing;
			}
		}

		if ($file === null) {
			$file = $folder->newFile($name, $ublOrderXml);
		}

		$this->logger->info('IntegriqPeppolOrderAdapter: UBL Order stored', ['orderId' => $orderId, 'bytes' => strlen($ublOrderXml)]);

		return 'nextcloud-file:' . (string)$file->getId();
	}//end store()

	/**
	 * A sub folder, created when missing.
	 *
	 * @param Folder $folder The parent.
	 * @param string $name   The child's name.
	 *
	 * @return Folder
	 */
	private function child(Folder $folder, string $name): Folder {
		if ($folder->nodeExists($name) === true) {
			$node = $folder->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
		}

		return $folder->newFolder($name);
	}//end child()

	/**
	 * A name safe for one path segment.
	 *
	 * @param string $name The raw name.
	 *
	 * @return string
	 */
	private function safeName(string $name): string {
		$safe = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.');
		if ($safe === '') {
			return 'order';
		}

		return $safe;
	}//end safeName()

	/**
	 * Whether a participant's document types include Orders.
	 *
	 * @param array<int,mixed> $documentTypes The types integriq lists.
	 *
	 * @return bool
	 */
	private function takesOrders(array $documentTypes): bool {
		foreach ($documentTypes as $type) {
			$type = (string)$type;
			if ($type === self::DOCUMENT_TYPE || str_contains($type, 'Order-2::Order') === true
				|| str_contains($type, ':Order:') === true
			) {
				return true;
			}
		}

		return false;
	}//end takesOrders()

	/**
	 * One of integriq's services, or a refusal naming that Peppol is not configured.
	 *
	 * @param string $relative The class below integriq's namespace.
	 * @param string $method   The method shillinq calls on it.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When integriq is not installed or lacks the method.
	 */
	private function integriqService(string $relative, string $method): object {
		$service = FleetAppId::getService($this->container, 'integriq', $relative);
		if ($service === null || method_exists($service, $method) === false) {
			throw new RuntimeException('Peppol sending is not configured');
		}

		return $service;
	}//end integriqService()
}//end class
