<?php

/**
 * Requisition Conversion Service
 *
 * ADR-031 imperative orchestrator for the Requisition `convertToPO`
 * transition (REQ-REQ-005). Converting an approved Requisition into a
 * PurchaseOrder is a genuine cross-schema materialisation — it creates a new
 * PurchaseOrder record (with its own approval chain, notifications, and
 * generated po_number) and writes that new record's id back onto the
 * Requisition — which is exactly the category of behaviour ADR-031 reserves
 * for an imperative service rather than a declarative lifecycle transition
 * (the same exception already documented for AansluitingService::compute()
 * and for PurchaseOrderService::blockSendUntilApproved() itself, which
 * likewise mutates lifecycleState outside the generic OR transition engine).
 *
 * This service does NOT reimplement purchase-order creation: it builds a
 * payload from the Requisition + its RequisitionLine items and delegates to
 * the existing, unmodified PurchaseOrderService::createPurchaseOrder() (ADR-022
 * — consume, don't reimplement).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\RequisitionConversionGuard;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Materialises an approved Requisition into a PurchaseOrder (REQ-REQ-005).
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 */
class RequisitionConversionService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param RequisitionConversionGuard $guard Fail-closed status precondition.
	 * @param PurchaseOrderService $purchaseOrderService Reused, unmodified PO creation.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly RequisitionConversionGuard $guard,
		private readonly PurchaseOrderService $purchaseOrderService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Convert an approved requisition into a PurchaseOrder (REQ-REQ-005).
	 *
	 * Server-authoritative:
	 *  - refuses when the requisition is missing, not approved, or has no
	 *    preferredSupplierId (a PurchaseOrder always needs a supplier);
	 *  - maps the requisition + its RequisitionLine items into
	 *    PurchaseOrderService::createPurchaseOrder()'s payload shape
	 *    (costCenter <- programma, glAccount <- glAccountSuggestion), and
	 *    threads `requisitionId` through so the new PurchaseOrder carries a
	 *    traceability link back to this requisition;
	 *  - on success, persists the requisition with statusCode='converted',
	 *    convertedPurchaseOrderId=<new PO id>, convertedAt=now.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array{requisition:array<string,mixed>,purchaseOrder:array<string,mixed>}
	 *
	 * @throws \RuntimeException When the requisition is missing, not approved,
	 *                           has no preferred supplier, or PO creation fails.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function convertToPurchaseOrder(string $administrationId, string $requisitionId): array {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Requisition not found');
		}

		$requisition = $this->findOne(
			schema: 'Requisition',
			filters: [
				'id' => $requisitionId,
				'administrationId' => $administrationId,
			]
		);
		if ($requisition === null) {
			throw new RuntimeException('Requisition not found');
		}

		// Fail-closed defence in depth — mirrors the declarative guard wired
		// onto the schema's convertToPO transition (RequisitionConversionGuard).
		if ($this->guard->canConvert(requisitionId: $requisitionId, object: $requisition) === false) {
			throw new RuntimeException('Requisition must be approved before it can be converted to a purchase order');
		}

		$supplierId = trim((string)($requisition['preferredSupplierId'] ?? ''));
		if ($supplierId === '') {
			throw new RuntimeException(
				'Requisition has no preferred supplier; assign one before converting to a purchase order'
			);
		}

		$lines = $this->findAll(
			schema: 'RequisitionLine',
			filters: [
				'requisitionId' => $requisitionId,
				'administrationId' => $administrationId,
			]
		);
		if ($lines === []) {
			throw new RuntimeException('Requisition has no lines to convert');
		}

		$poLines = [];
		foreach ($lines as $line) {
			$poLines[] = [
				'productCode' => (string)($line['description'] ?? 'Requisition item'),
				'quantity' => (float)($line['quantity'] ?? 0),
				// RequisitionLine.unitPrice is stored in integer cents (ADR-022);
				// PurchaseOrderService::createPurchaseOrder() expects a euro float.
				'unitPrice' => ((float)($line['unitPrice'] ?? 0)) / 100.0,
				'vatRate' => 0.0,
				'glAccount' => (string)($line['glAccountSuggestion'] ?? ''),
			];
		}

		$purchaseOrder = $this->purchaseOrderService->createPurchaseOrder(
			administrationId: $administrationId,
			payload: [
				'supplierId' => $supplierId,
				'costCenter' => (string)($requisition['programme'] ?? ''),
				'requisitionId' => $requisitionId,
				'lines' => $poLines,
				'notes' => 'Converted from requisition ' . ($requisition['requisitionNumber'] ?? $requisitionId),
			]
		);

		$poId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));

		$requisition['statusCode'] = 'converted';
		$requisition['convertedPurchaseOrderId'] = $poId;
		$requisition['convertedAt'] = date('c');

		$updatedRequisition = $this->saveObject(schema: 'Requisition', object: $requisition);

		return [
			'requisition' => $updatedRequisition,
			'purchaseOrder' => $purchaseOrder,
		];

	}//end convertToPurchaseOrder()

	/**
	 * Persist an object via OpenRegister's real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object The object to persist.
	 *
	 * @return array<string,mixed> The persisted record.
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->error(
				'RequisitionConversionService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all matching records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RequisitionConversionService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
