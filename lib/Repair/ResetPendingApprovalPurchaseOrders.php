<?php

/**
 * Reset Pending Approval Purchase Orders
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Purchase order approval moved onto OpenRegister's declared approval chain
 * (REQ-PAD-001). Orders written by the old in-object chain may carry
 * `lifecycleState` `pending_approval`, a field and a state the PurchaseOrder
 * schema never declared, sometimes without a `statusCode`. This step drops
 * the undeclared field and gives an order without a `statusCode` the draft
 * state, so the order gets OpenRegister's approval steps on its next submit;
 * a `statusCode` already set is kept, since only a transition may change it.
 * The in-object chain never recorded a decision, so no partial approval is
 * lost. An order without `pending_approval` is left alone, so a rerun saves
 * nothing.
 *
 * Runs post-migration. Best-effort: a failure warns and never blocks the
 * upgrade. Reads and writes are unscoped, because the repair context has no
 * user.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves purchase orders left in `pending_approval` to draft.
 */
class ResetPendingApprovalPurchaseOrders implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema this step repairs.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PurchaseOrder';

	/**
	 * The undeclared state the old chain wrote.
	 *
	 * @var string
	 */
	private const PENDING_APPROVAL = 'pending_approval';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocationInterface $settingsService The register location.
	 * @param LoggerInterface           $logger          The logger.
	 * @param ObjectServiceInterface    $objectService   OpenRegister's object service.
	 */
	public function __construct(
		private readonly RegisterLocationInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	#[Override]
	public function getName(): string {
		return 'Shillinq: move purchase orders waiting on the old approval chain back to draft';
	}//end getName()

	/**
	 * Move every `pending_approval` order to draft.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	#[Override]
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$rows         = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: self::SCHEMA,
				filters: ['lifecycleState' => self::PENDING_APPROVAL]
			);

			$reset = 0;
			foreach ($rows as $row) {
				$order = $this->rowPayload(row: $row);
				$uuid  = ObjectIdentifier::resolve(saved: $row);
				if ($uuid === '' || ($order['lifecycleState'] ?? null) !== self::PENDING_APPROVAL) {
					continue;
				}

				$this->objectService->saveObject(
					object: $this->asDraft(order: $order),
					options: new ObjectSaveOptions(
						target: new ObjectFindTarget(register: $registerSlug, schema: self::SCHEMA),
						uuid: $uuid
					),
					_rbac: false,
					_multitenancy: false
				);
				$reset++;
			}

			$output->info(sprintf('Shillinq: %d purchase order(s) waiting on the old approval chain moved to draft.', $reset));
		} catch (Throwable $e) {
			$output->warning('Shillinq: moving pending-approval purchase orders to draft failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: moving pending-approval purchase orders to draft failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * The order without its metadata and the undeclared field, in draft when it has no state.
	 *
	 * @param array<string,mixed> $order The stored order.
	 *
	 * @return array<string,mixed> The order to save.
	 */
	private function asDraft(array $order): array {
		$data = [];
		foreach ($order as $key => $value) {
			if ($key === 'id' || $key === 'lifecycleState' || str_starts_with((string) $key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		if (trim((string) ($data['statusCode'] ?? '')) === '') {
			$data['statusCode'] = 'draft';
		}

		return $data;
	}//end asDraft()
}//end class
