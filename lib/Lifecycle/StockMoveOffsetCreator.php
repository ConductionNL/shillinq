<?php

/**
 * Stock Move Offset Creator
 *
 * ADR-031 exception-path service that materialises a balanced
 * offsetting StockMove when a posted move is cancelled per REQ-SM-003.
 * The original posted row is NOT patched; immutability is preserved.
 * The offset row is itself posted (locked) so:
 *
 *   - InventoryStock.quantity nets back to its pre-original value
 *     through the same reservation/commit pipeline as a normal post.
 *   - The materialise-gl-transaction action on the offset emits a GL
 *     transaction with the debit/credit polarity swapped, satisfying
 *     REQ-SM-006 reversal expectations without bespoke "reverse GL"
 *     code.
 *
 * The created offset has:
 *   - sourceLocationId  ← original.destinationLocationId
 *   - destinationLocationId ← original.sourceLocationId
 *   - quantity / unitCost / movementType / itemId / administrationId  ← copied
 *   - movementNumber    ← original.movementNumber + '-CANCEL'
 *   - movementReason    ← 'cancellation'
 *   - notes             ← 'Offset for <original.movementNumber>'
 *   - offsetOfMoveId    ← original.id
 *   - lifecycleState=posted, locked=true, postedAt=now
 *
 * Idempotency: the (administrationId, movementNumber) unique index on
 * StockMove prevents double-offset; this service also queries first.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emit a balanced offsetting StockMove when a posted move is cancelled.
 *
 * Referenced from inventory-stock-movement-ledger.json
 * StockMove.x-openregister-lifecycle.transitions.cancel.actions[2].
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
 */
class StockMoveOffsetCreator {
	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Emit the offsetting StockMove for a posted-cancel transition.
	 *
	 * Returns true on success (offset persisted or already present); false on
	 * error (fail-closed — the lifecycle action surfaces the failure).
	 *
	 * @param array<string,mixed> $original The posted StockMove being cancelled.
	 *
	 * @return bool True when the offset is materialised or already exists.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
	 */
	public function emitOffset(array $original): bool {
		try {
			$originalId = (string)($original['id'] ?? ($original['@self']['id'] ?? ''));
			$administrationId = (string)($original['administrationId'] ?? '');
			if ($originalId === '' || $administrationId === '') {
				$this->logger->info(
					'StockMoveOffsetCreator: emit denied — missing id/administrationId',
					['movementNumber' => ($original['movementNumber'] ?? null)]
				);
				return false;
			}

			if ($this->offsetAlreadyExists(originalId: $originalId, administrationId: $administrationId) === true) {
				// Idempotent: double-call returns success without duplicating.
				return true;
			}

			$movementNumber = (string)($original['movementNumber'] ?? '');
			$offsetNumber = ($movementNumber . '-CANCEL');

			$offset = [
				'movementNumber' => $offsetNumber,
				'itemId' => (string)($original['itemId'] ?? ''),
				'quantity' => ((float)($original['quantity'] ?? 0)),
				'unitCost' => ((float)($original['unitCost'] ?? 0)),
				'movementType' => (string)($original['movementType'] ?? 'transfer'),
				'sourceLocationId' => ($original['destinationLocationId'] ?? null),
				'destinationLocationId' => ($original['sourceLocationId'] ?? null),
				'referenceDocumentUri' => null,
				'movementReason' => 'cancellation',
				'notes' => ('Offset for ' . $movementNumber),
				'draftedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'postedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'cancelledAt' => null,
				'administrationId' => $administrationId,
				'locked' => true,
				'glTransactionId' => null,
				'offsetOfMoveId' => $originalId,
				'lifecycleState' => 'posted',
			];

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(object: $offset, register: $this->register(), schema: 'StockMove');

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockMoveOffsetCreator: emitOffset failed — denying (fail-closed)',
				[
					'movementNumber' => ($original['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end emitOffset()

	/**
	 * Query whether an offset for the supplied original already exists.
	 *
	 * @param string $originalId Original StockMove id.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return bool True when an offset row already exists.
	 */
	private function offsetAlreadyExists(string $originalId, string $administrationId): bool {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('StockMove')
				->findAll(
					[
						'filters' => [
							'offsetOfMoveId' => $originalId,
							'administrationId' => $administrationId,
						],
					]
				);

			return is_array($rows) === true && count($rows) > 0;
		} catch (\Throwable $e) {
			// On query failure assume no offset; the upstream save attempt will
			// surface a duplicate-key error through the unique index instead.
			$this->logger->debug(
				'StockMoveOffsetCreator: existence check failed; proceeding',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end offsetAlreadyExists()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
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
