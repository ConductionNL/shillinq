<?php

/**
 * Move Pending Approval Orders To Draft
 *
 * Before purchase order approval moved to OpenRegister's declared approval
 * chain, orders were parked in `lifecycleState` `pending_approval`, a field
 * and a state the PurchaseOrder schema never declared. This step puts them
 * back in `statusCode` draft, so their next submit opens OpenRegister's
 * approval steps (purchasing-approval-delegation design.md, Migration Plan).
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Returns `pending_approval` purchase orders to draft. Idempotent, fail-soft.
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 */
class MovePendingApprovalOrdersToDraft implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      For the register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function getName(): string {
		return 'Shillinq: move purchase orders waiting in pending_approval back to draft';
	}//end getName()

	/**
	 * Move every `pending_approval` order to draft and clear the old field.
	 *
	 * An order whose statusCode is already past draft keeps it; only the
	 * undeclared field is cleared, so the lifecycle never runs backwards.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function run(IOutput $output): void {
		try {
			$register = $this->settings->getRegisterSlug();
			$moved = 0;
			$rows = $this->objectService->setRegister($register)->setSchema('PurchaseOrder')->findAll(['limit' => 100000]);
			foreach ($rows as $row) {
				$order = $row;
				if (is_array($row) === false) {
					$order = $row->jsonSerialize();
				}

				$id = (string)($order['id'] ?? ($order['@self']['id'] ?? ''));
				if ($id === '' || ($order['lifecycleState'] ?? null) !== 'pending_approval') {
					continue;
				}

				$patch = ['lifecycleState' => null];
				$status = (string)($order['statusCode'] ?? '');
				if ($status === '' || $status === 'draft') {
					$patch['statusCode'] = 'draft';
				}

				$this->objectService->patchObject(objectId: $id, data: $patch, register: $register, schema: 'PurchaseOrder');
				$moved++;
			}

			$output->info(sprintf('Shillinq: %d purchase order(s) waiting in pending_approval moved back to draft.', $moved));
		} catch (Throwable $e) {
			$output->warning('Shillinq: moving pending_approval purchase orders to draft failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: moving pending_approval purchase orders to draft failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()
}//end class
