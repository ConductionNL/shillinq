<?php

/**
 * Stock Move Immutability Guard
 *
 * ADR-031 exception-path guard enforcing two related rules on
 * `StockMove`:
 *
 *  1. `rejectLockedEdit()` — when a row is already `locked = true`
 *     (i.e. `lifecycleState = posted`), edits to the load-bearing
 *     fields (`quantity`, `unitCost`, `movementType`, `itemId`,
 *     source/destination locations) MUST be denied with HTTP 409 per
 *     REQ-SM-003. The lifecycle DSL can express the locked check but
 *     cannot — yet — emit a 409 with the operator-facing message
 *     "Move is locked; cancellation creates offset" from a single
 *     validation rule; this guard returns false so the engine surfaces
 *     the standard rejection.
 *
 *  2. `canCancel()` — predicate for the `cancel` transition guarding
 *     two preconditions that the declarative DSL cannot combine:
 *      - a `posted` move is cancellable only if no offset row already
 *        exists for it (avoid double-offset);
 *      - a `draft` move is always cancellable.
 *
 * The guard intentionally has no GL or InventoryStock writes; the
 * downstream `create-offset-move` lifecycle action handles the offset
 * materialisation through {@see StockMoveOffsetCreator}.
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
 * Lifecycle guard enforcing REQ-SM-003 immutability + cancellation preconditions.
 *
 * Referenced from inventory-stock-movement-ledger.json
 * StockMove.x-openregister-lifecycle.validations.onUpdate.lockedRejectsEdits and
 * .transitions.cancel.requires.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class StockMoveImmutabilityGuard {
	/**
	 * Construct the guard with DI dependencies.
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
	 * Returns false (i.e. denies the edit) when the supplied StockMove is
	 * locked AND any load-bearing field is being changed.
	 *
	 * The declarative `lockedRejectsEdits` validation already encodes the
	 * predicate; this guard is invoked when the engine needs to surface the
	 * 409 with the operator-facing message per REQ-SM-003.
	 *
	 * @param array<string,mixed> $current Current persisted StockMove.
	 * @param array<string,mixed> $proposed Proposed updated StockMove.
	 *
	 * @return bool True when the edit is permitted (move is not locked, or no load-bearing field changed); false otherwise.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
	 */
	public function rejectLockedEdit(array $current, array $proposed): bool {
		try {
			$locked = ((bool)($current['locked'] ?? false));
			if ($locked === false) {
				return true;
			}

			$loadBearing = [
				'quantity',
				'unitCost',
				'movementType',
				'itemId',
				'sourceLocationId',
				'destinationLocationId',
			];
			foreach ($loadBearing as $field) {
				$before = ($current[$field] ?? null);
				if (array_key_exists($field, $proposed) === true) {
					$after = $proposed[$field];
				} else {
					$after = $before;
				}

				if ($before !== $after) {
					$this->logger->info(
						'StockMoveImmutabilityGuard: edit denied — move is locked',
						[
							'movementNumber' => ($current['movementNumber'] ?? null),
							'field' => $field,
						]
					);
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockMoveImmutabilityGuard: rejectLockedEdit failed — denying edit (fail-closed)',
				[
					'movementNumber' => ($current['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end rejectLockedEdit()

	/**
	 * Predicate for the `cancel` transition per REQ-SM-003. A draft move is
	 * always cancellable. A posted move is cancellable only when no offset
	 * row already exists for it (`offsetOfMoveId = @move.id` not yet present)
	 * — preventing double-offset.
	 *
	 * Fail-closed: any exception denies the transition.
	 *
	 * @param array<string,mixed> $move The StockMove being cancelled.
	 *
	 * @return bool True when cancellation is permitted.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-10
	 */
	public function canCancel(array $move): bool {
		try {
			$state = (string)($move['lifecycleState'] ?? 'draft');
			if ($state === 'draft') {
				return true;
			}

			if ($state !== 'posted') {
				// Already cancelled — nothing to do.
				return false;
			}

			$moveId = (string)($move['id'] ?? '');
			$administrationId = (string)($move['administrationId'] ?? '');
			if ($moveId === '' || $administrationId === '') {
				return false;
			}

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->register();
			$existing = $objectService
				->setRegister($register)
				->setSchema('StockMove')
				->findAll(
					[
						'filters' => [
							'offsetOfMoveId' => $moveId,
							'administrationId' => $administrationId,
						],
					]
				);

			if (is_array($existing) === false) {
				$existing = [];
			}

			if (count($existing) > 0) {
				$this->logger->info(
					'StockMoveImmutabilityGuard: cancel denied — offset already exists',
					[
						'movementNumber' => ($move['movementNumber'] ?? null),
						'offsetCount' => count($existing),
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockMoveImmutabilityGuard: canCancel failed — denying transition (fail-closed)',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end canCancel()

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
